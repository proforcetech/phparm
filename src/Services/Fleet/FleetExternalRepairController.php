<?php

namespace App\Services\Fleet;

use App\Models\User;

/**
 * Phase 7.5 of docs/expansion-plan.md — thin HTTP facade for external
 * vendor repair logs. All behavior lives in FleetExternalRepairService.
 */
class FleetExternalRepairController
{
    public function __construct(private readonly FleetExternalRepairService $service)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(User $actor, int $unitId, array $body): array
    {
        return ['data' => $this->service->createRepair($actor, $unitId, $body)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $actor, int $id, array $body): array
    {
        return ['data' => $this->service->updateRepair($actor, $id, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(User $actor, int $id): array
    {
        $this->service->deleteRepair($actor, $id);
        return ['data' => ['deleted' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForUnit(User $actor, int $unitId, int $limit): array
    {
        return ['data' => $this->service->listForUnit($actor, $unitId, $limit)];
    }

    /**
     * @param array{vendor?: ?string, category?: ?string, from?: ?string, to?: ?string, limit?: ?int} $filters
     * @return array<string, mixed>
     */
    public function listForCompany(User $actor, int $companyId, array $filters): array
    {
        return ['data' => $this->service->listForCompany($actor, $companyId, $filters)];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(User $actor, int $id): array
    {
        return ['data' => $this->service->getRepair($actor, $id)];
    }
}
