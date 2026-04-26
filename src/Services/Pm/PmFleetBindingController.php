<?php

namespace App\Services\Pm;

use App\Models\User;

/**
 * Phase 7.2 of docs/expansion-plan.md — thin HTTP facade for
 * pm_fleet_bindings CRUD. All behavior lives in
 * PmFleetInheritanceService; this exists so the route module doesn't
 * hard-code service semantics.
 */
class PmFleetBindingController
{
    public function __construct(private readonly PmFleetInheritanceService $service)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(User $actor, array $body): array
    {
        return ['data' => $this->service->createBinding($actor, $body)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $actor, int $id, array $body): array
    {
        return ['data' => $this->service->updateBinding($actor, $id, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(User $actor, int $id): array
    {
        $this->service->deleteBinding($actor, $id);
        return ['data' => ['id' => $id, 'deleted' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForCompany(User $actor, int $companyId, bool $activeOnly): array
    {
        return ['data' => $this->service->listBindingsForCompany($actor, $companyId, $activeOnly)];
    }
}
