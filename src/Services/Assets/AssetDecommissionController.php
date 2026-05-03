<?php

namespace App\Services\Assets;

use App\Models\AssetDecommission;
use App\Models\User;
use App\Support\Auth\AccessGate;

/**
 * HTTP controller for /api/asset-decommissions — Phase 13 (M5).
 *
 * Read endpoints come through here directly. State transitions delegate to
 * AssetDecommissionService (which owns the rule + audit). The controller's
 * job is response shaping (`toArray`) and read-side gating; write-side
 * gating lives in the service so it stays enforced regardless of caller
 * (HTTP, cron, future portal).
 */
class AssetDecommissionController
{
    public function __construct(
        private readonly AssetDecommissionService $service,
        private readonly AssetDecommissionRepository $repository,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{decommissions: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'asset_decommissions.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->repository->list($filters, $perPage, $offset);
        $total = $this->repository->count($filters);

        return [
            'decommissions' => array_map([self::class, 'toArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        return self::toArray($this->service->find($user, $id));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function store(User $user, array $body): array
    {
        return self::toArray($this->service->create($user, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->update($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function startWipe(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->startWipe($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function completeWipe(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->completeWipe($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function startRecovery(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->startRecovery($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function completeRecovery(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->completeRecovery($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateEntitlements(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->updateEntitlements($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function markAudited(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->markAudited($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function retire(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->retire($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->cancel($user, $id, $body));
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(AssetDecommission $row): array
    {
        return [
            'id' => $row->id,
            'site_asset_id' => $row->site_asset_id,
            'customer_id' => $row->customer_id,
            'requested_by_user_id' => $row->requested_by_user_id,
            'requested_by_portal_user_id' => $row->requested_by_portal_user_id,
            'requested_at' => $row->requested_at,
            'reason' => $row->reason,
            'notes' => $row->notes,
            'requires_wipe' => $row->requiresWipe(),
            'recovery_method' => $row->recovery_method,
            'status' => $row->status,
            'is_terminal' => $row->isTerminal(),
            'allowed_transitions' => $row->allowedTransitions(),
            'wipe_started_at' => $row->wipe_started_at,
            'wipe_completed_at' => $row->wipe_completed_at,
            'wipe_completed_by' => $row->wipe_completed_by,
            'wipe_certificate_url' => $row->wipe_certificate_url,
            'recovery_started_at' => $row->recovery_started_at,
            'recovery_completed_at' => $row->recovery_completed_at,
            'recovery_completed_by' => $row->recovery_completed_by,
            'recovery_reference' => $row->recovery_reference,
            'recovery_value_cents' => $row->recovery_value_cents,
            'entitlement_updated_at' => $row->entitlement_updated_at,
            'entitlement_updated_by' => $row->entitlement_updated_by,
            'audited_at' => $row->audited_at,
            'audited_by' => $row->audited_by,
            'audit_log_id' => $row->audit_log_id,
            'retired_at' => $row->retired_at,
            'retired_by' => $row->retired_by,
            'cancelled_at' => $row->cancelled_at,
            'cancelled_by' => $row->cancelled_by,
            'cancelled_reason' => $row->cancelled_reason,
            'last_state_changed_at' => $row->last_state_changed_at,
            'last_state_changed_by' => $row->last_state_changed_by,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
