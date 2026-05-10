<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;

class PortalAuditController
{
    public function __construct(private readonly PortalAuditService $service)
    {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array<string, mixed>>
     */
    public function listForAccount(User $user, PortalAccount $account, array $query): array
    {
        $limit = isset($query['limit']) ? (int) $query['limit'] : 200;
        return $this->service->listForAccount($user, $account, $limit);
    }
}
