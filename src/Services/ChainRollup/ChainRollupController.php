<?php

namespace App\Services\ChainRollup;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/chain-rollup — Phase 17 / S4 of
 * docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   chain_rollup.view  — read-only access (managers, dispatchers, account
 *                        owners; not technicians).
 */
class ChainRollupController
{
    public function __construct(
        private readonly ChainRollupService $service,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @return array<int, array{id: int, name: string, status: string, site_count: int}>
     */
    public function listChains(User $user, ?string $search = null): array
    {
        $this->gate->assert($user, 'chain_rollup.view');
        return $this->service->listChains($search);
    }

    /**
     * @return array<string, mixed>
     */
    public function rollup(User $user, int $companyId, string $periodStart, string $periodEnd): array
    {
        $this->gate->assert($user, 'chain_rollup.view');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company_id is required');
        }
        return $this->service->rollup($companyId, $periodStart, $periodEnd);
    }
}
