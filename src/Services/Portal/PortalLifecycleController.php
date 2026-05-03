<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;

/**
 * Phase 13 (#123) of docs/woms-expansion-plan.md — HTTP facade for the
 * portal lease + acquisition + decommission view.
 *
 * Read endpoints come through here; write endpoints (lease decision,
 * acquisition approve/reject) delegate to PortalLifecycleService which
 * routes through the staff service layer for the actual transition so
 * matrix/audit rules stay single-sourced.
 */
class PortalLifecycleController
{
    public function __construct(
        private readonly PortalLifecycleService $service,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listLeases(User $user, PortalAccount $account, array $query = []): array
    {
        return $this->service->listLeases($account, $this->normalizeFilters($query));
    }

    /**
     * @return array<string, mixed>
     */
    public function getLease(User $user, PortalAccount $account, int $leaseId): array
    {
        return ['data' => $this->service->getLease($account, $leaseId)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function recordLeaseDecision(User $user, PortalAccount $account, int $leaseId, array $body): array
    {
        return ['data' => $this->service->recordLeaseDecision($user, $account, $leaseId, $body)];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listAcquisitions(User $user, PortalAccount $account, array $query = []): array
    {
        return $this->service->listAcquisitions($account, $this->normalizeFilters($query));
    }

    /**
     * @return array<string, mixed>
     */
    public function getAcquisition(User $user, PortalAccount $account, int $acquisitionId): array
    {
        return ['data' => $this->service->getAcquisition($account, $acquisitionId)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function approveAcquisition(User $user, PortalAccount $account, int $acquisitionId, array $body): array
    {
        return ['data' => $this->service->approveAcquisition($user, $account, $acquisitionId, $body)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function rejectAcquisition(User $user, PortalAccount $account, int $acquisitionId, array $body): array
    {
        return ['data' => $this->service->rejectAcquisition($user, $account, $acquisitionId, $body)];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listDecommissions(User $user, PortalAccount $account, array $query = []): array
    {
        return $this->service->listDecommissions($account, $this->normalizeFilters($query));
    }

    /**
     * @return array<string, mixed>
     */
    public function getDecommission(User $user, PortalAccount $account, int $decommissionId): array
    {
        return ['data' => $this->service->getDecommission($account, $decommissionId)];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $query): array
    {
        $filters = [];
        if (isset($query['status']) && is_string($query['status']) && $query['status'] !== '') {
            $filters['status'] = $query['status'];
        }
        if (isset($query['limit'])) {
            $filters['limit'] = (int) $query['limit'];
        }
        if (isset($query['offset'])) {
            $filters['offset'] = (int) $query['offset'];
        }
        return $filters;
    }
}
