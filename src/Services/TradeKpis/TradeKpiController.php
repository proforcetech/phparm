<?php

namespace App\Services\TradeKpis;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/trade-kpis — Phase 17 / S10 of
 * docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   trade_kpis.view  — read-only access for managers and dispatchers.
 */
class TradeKpiController
{
    public function __construct(
        private readonly TradeKpiService $service,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @return array<int, array{id: int, slug: string, name: string}>
     */
    public function listServiceLines(User $user): array
    {
        $this->gate->assert($user, 'trade_kpis.view');
        return $this->service->listServiceLines();
    }

    /**
     * @return array<string, mixed>
     */
    public function bundle(User $user, int $serviceLineId, string $periodStart, string $periodEnd): array
    {
        $this->gate->assert($user, 'trade_kpis.view');
        if ($serviceLineId <= 0) {
            throw new InvalidArgumentException('service_line_id is required');
        }
        return $this->service->bundle($serviceLineId, $periodStart, $periodEnd);
    }
}
