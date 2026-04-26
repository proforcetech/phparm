<?php

namespace App\Services\Fleet;

use App\Models\User;

/**
 * Phase 7.3 of docs/expansion-plan.md — thin HTTP facade for
 * fleet_unit_downtime. All behavior lives in FleetUnitDowntimeService.
 */
class FleetUnitDowntimeController
{
    public function __construct(private readonly FleetUnitDowntimeService $service)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function start(User $actor, int $unitId, array $body): array
    {
        return ['data' => $this->service->startDowntime($actor, $unitId, $body)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function end(User $actor, int $unitId, array $body): array
    {
        return ['data' => $this->service->endDowntime($actor, $unitId, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForUnit(User $actor, int $unitId, int $limit): array
    {
        return ['data' => $this->service->listDowntime($actor, $unitId, $limit)];
    }

    /**
     * @return array<string, mixed>
     */
    public function current(User $actor, int $unitId): array
    {
        return ['data' => $this->service->currentDowntime($actor, $unitId)];
    }
}
