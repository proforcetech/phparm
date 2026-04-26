<?php

namespace App\Services\Fleet;

use App\Models\User;

/**
 * Phase 7.1 of docs/expansion-plan.md — HTTP facade for fleet units.
 * Thin one-liner delegation; all auth/validation/transactional concerns
 * stay in FleetUnitService.
 */
class FleetUnitController
{
    public function __construct(
        private readonly FleetUnitService $service,
    ) {
    }

    // ── Units ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createUnit(User $actor, int $companyId, array $body): array
    {
        return ['data' => $this->service->createUnit($actor, $companyId, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getUnit(User $actor, int $unitId): array
    {
        return ['data' => $this->service->getUnit($actor, $unitId)];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listUnits(User $actor, int $companyId, array $filters): array
    {
        $out = $this->service->listForCompany($actor, $companyId, $filters);
        return ['data' => $out['data'], 'meta' => ['total' => $out['total']]];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateUnit(User $actor, int $unitId, array $body): array
    {
        return ['data' => $this->service->updateUnit($actor, $unitId, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function retireUnit(User $actor, int $unitId): array
    {
        $this->service->retireUnit($actor, $unitId);
        return ['data' => ['retired' => true, 'id' => $unitId]];
    }

    // ── Readings ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function recordReading(User $actor, int $unitId, array $body): array
    {
        return ['data' => $this->service->recordReading($actor, $unitId, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listReadings(User $actor, int $unitId, ?string $readingType, int $limit): array
    {
        return ['data' => $this->service->listReadings($actor, $unitId, $readingType, $limit)];
    }

    // ── Assignments ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function assignUnit(User $actor, int $unitId, array $body): array
    {
        return ['data' => $this->service->assignUnit($actor, $unitId, $body)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function endAssignment(User $actor, int $unitId, array $body): array
    {
        $endedAt = isset($body['ended_at']) && is_string($body['ended_at']) ? $body['ended_at'] : null;
        $this->service->endAssignment($actor, $unitId, $endedAt);
        return ['data' => ['ended' => true, 'fleet_unit_id' => $unitId]];
    }

    /**
     * @return array<string, mixed>
     */
    public function listAssignments(User $actor, int $unitId): array
    {
        return ['data' => $this->service->listAssignments($actor, $unitId)];
    }
}
