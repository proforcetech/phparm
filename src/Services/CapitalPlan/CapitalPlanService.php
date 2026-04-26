<?php

namespace App\Services\CapitalPlan;

use App\Models\CapitalPlan;
use App\Models\CapitalPlanScenario;
use App\Models\CapitalPlanScenarioOverride;
use App\Models\CapitalScoringModel;
use App\Models\SiteAsset;
use App\Models\User;
use App\Services\Assets\AssetLifecycleService;
use App\Services\Assets\SiteAssetRepository;
use App\Support\Auth\AccessGate;
use App\Support\Pdf\CapitalRecommendationPdfGenerator;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 9.3 — orchestrates capital_plan + scenario + override persistence and
 * runs the year-by-year capex projection that the planner UI charts.
 *
 * Persistence is delegated to CapitalPlanRepository. This service owns:
 *   1) plan lifecycle (createPlan auto-mints a Baseline scenario; deletePlan
 *      cascades through the FK chain)
 *   2) scenario / override write paths with validation and gating
 *   3) computeScenario(): the meat of the planner. Resolves the asset pool
 *      from the plan's scope, applies the scenario's global_options + each
 *      override, and emits year-buckets [base_year .. base_year + horizon-1]
 *      plus 'overdue' and 'beyond' tails. Both raw (today's dollars) and
 *      projected (inflated to spend year) totals are surfaced.
 *   4) compareScenarios(): convenience wrapper that returns several scenarios
 *      computed against the same plan in one payload.
 */
class CapitalPlanService
{
    /** Hard cap on assets returned in a single scope rollup. Matches
     *  SiteAssetRepository::listForReport's own ceiling so the planner can't
     *  silently truncate at a lower number than the aging report. */
    private const SCOPE_ASSET_LIMIT = 10000;

    public function __construct(
        private readonly CapitalPlanRepository $plans,
        private readonly SiteAssetRepository $assets,
        private readonly AssetLifecycleService $lifecycle,
        private readonly CapitalScoringModelRepository $scoringModels,
        private readonly AccessGate $gate,
        private readonly ?CapitalRecommendationPdfGenerator $pdfGenerator = null,
    ) {
    }

    // ────────────────────────────────────────────────── plan lifecycle ────

    /**
     * @param array<string, mixed> $data
     */
    public function createPlan(User $actor, array $data): CapitalPlan
    {
        $this->gate->assert($actor, 'capital_plans.manage');
        $this->validateScope($data['scope_type'] ?? '', $data['scope_id'] ?? null);
        $data['created_by_user_id'] = $actor->id ?? null;
        $plan = $this->plans->createPlan($data);
        // Auto-mint Baseline scenario so the planner can immediately compute
        // without an extra POST. Future writes (defer/pin/exclude) attach to
        // a new scenario, not Baseline.
        $this->plans->createScenario($plan->id, [
            'name' => 'Baseline',
            'is_baseline' => true,
            'global_options' => null,
            'notes' => 'Auto-generated baseline — reflects raw aging report.',
        ]);
        return $plan;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updatePlan(User $actor, int $planId, array $data): CapitalPlan
    {
        $this->gate->assert($actor, 'capital_plans.manage');
        if ($this->plans->findPlan($planId) === null) {
            throw new InvalidArgumentException("Capital plan {$planId} not found");
        }
        if (array_key_exists('scope_type', $data) || array_key_exists('scope_id', $data)) {
            $existing = $this->plans->findPlan($planId);
            $scopeType = $data['scope_type'] ?? $existing->scope_type;
            $scopeId = array_key_exists('scope_id', $data) ? $data['scope_id'] : $existing->scope_id;
            $this->validateScope($scopeType, $scopeId);
        }
        return $this->plans->updatePlan($planId, $data);
    }

    public function deletePlan(User $actor, int $planId): void
    {
        $this->gate->assert($actor, 'capital_plans.manage');
        if ($this->plans->findPlan($planId) === null) {
            throw new InvalidArgumentException("Capital plan {$planId} not found");
        }
        $this->plans->deletePlan($planId);
    }

    /**
     * @return array<int, CapitalPlan>
     */
    public function listPlans(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'capital_plans.view');
        return $this->plans->listPlans($filters);
    }

    public function findPlan(User $actor, int $planId): CapitalPlan
    {
        $this->gate->assert($actor, 'capital_plans.view');
        $plan = $this->plans->findPlan($planId);
        if ($plan === null) {
            throw new InvalidArgumentException("Capital plan {$planId} not found");
        }
        return $plan;
    }

    // ───────────────────────────────────────────────────────── scenarios ──

    /**
     * @return array<int, CapitalPlanScenario>
     */
    public function listScenarios(User $actor, int $planId): array
    {
        $this->gate->assert($actor, 'capital_plans.view');
        if ($this->plans->findPlan($planId) === null) {
            throw new InvalidArgumentException("Capital plan {$planId} not found");
        }
        return $this->plans->listScenariosForPlan($planId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addScenario(User $actor, int $planId, array $data): CapitalPlanScenario
    {
        $this->gate->assert($actor, 'capital_plans.manage');
        if ($this->plans->findPlan($planId) === null) {
            throw new InvalidArgumentException("Capital plan {$planId} not found");
        }
        if (empty($data['name'])) {
            throw new InvalidArgumentException('Scenario name is required');
        }
        // is_baseline is system-controlled — only the auto-mint at plan create
        // is allowed to set it. Strip it from user payloads.
        $data['is_baseline'] = false;
        return $this->plans->createScenario($planId, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateScenario(User $actor, int $scenarioId, array $data): CapitalPlanScenario
    {
        $this->gate->assert($actor, 'capital_plans.manage');
        $scenario = $this->plans->findScenario($scenarioId);
        if ($scenario === null) {
            throw new InvalidArgumentException("Scenario {$scenarioId} not found");
        }
        return $this->plans->updateScenario($scenarioId, $data);
    }

    public function deleteScenario(User $actor, int $scenarioId): void
    {
        $this->gate->assert($actor, 'capital_plans.manage');
        $scenario = $this->plans->findScenario($scenarioId);
        if ($scenario === null) {
            throw new InvalidArgumentException("Scenario {$scenarioId} not found");
        }
        if ($scenario->is_baseline) {
            throw new InvalidArgumentException('Cannot delete the Baseline scenario');
        }
        $this->plans->deleteScenario($scenarioId);
    }

    // ───────────────────────────────────────────────────────── overrides ──

    /**
     * @return array<int, CapitalPlanScenarioOverride>
     */
    public function listOverrides(User $actor, int $scenarioId): array
    {
        $this->gate->assert($actor, 'capital_plans.view');
        if ($this->plans->findScenario($scenarioId) === null) {
            throw new InvalidArgumentException("Scenario {$scenarioId} not found");
        }
        return $this->plans->listOverridesForScenario($scenarioId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setOverride(
        User $actor,
        int $scenarioId,
        int $assetId,
        array $data
    ): CapitalPlanScenarioOverride {
        $this->gate->assert($actor, 'capital_plans.manage');
        $scenario = $this->plans->findScenario($scenarioId);
        if ($scenario === null) {
            throw new InvalidArgumentException("Scenario {$scenarioId} not found");
        }
        if ($scenario->is_baseline) {
            throw new InvalidArgumentException('Cannot attach overrides to Baseline');
        }
        if ($assetId <= 0) {
            throw new InvalidArgumentException('site_asset_id is required');
        }
        return $this->plans->upsertOverride($scenarioId, $assetId, $data);
    }

    public function removeOverride(User $actor, int $overrideId): void
    {
        $this->gate->assert($actor, 'capital_plans.manage');
        if ($this->plans->findOverride($overrideId) === null) {
            throw new InvalidArgumentException("Override {$overrideId} not found");
        }
        $this->plans->deleteOverride($overrideId);
    }

    // ─────────────────────────────────────────────────── compute scenario ─

    /**
     * Run the year-by-year capex projection for a single scenario. The result
     * is the planner's primary chart payload.
     *
     * Year buckets:
     *   - 'overdue'                           replace year < base_year
     *   - years[base_year + N], N in 0..H-1   exact bucket
     *   - 'beyond'                            replace year >= base_year + H
     *
     * Per-bucket totals are reported both as `raw_cents` (today's dollars,
     * matching what the user sees on the asset row) and `projected_cents`
     * (inflated to the spend year using the scoring model's annual_inflation
     * rate, optionally overridden via global_options.inflation_rate_override).
     *
     * @return array<string, mixed>
     */
    public function computeScenario(
        User $actor,
        int $scenarioId,
        ?DateTimeImmutable $now = null
    ): array {
        $this->gate->assert($actor, 'capital_plans.view');
        $scenario = $this->plans->findScenario($scenarioId);
        if ($scenario === null) {
            throw new InvalidArgumentException("Scenario {$scenarioId} not found");
        }
        $plan = $this->plans->findPlan($scenario->capital_plan_id);
        if ($plan === null) {
            throw new InvalidArgumentException("Plan {$scenario->capital_plan_id} not found");
        }

        $now = $now ?? new DateTimeImmutable();
        $model = $this->resolveModel($plan);
        $effectiveModel = $this->applyInflationOverride($model, $scenario);

        $rows = $this->loadScopeAssets($plan);
        $overrides = $this->indexOverrides(
            $this->plans->listOverridesForScenario($scenarioId)
        );
        $globalOptions = $scenario->global_options ?? [];

        $baseYear = $plan->base_year;
        $horizonYears = $plan->horizon_years;
        $endYear = $baseYear + $horizonYears - 1;

        // Initialize buckets. years[] keys are absolute years for the planner UI.
        $years = [];
        for ($y = $baseYear; $y <= $endYear; $y++) {
            $years[$y] = $this->emptyBucket();
        }
        $overdueBucket = $this->emptyBucket();
        $beyondBucket = $this->emptyBucket();

        $assetLines = [];
        $totals = $this->emptyBucket();
        $excludedCount = 0;
        $deferredCount = 0;
        $pinnedCount = 0;
        $accelerateUrgentTo = isset($globalOptions['accelerate_urgent_to_year'])
            ? max($baseYear, (int) $globalOptions['accelerate_urgent_to_year'])
            : null;
        $deferAllMonths = isset($globalOptions['defer_all_months'])
            ? (int) $globalOptions['defer_all_months']
            : 0;

        foreach ($rows as $row) {
            $asset = $row['asset'];
            $scored = $this->lifecycle->scoreAsset($asset, $now, $model);
            $override = $overrides[$asset->id] ?? null;

            if ($override !== null && $override->excluded) {
                $excludedCount++;
                continue;
            }

            $estimateCents = $this->resolveEstimate($asset, $override);
            // No estimate → still surface row-level data for the planner, but
            // exclude from $ totals (consistent with aging report behavior).
            $hasEstimate = $estimateCents !== null && $estimateCents > 0;

            // ── 1) baseline year from replace_by_date (or current if blank/past) ──
            $baseReplaceYear = $this->yearFromScored($scored, $baseYear);

            // ── 2) apply pin → defer (override) → defer_all_months ──
            $replaceYear = $baseReplaceYear;
            $applied = [];
            if ($override !== null && $override->pin_to_year !== null) {
                $replaceYear = $override->pin_to_year;
                $pinnedCount++;
                $applied[] = 'pin';
            } elseif ($override !== null && $override->defer_months !== null && $override->defer_months !== 0) {
                $replaceYear = $this->shiftYearByMonths($scored['replace_by_date'], $baseYear, $override->defer_months);
                $deferredCount++;
                $applied[] = 'defer';
            } elseif ($deferAllMonths !== 0) {
                $replaceYear = $this->shiftYearByMonths($scored['replace_by_date'], $baseYear, $deferAllMonths);
                $applied[] = 'defer_all';
            }

            // ── 3) accelerate-urgent-to-year only pulls forward; never delays ──
            if ($accelerateUrgentTo !== null
                && $scored['category'] === 'urgent'
                && $replaceYear > $accelerateUrgentTo
            ) {
                $replaceYear = $accelerateUrgentTo;
                $applied[] = 'accelerate_urgent';
            }

            // ── 4) project to spend year (inflation) ──
            $yearsOut = max(0, $replaceYear - $baseYear);
            $projectedCents = $hasEstimate
                ? $this->lifecycle->projectedReplacementCostCents($estimateCents, $yearsOut, $effectiveModel)
                : null;

            // ── 5) bucket ──
            if ($replaceYear < $baseYear) {
                $bucket =& $overdueBucket;
                $bucketKey = 'overdue';
            } elseif ($replaceYear > $endYear) {
                $bucket =& $beyondBucket;
                $bucketKey = 'beyond';
            } else {
                $bucket =& $years[$replaceYear];
                $bucketKey = (string) $replaceYear;
            }
            $bucket['asset_count']++;
            if ($hasEstimate) {
                $bucket['raw_cents'] += $estimateCents;
                $bucket['projected_cents'] += $projectedCents ?? $estimateCents;
                $totals['raw_cents'] += $estimateCents;
                $totals['projected_cents'] += $projectedCents ?? $estimateCents;
            }
            $totals['asset_count']++;
            unset($bucket);

            $assetLines[] = [
                'asset_id' => $asset->id,
                'asset_name' => $asset->name,
                'site_id' => $row['site_id'],
                'site_name' => $row['site_name'],
                'category' => $scored['category'],
                'risk' => $scored['risk'],
                'baseline_replace_year' => $baseReplaceYear,
                'replace_year' => $replaceYear,
                'bucket' => $bucketKey,
                'raw_cents' => $hasEstimate ? $estimateCents : null,
                'projected_cents' => $projectedCents,
                'overrides_applied' => $applied,
                'has_override' => $override !== null,
                'has_estimate' => $hasEstimate,
            ];
        }

        // Surface buckets as a list so the JSON keeps year ordering for the UI.
        $yearList = [];
        foreach ($years as $y => $b) {
            $yearList[] = ['year' => $y] + $b;
        }

        return [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'scope_type' => $plan->scope_type,
                'scope_id' => $plan->scope_id,
                'base_year' => $baseYear,
                'horizon_years' => $horizonYears,
                'status' => $plan->status,
            ],
            'scenario' => [
                'id' => $scenario->id,
                'name' => $scenario->name,
                'is_baseline' => $scenario->is_baseline,
                'global_options' => $globalOptions,
            ],
            'scoring_model' => [
                'id' => $effectiveModel->id > 0 ? $effectiveModel->id : null,
                'name' => $effectiveModel->name,
                'annual_inflation_rate' => $effectiveModel->annual_inflation_rate,
            ],
            'years' => $yearList,
            'overdue' => $overdueBucket,
            'beyond' => $beyondBucket,
            'totals' => $totals,
            'counts' => [
                'assets_in_scope' => count($rows),
                'excluded' => $excludedCount,
                'pinned' => $pinnedCount,
                'deferred' => $deferredCount,
                'with_overrides' => count($overrides),
            ],
            'assets' => $assetLines,
            'generated_at' => $now->format(DATE_ATOM),
        ];
    }

    /**
     * Compute every scenario for a plan (or a caller-supplied subset) in one
     * pass. The planner UI uses this for the side-by-side compare view.
     *
     * @param array<int> $scenarioIds Empty = compute all scenarios for the plan.
     * @return array<string, mixed>
     */
    public function compareScenarios(
        User $actor,
        int $planId,
        array $scenarioIds = [],
        ?DateTimeImmutable $now = null
    ): array {
        $this->gate->assert($actor, 'capital_plans.view');
        $plan = $this->plans->findPlan($planId);
        if ($plan === null) {
            throw new InvalidArgumentException("Capital plan {$planId} not found");
        }
        $allScenarios = $this->plans->listScenariosForPlan($planId);
        if ($scenarioIds !== []) {
            $wanted = array_flip(array_map(static fn($id) => (int) $id, $scenarioIds));
            $allScenarios = array_values(array_filter(
                $allScenarios,
                static fn(CapitalPlanScenario $s) => isset($wanted[$s->id])
            ));
        }

        $computed = [];
        foreach ($allScenarios as $scenario) {
            $computed[] = $this->computeScenario($actor, $scenario->id, $now);
        }
        return [
            'plan_id' => $planId,
            'scenarios' => $computed,
            'generated_at' => ($now ?? new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    // ─────────────────────────────────────────── recommendation PDF (9.4) ──

    /**
     * Phase 9.4 — render the customer-facing capital replacement recommendation
     * PDF for a scenario. Returns the binary PDF body so the HTTP layer can
     * stream it back with the appropriate Content-Type/Content-Disposition.
     *
     * Reuses computeScenario() so the on-paper recommendation is
     * byte-for-byte consistent with whatever the planner UI shows for the
     * same scenario at the same moment in time.
     *
     * @param array<string, mixed> $shopSettings shop_name, shop_address, shop_phone (optional)
     */
    public function renderRecommendationPdf(
        User $actor,
        int $scenarioId,
        array $shopSettings = [],
        ?string $scopeLabel = null,
        ?DateTimeImmutable $now = null
    ): string {
        if ($this->pdfGenerator === null) {
            throw new \LogicException('CapitalPlanService not configured with a PDF generator');
        }
        // computeScenario already gates capital_plans.view + raises if the
        // scenario doesn't exist, so no extra guards needed here.
        $payload = $this->computeScenario($actor, $scenarioId, $now);
        return $this->pdfGenerator->generate($payload, $shopSettings, $scopeLabel);
    }

    // ──────────────────────────────────────────────────────── internals ────

    private function validateScope(string $scopeType, mixed $scopeId): void
    {
        $valid = [CapitalPlan::SCOPE_COMPANY, CapitalPlan::SCOPE_DIVISION, CapitalPlan::SCOPE_PORTFOLIO];
        if (!in_array($scopeType, $valid, true)) {
            throw new InvalidArgumentException(
                'scope_type must be one of: ' . implode(', ', $valid)
            );
        }
        if ($scopeType === CapitalPlan::SCOPE_PORTFOLIO) {
            return;
        }
        if ($scopeId === null || $scopeId === '' || (int) $scopeId <= 0) {
            throw new InvalidArgumentException("scope_id is required for scope_type={$scopeType}");
        }
    }

    private function resolveModel(CapitalPlan $plan): CapitalScoringModel
    {
        if ($plan->scoring_model_id !== null) {
            $model = $this->scoringModels->findById($plan->scoring_model_id);
            if ($model !== null) {
                return $model;
            }
        }
        // Division-scoped plans inherit the division's active model.
        $divisionId = $plan->scope_type === CapitalPlan::SCOPE_DIVISION ? $plan->scope_id : null;
        return $this->scoringModels->findActiveForDivision($divisionId);
    }

    /**
     * Honor scenario.global_options.inflation_rate_override by cloning the
     * model with a swapped rate. Bounded to [-0.5, +0.5] to match the
     * repository's clamp.
     */
    private function applyInflationOverride(
        CapitalScoringModel $model,
        CapitalPlanScenario $scenario
    ): CapitalScoringModel {
        $opts = $scenario->global_options ?? [];
        if (!array_key_exists('inflation_rate_override', $opts)) {
            return $model;
        }
        $rate = (float) $opts['inflation_rate_override'];
        $rate = max(-0.5, min(0.5, $rate));
        $clone = new CapitalScoringModel($model->toArray());
        $clone->annual_inflation_rate = $rate;
        return $clone;
    }

    /**
     * @return array<int, array{asset: SiteAsset, site_id: int, site_name: string, company_id: int, division_id: ?int}>
     */
    private function loadScopeAssets(CapitalPlan $plan): array
    {
        $filters = ['limit' => self::SCOPE_ASSET_LIMIT];
        if ($plan->scope_type === CapitalPlan::SCOPE_COMPANY && $plan->scope_id !== null) {
            $filters['company_id'] = $plan->scope_id;
        } elseif ($plan->scope_type === CapitalPlan::SCOPE_DIVISION && $plan->scope_id !== null) {
            $filters['division_id'] = $plan->scope_id;
        }
        return $this->assets->listForReport($filters);
    }

    /**
     * @param array<int, CapitalPlanScenarioOverride> $overrides
     * @return array<int, CapitalPlanScenarioOverride>
     */
    private function indexOverrides(array $overrides): array
    {
        $indexed = [];
        foreach ($overrides as $o) {
            $indexed[$o->site_asset_id] = $o;
        }
        return $indexed;
    }

    private function resolveEstimate(SiteAsset $asset, ?CapitalPlanScenarioOverride $override): ?int
    {
        if ($override !== null && $override->replacement_estimate_cents_override !== null) {
            return $override->replacement_estimate_cents_override;
        }
        return $asset->replacement_estimate_cents;
    }

    /**
     * Pull the year out of the scored row's replace_by_date. If missing or
     * before base_year, the asset is considered "due now" (base_year) so it
     * lands in the first horizon bucket — overdue handling happens later
     * once shifts/pins have been applied.
     *
     * @param array<string, mixed> $scored
     */
    private function yearFromScored(array $scored, int $baseYear): int
    {
        $replaceBy = $scored['replace_by_date'] ?? null;
        if (!is_string($replaceBy) || $replaceBy === '') {
            return $baseYear;
        }
        $year = (int) substr($replaceBy, 0, 4);
        if ($year <= 0) {
            return $baseYear;
        }
        return $year;
    }

    /**
     * Shift a date string (Y-m-d) by N months and return the resulting year.
     * Negative N pulls forward. Falls back to baseYear+monthsAsYears if the
     * date can't be parsed.
     */
    private function shiftYearByMonths(?string $dateString, int $baseYear, int $months): int
    {
        if (!is_string($dateString) || $dateString === '') {
            // No baseline date — pretend it's due today for the math.
            return $baseYear + (int) round($months / 12.0);
        }
        $datePart = substr($dateString, 0, 10);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $datePart);
        if (!$dt instanceof DateTimeImmutable) {
            return $baseYear + (int) round($months / 12.0);
        }
        $shifted = $months >= 0
            ? $dt->modify("+{$months} months")
            : $dt->modify($months . ' months'); // already-negative N
        return (int) $shifted->format('Y');
    }

    /**
     * @return array{raw_cents: int, projected_cents: int, asset_count: int}
     */
    private function emptyBucket(): array
    {
        return ['raw_cents' => 0, 'projected_cents' => 0, 'asset_count' => 0];
    }
}
