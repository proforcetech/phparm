<?php

namespace App\Services\CapitalPlan;

use App\Models\CapitalPlan;
use App\Models\CapitalPlanScenario;
use App\Models\CapitalPlanScenarioOverride;
use App\Models\CapitalScoringModel;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Phase 9 — Capital Planner HTTP facade. Phase 9.1 surfaces the aging asset
 * report; Phase 9.2 adds tunable scoring-model CRUD; Phase 9.3 hangs the
 * multi-year budget planner / what-if scenarios off this same controller.
 */
class CapitalPlanController
{
    public function __construct(
        private readonly AgingAssetReportService $aging,
        private readonly ?CapitalScoringModelRepository $scoringModels = null,
        private readonly ?AccessGate $gate = null,
        private readonly ?CapitalPlanService $plans = null,
    ) {
    }

    // ─────────────────────────────────────────────── Aging report (9.1) ────

    /**
     * @return array<string, mixed>
     */
    public function agingForCompany(User $actor, int $companyId): array
    {
        return ['data' => $this->aging->reportForCompany($actor, $companyId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function agingForDivision(User $actor, int $divisionId): array
    {
        return ['data' => $this->aging->reportForDivision($actor, $divisionId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function agingForPortfolio(User $actor): array
    {
        return ['data' => $this->aging->reportForPortfolio($actor)];
    }

    // ──────────────────────────────────────── Scoring model CRUD (9.2) ─────

    /**
     * @return array<string, mixed>
     */
    public function listScoringModels(User $actor, ?int $divisionId = null): array
    {
        $this->requireScoringDeps();
        $this->gate->assert($actor, 'capital_plans.view');
        $models = $this->scoringModels->listAll($divisionId);
        return ['data' => array_map(static fn(CapitalScoringModel $m) => $m->toArray(), $models)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getScoringModel(User $actor, int $id): array
    {
        $this->requireScoringDeps();
        $this->gate->assert($actor, 'capital_plans.view');
        $model = $this->scoringModels->findById($id);
        if ($model === null) {
            throw new InvalidArgumentException("Scoring model {$id} not found");
        }
        return ['data' => $model->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createScoringModel(User $actor, array $payload): array
    {
        $this->requireScoringDeps();
        $this->gate->assert($actor, 'capital_plans.manage');
        if (empty($payload['name'])) {
            throw new InvalidArgumentException('name is required');
        }
        $model = $this->scoringModels->create($payload);
        return ['data' => $model->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateScoringModel(User $actor, int $id, array $payload): array
    {
        $this->requireScoringDeps();
        $this->gate->assert($actor, 'capital_plans.manage');
        if ($this->scoringModels->findById($id) === null) {
            throw new InvalidArgumentException("Scoring model {$id} not found");
        }
        $model = $this->scoringModels->update($id, $payload);
        return ['data' => $model->toArray()];
    }

    public function deleteScoringModel(User $actor, int $id): void
    {
        $this->requireScoringDeps();
        $this->gate->assert($actor, 'capital_plans.manage');
        if ($this->scoringModels->findById($id) === null) {
            throw new InvalidArgumentException("Scoring model {$id} not found");
        }
        $this->scoringModels->delete($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function setDefaultScoringModel(User $actor, int $id): array
    {
        $this->requireScoringDeps();
        $this->gate->assert($actor, 'capital_plans.manage');
        if ($this->scoringModels->findById($id) === null) {
            throw new InvalidArgumentException("Scoring model {$id} not found");
        }
        $this->scoringModels->setDefault($id);
        return ['data' => $this->scoringModels->findById($id)?->toArray() ?? []];
    }

    private function requireScoringDeps(): void
    {
        if ($this->scoringModels === null || $this->gate === null) {
            throw new \LogicException('CapitalPlanController not configured with scoring-model deps');
        }
    }

    // ──────────────────────────────────────── Plans + scenarios (9.3) ──────

    /**
     * @return array<string, mixed>
     */
    public function listPlans(User $actor, array $filters = []): array
    {
        $this->requirePlannerDeps();
        $items = $this->plans->listPlans($actor, $filters);
        return ['data' => array_map(static fn(CapitalPlan $p) => $p->toArray(), $items)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlan(User $actor, int $planId): array
    {
        $this->requirePlannerDeps();
        $plan = $this->plans->findPlan($actor, $planId);
        $scenarios = $this->plans->listScenarios($actor, $planId);
        return [
            'data' => [
                'plan' => $plan->toArray(),
                'scenarios' => array_map(
                    static fn(CapitalPlanScenario $s) => $s->toArray(),
                    $scenarios
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPlan(User $actor, array $payload): array
    {
        $this->requirePlannerDeps();
        if (empty($payload['name'])) {
            throw new InvalidArgumentException('name is required');
        }
        $plan = $this->plans->createPlan($actor, $payload);
        return ['data' => $plan->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updatePlan(User $actor, int $planId, array $payload): array
    {
        $this->requirePlannerDeps();
        $plan = $this->plans->updatePlan($actor, $planId, $payload);
        return ['data' => $plan->toArray()];
    }

    public function deletePlan(User $actor, int $planId): void
    {
        $this->requirePlannerDeps();
        $this->plans->deletePlan($actor, $planId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function addScenario(User $actor, int $planId, array $payload): array
    {
        $this->requirePlannerDeps();
        $scenario = $this->plans->addScenario($actor, $planId, $payload);
        return ['data' => $scenario->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateScenario(User $actor, int $scenarioId, array $payload): array
    {
        $this->requirePlannerDeps();
        $scenario = $this->plans->updateScenario($actor, $scenarioId, $payload);
        return ['data' => $scenario->toArray()];
    }

    public function deleteScenario(User $actor, int $scenarioId): void
    {
        $this->requirePlannerDeps();
        $this->plans->deleteScenario($actor, $scenarioId);
    }

    /**
     * @return array<string, mixed>
     */
    public function listOverrides(User $actor, int $scenarioId): array
    {
        $this->requirePlannerDeps();
        $items = $this->plans->listOverrides($actor, $scenarioId);
        return [
            'data' => array_map(
                static fn(CapitalPlanScenarioOverride $o) => $o->toArray(),
                $items
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function setOverride(User $actor, int $scenarioId, array $payload): array
    {
        $this->requirePlannerDeps();
        $assetId = isset($payload['site_asset_id']) ? (int) $payload['site_asset_id'] : 0;
        $override = $this->plans->setOverride($actor, $scenarioId, $assetId, $payload);
        return ['data' => $override->toArray()];
    }

    public function removeOverride(User $actor, int $overrideId): void
    {
        $this->requirePlannerDeps();
        $this->plans->removeOverride($actor, $overrideId);
    }

    /**
     * @return array<string, mixed>
     */
    public function computeScenario(User $actor, int $scenarioId): array
    {
        $this->requirePlannerDeps();
        return ['data' => $this->plans->computeScenario($actor, $scenarioId)];
    }

    /**
     * @param array<int> $scenarioIds
     * @return array<string, mixed>
     */
    public function compareScenarios(User $actor, int $planId, array $scenarioIds = []): array
    {
        $this->requirePlannerDeps();
        return ['data' => $this->plans->compareScenarios($actor, $planId, $scenarioIds)];
    }

    /**
     * Phase 9.4 — return the customer-facing recommendation PDF as binary.
     * The route layer is responsible for emitting Content-Type and filename.
     *
     * @param array<string, mixed> $shopSettings
     */
    public function recommendationPdf(
        User $actor,
        int $scenarioId,
        array $shopSettings = [],
        ?string $scopeLabel = null
    ): string {
        $this->requirePlannerDeps();
        return $this->plans->renderRecommendationPdf($actor, $scenarioId, $shopSettings, $scopeLabel);
    }

    private function requirePlannerDeps(): void
    {
        if ($this->plans === null) {
            throw new \LogicException('CapitalPlanController not configured with planner service');
        }
    }
}
