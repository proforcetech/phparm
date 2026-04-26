<?php

namespace App\Services\Inspection;

use App\Models\User;

/**
 * Phase 8.4 — thin HTTP facade for the risk scoring + trend analysis
 * service. All behavior lives in InspectionRiskScoringService.
 */
class InspectionRiskScoringController
{
    public function __construct(private readonly InspectionRiskScoringService $service)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function scoreReport(User $actor, int $reportId): array
    {
        return ['data' => $this->service->scoreReport($actor, $reportId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getReportScore(User $actor, int $reportId): array
    {
        $data = $this->service->getReportScore($actor, $reportId);
        return ['data' => $data];
    }

    /**
     * @return array<string, mixed>
     */
    public function vehicleTrend(
        User $actor,
        int $vehicleId,
        ?string $from,
        ?string $to,
        int $limit,
    ): array {
        return ['data' => $this->service->vehicleTrend($actor, $vehicleId, $from, $to, $limit)];
    }

    /**
     * @return array<string, mixed>
     */
    public function divisionTrend(
        User $actor,
        int $divisionId,
        string $from,
        string $to,
        int $limit,
    ): array {
        return ['data' => $this->service->divisionTrend($actor, $divisionId, $from, $to, $limit)];
    }
}
