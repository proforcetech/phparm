<?php

namespace App\Services\Inspection;

use App\Models\User;

/**
 * Phase 8.2 — thin HTTP facade for inspection auto-generation policy
 * CRUD + manual evaluate-report trigger. All behavior lives in
 * InspectionDeficiencyAutoService.
 */
class InspectionDeficiencyAutoController
{
    public function __construct(private readonly InspectionDeficiencyAutoService $service)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createPolicy(User $actor, array $body): array
    {
        return ['data' => $this->service->createPolicy($actor, $body)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updatePolicy(User $actor, int $id, array $body): array
    {
        return ['data' => $this->service->updatePolicy($actor, $id, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function deletePolicy(User $actor, int $id): array
    {
        $this->service->deletePolicy($actor, $id);
        return ['data' => ['deleted' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    public function listPolicies(User $actor, ?int $divisionId, bool $activeOnly): array
    {
        return ['data' => $this->service->listPolicies($actor, $divisionId, $activeOnly)];
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateReport(User $actor, int $reportId): array
    {
        return ['data' => $this->service->evaluateReport($actor, $reportId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listRuns(User $actor, int $reportId): array
    {
        return ['data' => $this->service->listRunsForReport($actor, $reportId)];
    }
}
