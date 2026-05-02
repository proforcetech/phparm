<?php

namespace App\Services\Assets;

use App\Models\AssetAcquisition;
use App\Models\User;
use App\Support\Auth\AccessGate;

/**
 * HTTP controller for /api/asset-acquisitions — Phase 13 (M4).
 *
 * Read endpoints come through here directly. State transitions delegate to
 * AssetAcquisitionService (which owns the rule + audit). The controller's
 * job is response shaping (`toArray`) and read-side gating; write-side
 * gating lives in the service so it stays enforced regardless of caller
 * (HTTP, cron, future portal).
 */
class AssetAcquisitionController
{
    public function __construct(
        private readonly AssetAcquisitionService $service,
        private readonly AssetAcquisitionRepository $repository,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{acquisitions: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'asset_acquisitions.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->repository->list($filters, $perPage, $offset);
        $total = $this->repository->count($filters);

        return [
            'acquisitions' => array_map([self::class, 'toArray'], $rows),
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
    public function attachQuote(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->attachQuote($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function approve(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->customerApprove($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function reject(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->customerReject($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function issuePo(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->issuePo($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function markReceived(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->markReceived($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function scheduleInstall(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->scheduleInstall($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function markInstalled(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->markInstalled($user, $id, $body));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function activate(User $user, int $id, array $body): array
    {
        return self::toArray($this->service->activate($user, $id, $body));
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
    public static function toArray(AssetAcquisition $acq): array
    {
        return [
            'id' => $acq->id,
            'customer_id' => $acq->customer_id,
            'site_id' => $acq->site_id,
            'service_line_id' => $acq->service_line_id,
            'asset_type_id' => $acq->asset_type_id,
            'requested_by_user_id' => $acq->requested_by_user_id,
            'requested_by_portal_user_id' => $acq->requested_by_portal_user_id,
            'requested_at' => $acq->requested_at,
            'title' => $acq->title,
            'description' => $acq->description,
            'quantity' => $acq->quantity,
            'target_install_date' => $acq->target_install_date,
            'status' => $acq->status,
            'is_terminal' => $acq->isTerminal(),
            'allowed_transitions' => AssetAcquisition::allowedTransitionsFrom($acq->status),
            'estimate_id' => $acq->estimate_id,
            'customer_approved_at' => $acq->customer_approved_at,
            'customer_approved_by' => $acq->customer_approved_by,
            'customer_rejected_at' => $acq->customer_rejected_at,
            'customer_rejection_reason' => $acq->customer_rejection_reason,
            'vendor_name' => $acq->vendor_name,
            'vendor_po_number' => $acq->vendor_po_number,
            'vendor_po_total_cents' => $acq->vendor_po_total_cents,
            'vendor_po_issued_at' => $acq->vendor_po_issued_at,
            'received_at' => $acq->received_at,
            'received_by' => $acq->received_by,
            'install_workorder_id' => $acq->install_workorder_id,
            'install_scheduled_at' => $acq->install_scheduled_at,
            'installed_at' => $acq->installed_at,
            'target_site_asset_id' => $acq->target_site_asset_id,
            'activated_at' => $acq->activated_at,
            'activated_by' => $acq->activated_by,
            'cancelled_at' => $acq->cancelled_at,
            'cancelled_by' => $acq->cancelled_by,
            'cancelled_reason' => $acq->cancelled_reason,
            'last_state_changed_at' => $acq->last_state_changed_at,
            'last_state_changed_by' => $acq->last_state_changed_by,
            'created_at' => $acq->created_at,
            'updated_at' => $acq->updated_at,
        ];
    }
}
