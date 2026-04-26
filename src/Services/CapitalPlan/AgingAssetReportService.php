<?php

namespace App\Services\CapitalPlan;

use App\Database\Connection;
use App\Models\CapitalScoringModel;
use App\Models\User;
use App\Services\Assets\AssetLifecycleService;
use App\Services\Assets\SiteAssetRepository;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Phase 9.1 — Aging Asset Report.
 *
 * Cross-site portfolio rollup of installed-asset aging + replacement capex.
 * The per-site lifecycle view (Phase 2.5) answers "what's about to break at
 * this one address?" — this service answers the planner's question: "across
 * the whole company / division / org, what does the next five years of
 * capital replacement look like, and where are the hot spots?"
 *
 * Inputs are scoped one of three ways:
 *   - by company  (every site under one customer-company)
 *   - by division (operational dimension — e.g. all HVAC sites)
 *   - portfolio   (entire org; admin/manager only at the controller layer)
 *
 * Per-asset risk reuses AssetLifecycleService::scoreAsset so the categorical
 * thresholds (ok/watch/action/urgent) stay consistent with the per-site view.
 * What's new here is the **capex horizon**: replacement_estimate_cents bucketed
 * by the asset's replace_by_date (or warranty_end fallback) into overdue,
 * 0–12mo, 12–24mo, 24–60mo, and beyond/unscheduled. A multi-year budget
 * planner (Phase 9.3) consumes those buckets directly.
 */
class AgingAssetReportService
{
    /** Hard cap on top-risk asset rows surfaced in the response payload. */
    private const TOP_RISK_LIMIT = 25;

    public function __construct(
        private readonly Connection $connection,
        private readonly SiteAssetRepository $assets,
        private readonly AssetLifecycleService $lifecycle,
        private readonly AccessGate $gate,
        private readonly ?CapitalScoringModelRepository $scoringModels = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function reportForCompany(User $actor, int $companyId, ?DateTimeImmutable $now = null): array
    {
        $this->gate->assert($actor, 'assets.view');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('companyId must be positive');
        }
        $label = $this->lookupCompanyName($companyId) ?? "Company #{$companyId}";
        $rows = $this->assets->listForReport(['company_id' => $companyId]);
        // Companies span divisions, so use the global model.
        $model = $this->resolveScoringModel(null);
        return $this->buildReport(
            ['type' => 'company', 'id' => $companyId, 'label' => $label],
            $rows,
            $now,
            $model,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function reportForDivision(User $actor, int $divisionId, ?DateTimeImmutable $now = null): array
    {
        $this->gate->assert($actor, 'assets.view');
        if ($divisionId <= 0) {
            throw new InvalidArgumentException('divisionId must be positive');
        }
        $label = $this->lookupDivisionName($divisionId) ?? "Division #{$divisionId}";
        $rows = $this->assets->listForReport(['division_id' => $divisionId]);
        // Division-scoped report uses the division's own scoring model when set.
        $model = $this->resolveScoringModel($divisionId);
        return $this->buildReport(
            ['type' => 'division', 'id' => $divisionId, 'label' => $label],
            $rows,
            $now,
            $model,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function reportForPortfolio(User $actor, ?DateTimeImmutable $now = null): array
    {
        // Portfolio-wide capex is a planning-grade view, not just an asset
        // viewer's. capital_plans.view is the Phase 9 planner gate.
        $this->gate->assert($actor, 'capital_plans.view');
        $rows = $this->assets->listForReport([]);
        $model = $this->resolveScoringModel(null);
        return $this->buildReport(
            ['type' => 'portfolio', 'id' => null, 'label' => 'Portfolio'],
            $rows,
            $now,
            $model,
        );
    }

    private function resolveScoringModel(?int $divisionId): CapitalScoringModel
    {
        return $this->scoringModels === null
            ? CapitalScoringModel::fallback()
            : $this->scoringModels->findActiveForDivision($divisionId);
    }

    /**
     * @param array{type: string, id: ?int, label: string} $scope
     * @param array<int, array{asset: \App\Models\SiteAsset, site_id: int, site_name: string, company_id: int, division_id: ?int}> $rows
     * @return array<string, mixed>
     */
    private function buildReport(
        array $scope,
        array $rows,
        ?DateTimeImmutable $now,
        ?CapitalScoringModel $model = null,
    ): array {
        $now = $now ?? new DateTimeImmutable();
        $model = $model ?? CapitalScoringModel::fallback();
        $currentYear = (int) $now->format('Y');
        $horizon12 = $now->modify('+12 months')->format('Y-m-d');
        $horizon24 = $now->modify('+24 months')->format('Y-m-d');
        $horizon60 = $now->modify('+60 months')->format('Y-m-d');
        $today = $now->format('Y-m-d');

        $summary = [
            'total' => 0,
            'ok' => 0,
            'watch' => 0,
            'action' => 0,
            'urgent' => 0,
            'replacement_estimate_cents' => 0,
            'capex_horizon' => [
                'overdue_cents' => 0,
                'next_12mo_cents' => 0,
                'next_24mo_cents' => 0,
                'next_60mo_cents' => 0,
                'beyond_or_unscheduled_cents' => 0,
            ],
            // Phase 9.2 — same buckets, but inflated to the year of spend.
            'capex_horizon_projected' => [
                'overdue_cents' => 0,
                'next_12mo_cents' => 0,
                'next_24mo_cents' => 0,
                'next_60mo_cents' => 0,
                'beyond_or_unscheduled_cents' => 0,
            ],
            'sites_count' => 0,
        ];

        /** @var array<int, array<string, mixed>> $bySite */
        $bySite = [];
        $scoredRows = [];

        foreach ($rows as $row) {
            $asset = $row['asset'];
            $scored = $this->lifecycle->scoreAsset($asset, $now, $model);
            $scored['site_id'] = $row['site_id'];
            $scored['site_name'] = $row['site_name'];
            // Phase 9.2 — forward-project the stored estimate to the asset's
            // own replace year so the planner can see "what this will cost in
            // today's dollars vs. when we actually have to spend it."
            $targetYear = $this->yearFromDate($scored['replace_by_date']) ?? $currentYear;
            $yearsOut = max(0, $targetYear - $currentYear);
            $scored['projected_replacement_cents'] = $this->lifecycle->projectedReplacementCostCents(
                $asset->replacement_estimate_cents,
                $yearsOut,
                $model,
            );
            $scoredRows[] = $scored;

            $summary['total']++;
            $summary[$scored['category']]++;

            $estimate = $asset->replacement_estimate_cents ?? 0;
            if ($estimate > 0) {
                $summary['replacement_estimate_cents'] += $estimate;
                $bucket = $this->bucketFor($scored['replace_by_date'], $today, $horizon12, $horizon24, $horizon60);
                $summary['capex_horizon'][$bucket] += $estimate;
                if ($scored['projected_replacement_cents'] !== null) {
                    $summary['capex_horizon_projected'][$bucket] += $scored['projected_replacement_cents'];
                }
            }

            $siteId = $row['site_id'];
            if (!isset($bySite[$siteId])) {
                $bySite[$siteId] = [
                    'site_id' => $siteId,
                    'site_name' => $row['site_name'],
                    'total' => 0,
                    'ok' => 0,
                    'watch' => 0,
                    'action' => 0,
                    'urgent' => 0,
                    'replacement_estimate_cents' => 0,
                    'max_risk' => 0.0,
                ];
            }
            $bySite[$siteId]['total']++;
            $bySite[$siteId][$scored['category']]++;
            $bySite[$siteId]['replacement_estimate_cents'] += $estimate;
            if ($scored['risk'] > $bySite[$siteId]['max_risk']) {
                $bySite[$siteId]['max_risk'] = $scored['risk'];
            }
        }

        $summary['sites_count'] = count($bySite);

        // Sites sorted urgent-first, then max risk, so the planner sees the
        // worst sites at the top of the list.
        $bySiteList = array_values($bySite);
        usort($bySiteList, static function (array $a, array $b): int {
            return $b['urgent'] <=> $a['urgent']
                ?: $b['action'] <=> $a['action']
                ?: $b['max_risk'] <=> $a['max_risk'];
        });

        // Top-N highest-risk individual assets across the scope. Sorting is
        // descending by risk, then descending by replacement estimate so a
        // ceiling unit ranks above a wall-mounted thermostat at the same risk.
        usort($scoredRows, static function (array $a, array $b): int {
            return $b['risk'] <=> $a['risk']
                ?: ($b['replacement_estimate_cents'] ?? 0) <=> ($a['replacement_estimate_cents'] ?? 0);
        });
        $topRisks = array_slice($scoredRows, 0, self::TOP_RISK_LIMIT);

        return [
            'scope' => $scope,
            'generated_at' => $now->format(DATE_ATOM),
            'scoring_model' => [
                'id' => $model->id > 0 ? $model->id : null,
                'name' => $model->name,
                'division_id' => $model->division_id,
                'weights' => $model->normalizedWeights(),
                'thresholds' => [
                    'watch' => $model->watch_threshold,
                    'action' => $model->action_threshold,
                    'urgent' => $model->urgent_threshold,
                ],
                'annual_inflation_rate' => $model->annual_inflation_rate,
            ],
            'summary' => $summary,
            'by_site' => $bySiteList,
            'top_risks' => $topRisks,
        ];
    }

    private function yearFromDate(?string $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }
        $year = (int) substr($date, 0, 4);
        return $year > 0 ? $year : null;
    }

    private function bucketFor(?string $replaceByDate, string $today, string $h12, string $h24, string $h60): string
    {
        if ($replaceByDate === null || $replaceByDate === '') {
            return 'beyond_or_unscheduled_cents';
        }
        // Normalize to date-only for lexicographic comparison (Y-m-d sorts
        // correctly as text).
        $d = substr($replaceByDate, 0, 10);
        if ($d <= $today) {
            return 'overdue_cents';
        }
        if ($d <= $h12) {
            return 'next_12mo_cents';
        }
        if ($d <= $h24) {
            return 'next_24mo_cents';
        }
        if ($d <= $h60) {
            return 'next_60mo_cents';
        }
        return 'beyond_or_unscheduled_cents';
    }

    private function lookupCompanyName(int $companyId): ?string
    {
        try {
            $stmt = $this->connection->pdo()->prepare('SELECT name FROM companies WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $companyId]);
            $name = $stmt->fetchColumn();
            return $name === false ? null : (string) $name;
        } catch (\Throwable) {
            return null;
        }
    }

    private function lookupDivisionName(int $divisionId): ?string
    {
        try {
            $stmt = $this->connection->pdo()->prepare('SELECT name FROM divisions WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $divisionId]);
            $name = $stmt->fetchColumn();
            return $name === false ? null : (string) $name;
        } catch (\Throwable) {
            return null;
        }
    }
}
