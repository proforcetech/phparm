<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;

/**
 * Phase 2c — thin controller for portal-facing workorder reads.
 */
class PortalWorkorderController
{
    public function __construct(
        private readonly PortalWorkorderService $service,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listForPortal(User $user, PortalAccount $account, array $query): array
    {
        return $this->service->listForPortal($user, $account, $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function getForPortal(User $user, PortalAccount $account, int $workorderId): array
    {
        return $this->service->getForPortal($user, $account, $workorderId);
    }
}
