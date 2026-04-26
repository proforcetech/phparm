<?php

namespace App\Services\Assets;

use App\Models\CapitalScoringModel;
use App\Models\SiteAsset;
use DateTimeImmutable;

/**
 * Computes lifecycle risk + replacement recommendations for installed assets
 * (Phase 2.5 of docs/expansion-plan.md).
 *
 * Risk is a 0-100 score combining:
 *   * inverted condition_score                       (weight: 0.5 default)
 *   * age-vs-expected-life ratio                     (weight: 0.3 default)
 *   * distance to replace_by_date (or warranty_end)  (weight: 0.2 default)
 *
 * Categories (default thresholds):
 *   * ok      risk < 40
 *   * watch   40-59
 *   * action  60-79
 *   * urgent  >= 80, or replace_by_date has already passed
 *
 * Phase 9.2 of docs/expansion-plan.md: weights AND thresholds are now tunable
 * via CapitalScoringModel. Pass a model into scoreAsset/reportForSite to drive
 * the math from a persisted profile (typically resolved per-division). When
 * no model is supplied, the historical hardcoded defaults are used so callers
 * that haven't migrated continue to behave identically.
 *
 * Dollars are aggregated separately so a site rollup can show "replacement
 * capex due in the next 12 months."
 */
class AssetLifecycleService
{
    public function __construct(private readonly SiteAssetRepository $assets)
    {
    }

    /**
     * Produce a lifecycle report for a site. Returns per-asset rows sorted by
     * risk desc plus aggregate counters.
     *
     * @return array{
     *     data: array<int, array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         ok: int,
     *         watch: int,
     *         action: int,
     *         urgent: int,
     *         replacement_estimate_cents: int,
     *         replacement_due_12mo_cents: int
     *     }
     * }
     */
    public function reportForSite(
        int $siteId,
        ?DateTimeImmutable $now = null,
        ?CapitalScoringModel $model = null,
    ): array {
        $now = $now ?? new DateTimeImmutable();
        $result = $this->assets->search(['site_id' => $siteId, 'limit' => 500]);
        $rows = [];
        $summary = [
            'total' => 0,
            'ok' => 0,
            'watch' => 0,
            'action' => 0,
            'urgent' => 0,
            'replacement_estimate_cents' => 0,
            'replacement_due_12mo_cents' => 0,
        ];
        $twelveMonths = $now->modify('+12 months');

        foreach ($result['data'] as $asset) {
            $scored = $this->scoreAsset($asset, $now, $model);
            $rows[] = $scored;
            $summary['total']++;
            $summary[$scored['category']]++;
            if ($asset->replacement_estimate_cents !== null) {
                $summary['replacement_estimate_cents'] += $asset->replacement_estimate_cents;
                if ($scored['replace_by_date'] !== null
                    && $scored['replace_by_date'] <= $twelveMonths->format('Y-m-d')
                ) {
                    $summary['replacement_due_12mo_cents'] += $asset->replacement_estimate_cents;
                }
            }
        }

        usort($rows, static fn(array $a, array $b) => $b['risk'] <=> $a['risk']);
        return ['data' => $rows, 'summary' => $summary];
    }

    /**
     * @return array<string, mixed>
     */
    public function scoreAsset(
        SiteAsset $asset,
        ?DateTimeImmutable $now = null,
        ?CapitalScoringModel $model = null,
    ): array {
        $now = $now ?? new DateTimeImmutable();
        $model = $model ?? CapitalScoringModel::fallback();
        $weights = $model->normalizedWeights();

        // ── condition risk (inverse of score, or 50 if unknown) ──────────────
        $conditionRisk = $asset->condition_score === null
            ? 50.0
            : (100.0 - (float) $asset->condition_score);

        // ── age risk (age / expected_life) ───────────────────────────────────
        $ageRisk = 0.0;
        $ageYears = null;
        if ($asset->install_date !== null) {
            $installed = DateTimeImmutable::createFromFormat('Y-m-d', substr($asset->install_date, 0, 10));
            if ($installed instanceof DateTimeImmutable) {
                $diff = $installed->diff($now);
                $ageYears = $diff->y + ($diff->m / 12.0);
            }
        }
        if ($ageYears !== null && $asset->expected_life_years !== null && $asset->expected_life_years > 0) {
            $ageRisk = min(100.0, ($ageYears / $asset->expected_life_years) * 100.0);
        } elseif ($ageYears !== null && $ageYears >= 15) {
            // No explicit expected_life — treat 15+ years as moderately aged.
            $ageRisk = min(100.0, ($ageYears / 15.0) * 60.0);
        }

        // ── replace-by risk (how close are we to replace_by / warranty_end) ──
        $replaceBy = $asset->replace_by_date ?? $asset->warranty_end;
        $replaceByRisk = 0.0;
        $daysToReplaceBy = null;
        if ($replaceBy !== null) {
            $target = DateTimeImmutable::createFromFormat('Y-m-d', substr($replaceBy, 0, 10));
            if ($target instanceof DateTimeImmutable) {
                $daysToReplaceBy = (int) $now->diff($target)->format('%r%a');
                if ($daysToReplaceBy <= 0) {
                    $replaceByRisk = 100.0;
                } elseif ($daysToReplaceBy < 365) {
                    // 0→100 as we approach the target within the final year.
                    $replaceByRisk = 100.0 - (($daysToReplaceBy / 365.0) * 100.0);
                }
            }
        }

        $risk = ($conditionRisk * $weights['condition'])
            + ($ageRisk * $weights['age'])
            + ($replaceByRisk * $weights['replace_by']);
        $risk = round(min(100.0, max(0.0, $risk)), 1);

        $category = 'ok';
        if ($risk >= $model->urgent_threshold
            || ($daysToReplaceBy !== null && $daysToReplaceBy <= 0)
        ) {
            $category = 'urgent';
        } elseif ($risk >= $model->action_threshold) {
            $category = 'action';
        } elseif ($risk >= $model->watch_threshold) {
            $category = 'watch';
        }

        return [
            'asset_id' => $asset->id,
            'name' => $asset->name,
            'code' => $asset->code,
            'status' => $asset->status,
            'condition_score' => $asset->condition_score,
            'expected_life_years' => $asset->expected_life_years,
            'age_years' => $ageYears !== null ? round($ageYears, 2) : null,
            'days_to_replace_by' => $daysToReplaceBy,
            'replace_by_date' => $replaceBy,
            'last_inspected_at' => $asset->last_inspected_at,
            'replacement_estimate_cents' => $asset->replacement_estimate_cents,
            'risk' => $risk,
            'category' => $category,
            'scoring_model_id' => $model->id > 0 ? $model->id : null,
        ];
    }

    /**
     * Phase 9.2 — forward-project a stored replacement estimate into a future
     * year using the model's annual_inflation_rate. Returns null when the
     * input estimate is null or non-positive (i.e. nothing to inflate).
     *
     * Years-out is clamped to a sane range so a planner accidentally entering
     * year 9999 doesn't inflate to absurd numbers.
     */
    public function projectedReplacementCostCents(
        ?int $baseCents,
        int $yearsOut,
        ?CapitalScoringModel $model = null,
    ): ?int {
        if ($baseCents === null || $baseCents <= 0) {
            return null;
        }
        $rate = ($model ?? CapitalScoringModel::fallback())->annual_inflation_rate;
        $years = max(0, min(100, $yearsOut));
        $factor = (1.0 + $rate) ** $years;
        return (int) round($baseCents * $factor);
    }
}
