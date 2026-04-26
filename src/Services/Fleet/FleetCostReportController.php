<?php

namespace App\Services\Fleet;

use App\Models\User;

/**
 * Phase 7.4 of docs/expansion-plan.md — thin HTTP facade for fleet cost
 * reports. Behavior lives in FleetCostReportService.
 */
class FleetCostReportController
{
    public function __construct(private readonly FleetCostReportService $service)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function costPerMile(User $actor, int $companyId, string $from, string $to): array
    {
        return ['data' => $this->service->costPerMile($actor, $companyId, $from, $to)];
    }

    /**
     * @return array<string, mixed>
     */
    public function costPerHour(User $actor, int $companyId, string $from, string $to): array
    {
        return ['data' => $this->service->costPerHour($actor, $companyId, $from, $to)];
    }
}
