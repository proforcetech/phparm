<?php

namespace App\Services\Portal;

use App\Models\AssetAcquisition;
use App\Models\AssetDecommission;
use App\Models\AssetLease;
use App\Models\PortalAccount;
use App\Models\User;
use App\Services\Assets\AssetAcquisitionRepository;
use App\Services\Assets\AssetAcquisitionService;
use App\Services\Assets\AssetDecommissionRepository;
use App\Services\Assets\AssetLeaseRepository;
use App\Services\Customer\CustomerRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 13 (#123) of docs/woms-expansion-plan.md — portal-facing surface for
 * lease + acquisition + decommission rows owned by the signed-in portal
 * user's company.
 *
 * Reads only, with two write actions:
 *   * recordLeaseDecision()        — customer chooses renew/buyout/return/replace
 *   * approveAcquisition()/reject  — customer approves the staff-issued quote
 * Both writes go through the existing staff services (not the repo) so
 * permission/audit/transition rules stay single-sourced. The portal context
 * is recorded in audit context so admins can tell a portal-driven decision
 * from a staff-driven one.
 *
 * Scope layers (belt + suspenders with Middleware::portalAuth):
 *   1. portal_account.isUsable() — revoked accounts get nothing.
 *   2. row.customer_id ∈ customers.listIdsForCompany(account.company_id)
 *      — cross-tenant rows are invisible.
 *   3. on the per-row reads, we re-resolve the row and re-check ownership
 *      so guessing an ID from another tenant returns "not found".
 *
 * Service-line / requires-wipe / vendor-PO fields stay in the response —
 * the customer is the legitimate end-of-life decision-maker on equipment
 * they're being charged for, so we don't strip vendor/cost data the way
 * we strip alarm codes from the site view.
 */
class PortalLifecycleService
{
    public function __construct(
        private readonly AssetLeaseRepository $leases,
        private readonly AssetAcquisitionRepository $acquisitionsRepo,
        private readonly AssetAcquisitionService $acquisitionsService,
        private readonly AssetDecommissionRepository $decommissions,
        private readonly CustomerRepository $customers,
        private readonly ?AuditLogger $audit = null,
        private readonly ?PortalPermissionService $permissions = null,
    ) {
    }

    private function assertCanDecide(PortalAccount $account): void
    {
        ($this->permissions ?? new PortalPermissionService())
            ->assert($account, PortalPermission::DECIDE_LIFECYCLE);
    }

    // ── leases ───────────────────────────────────────────────────────────────

    /**
     * @param array{status?: string, limit?: int, offset?: int} $filters
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function listLeases(PortalAccount $account, array $filters = []): array
    {
        $customerIds = $this->customerIds($account);
        if ($customerIds === []) {
            return ['data' => [], 'total' => 0];
        }

        [$limit, $offset] = $this->page($filters);

        // Repo only filters by a single customer_id; the portal is small
        // enough per company that we can fan out and merge. We cap the
        // per-customer pull at $limit so a 50-customer company still
        // bounds memory at limit*50.
        $rows = [];
        foreach ($customerIds as $cid) {
            $perFilters = ['customer_id' => $cid];
            if (!empty($filters['status']) && is_string($filters['status'])) {
                $perFilters['status'] = $filters['status'];
            }
            foreach ($this->leases->list($perFilters, $limit, 0) as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, static fn (AssetLease $a, AssetLease $b) => $b->id <=> $a->id);
        $total = count($rows);
        $page = array_slice($rows, $offset, $limit);

        return [
            'data' => array_map(fn (AssetLease $l) => $this->serializeLease($l), $page),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getLease(PortalAccount $account, int $leaseId): array
    {
        return $this->serializeLease($this->loadScopedLease($account, $leaseId));
    }

    /**
     * Customer end-of-lease decision. Audited with `actor_kind=portal_user`
     * so the staff timeline shows the portal origin distinctly.
     *
     * @param array<string, mixed> $body { decision: string, note?: string }
     */
    public function recordLeaseDecision(
        User $portalUser,
        PortalAccount $account,
        int $leaseId,
        array $body,
    ): array {
        $this->assertCanDecide($account);
        $decision = trim((string) ($body['decision'] ?? ''));
        if (!in_array($decision, AssetLease::ALLOWED_DECISIONS, true)) {
            throw new InvalidArgumentException(
                'decision must be one of: ' . implode(', ', AssetLease::ALLOWED_DECISIONS)
            );
        }

        $current = $this->loadScopedLease($account, $leaseId);
        if ($current->end_of_lease_decision !== null) {
            throw new InvalidArgumentException(
                'A lease decision has already been recorded for this lease.'
            );
        }
        if (!in_array($current->status, [AssetLease::STATUS_ACTIVE, AssetLease::STATUS_PENDING_RENEWAL], true)) {
            throw new InvalidArgumentException(
                "Lease in status '{$current->status}' is not eligible for an end-of-lease decision."
            );
        }

        $updated = $this->leases->recordDecision(
            $leaseId,
            $decision,
            (int) $portalUser->id,
            $this->now(),
        );

        $this->logPortalAction(
            'lease.decision_recorded',
            'asset_lease',
            (string) $leaseId,
            (int) $portalUser->id,
            $account,
            [
                'decision' => $decision,
                'previous_status' => $current->status,
                'new_status' => $updated->status,
                'note' => $this->stringOrNull($body['note'] ?? null),
            ],
        );

        return $this->serializeLease($updated);
    }

    // ── acquisitions ─────────────────────────────────────────────────────────

    /**
     * @param array{status?: string, limit?: int, offset?: int} $filters
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function listAcquisitions(PortalAccount $account, array $filters = []): array
    {
        $customerIds = $this->customerIds($account);
        if ($customerIds === []) {
            return ['data' => [], 'total' => 0];
        }
        [$limit, $offset] = $this->page($filters);

        $rows = [];
        foreach ($customerIds as $cid) {
            $perFilters = ['customer_id' => $cid];
            if (!empty($filters['status']) && is_string($filters['status'])) {
                $perFilters['status'] = $filters['status'];
            }
            foreach ($this->acquisitionsRepo->list($perFilters, $limit, 0) as $row) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn (AssetAcquisition $a, AssetAcquisition $b) => $b->id <=> $a->id);
        $total = count($rows);
        $page = array_slice($rows, $offset, $limit);

        return [
            'data' => array_map(fn (AssetAcquisition $a) => $this->serializeAcquisition($a), $page),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAcquisition(PortalAccount $account, int $acquisitionId): array
    {
        return $this->serializeAcquisition($this->loadScopedAcquisition($account, $acquisitionId));
    }

    /**
     * Customer approval of a quoted acquisition. Routed through the staff
     * service so the matrix check + status write + audit row stay in one
     * place. We then attach a second audit entry capturing the portal
     * origin (so admins can distinguish a staff-recorded approval from a
     * customer-recorded one).
     *
     * @param array<string, mixed> $body { note?: string }
     */
    public function approveAcquisition(
        User $portalUser,
        PortalAccount $account,
        int $acquisitionId,
        array $body,
    ): array {
        $this->assertCanDecide($account);
        $current = $this->loadScopedAcquisition($account, $acquisitionId);
        if ($current->status !== AssetAcquisition::STATUS_QUOTED) {
            throw new InvalidArgumentException(
                "Acquisition is in status '{$current->status}'; approval is only allowed when status='quoted'."
            );
        }

        $updated = $this->acquisitionsService->customerApprove($portalUser, $acquisitionId, [
            'customer_approved_by' => (int) $portalUser->id,
            'note' => $this->stringOrNull($body['note'] ?? null),
        ]);

        $this->logPortalAction(
            'acquisition.portal_approved',
            'asset_acquisition',
            (string) $acquisitionId,
            (int) $portalUser->id,
            $account,
            ['note' => $this->stringOrNull($body['note'] ?? null)],
        );

        return $this->serializeAcquisition($updated);
    }

    /**
     * @param array<string, mixed> $body { reason: string }
     */
    public function rejectAcquisition(
        User $portalUser,
        PortalAccount $account,
        int $acquisitionId,
        array $body,
    ): array {
        $this->assertCanDecide($account);
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('reason is required');
        }
        $current = $this->loadScopedAcquisition($account, $acquisitionId);
        if ($current->status !== AssetAcquisition::STATUS_QUOTED) {
            throw new InvalidArgumentException(
                "Acquisition is in status '{$current->status}'; rejection is only allowed when status='quoted'."
            );
        }

        $updated = $this->acquisitionsService->customerReject($portalUser, $acquisitionId, [
            'reason' => $reason,
        ]);

        $this->logPortalAction(
            'acquisition.portal_rejected',
            'asset_acquisition',
            (string) $acquisitionId,
            (int) $portalUser->id,
            $account,
            ['reason' => $reason],
        );

        return $this->serializeAcquisition($updated);
    }

    // ── decommissions (read-only on the portal) ──────────────────────────────

    /**
     * @param array{status?: string, limit?: int, offset?: int} $filters
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function listDecommissions(PortalAccount $account, array $filters = []): array
    {
        $customerIds = $this->customerIds($account);
        if ($customerIds === []) {
            return ['data' => [], 'total' => 0];
        }
        [$limit, $offset] = $this->page($filters);

        $rows = [];
        foreach ($customerIds as $cid) {
            $perFilters = ['customer_id' => $cid];
            if (!empty($filters['status']) && is_string($filters['status'])) {
                $perFilters['status'] = $filters['status'];
            }
            foreach ($this->decommissions->list($perFilters, $limit, 0) as $row) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn (AssetDecommission $a, AssetDecommission $b) => $b->id <=> $a->id);
        $total = count($rows);
        $page = array_slice($rows, $offset, $limit);

        return [
            'data' => array_map(fn (AssetDecommission $d) => $this->serializeDecommission($d), $page),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDecommission(PortalAccount $account, int $decommissionId): array
    {
        return $this->serializeDecommission($this->loadScopedDecommission($account, $decommissionId));
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * @return array<int, int>
     */
    private function customerIds(PortalAccount $account): array
    {
        $this->assertUsable($account);
        return $this->customers->listIdsForCompany($account->company_id);
    }

    private function loadScopedLease(PortalAccount $account, int $id): AssetLease
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('lease id is required');
        }
        $row = $this->leases->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("lease {$id} not found");
        }
        $this->assertCustomerInScope($account, $row->customer_id, "lease {$id}");
        return $row;
    }

    private function loadScopedAcquisition(PortalAccount $account, int $id): AssetAcquisition
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('acquisition id is required');
        }
        $row = $this->acquisitionsRepo->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("acquisition {$id} not found");
        }
        $this->assertCustomerInScope($account, $row->customer_id, "acquisition {$id}");
        return $row;
    }

    private function loadScopedDecommission(PortalAccount $account, int $id): AssetDecommission
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('decommission id is required');
        }
        $row = $this->decommissions->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("decommission {$id} not found");
        }
        $this->assertCustomerInScope($account, $row->customer_id, "decommission {$id}");
        return $row;
    }

    private function assertCustomerInScope(PortalAccount $account, ?int $customerId, string $label): void
    {
        $this->assertUsable($account);
        if ($customerId === null || $customerId <= 0) {
            // No customer means staff-internal — invisible to portal.
            throw new UnauthorizedException("{$label} is not portal-visible");
        }
        $customer = $this->customers->find($customerId);
        if ($customer === null || (int) ($customer->company_id ?? 0) !== $account->company_id) {
            throw new UnauthorizedException("{$label} does not belong to the portal account");
        }
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: int, 1: int}
     */
    private function page(array $filters): array
    {
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        return [$limit, $offset];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logPortalAction(
        string $event,
        string $entityType,
        string $entityId,
        int $portalUserId,
        PortalAccount $account,
        array $context = [],
    ): void {
        if ($this->audit === null) {
            return;
        }
        $context['actor_kind'] = 'portal_user';
        $context['portal_account_id'] = $account->id;
        $context['portal_user_id'] = $portalUserId;
        $context['company_id'] = $account->company_id;

        $this->audit->log(new AuditEntry(
            $event,
            $entityType,
            $entityId,
            $portalUserId > 0 ? $portalUserId : null,
            $context,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLease(AssetLease $l): array
    {
        $today = (new DateTimeImmutable($this->now()))->format('Y-m-d');
        return [
            'id' => $l->id,
            'site_asset_id' => $l->site_asset_id,
            'customer_id' => $l->customer_id,
            'lessor_name' => $l->lessor_name,
            'lessor_contact' => $l->lessor_contact,
            'lease_number' => $l->lease_number,
            'start_date' => $l->start_date,
            'end_date' => $l->end_date,
            'days_remaining' => $this->daysBetween($today, $l->end_date),
            'monthly_payment_cents' => $l->monthly_payment_cents,
            'payment_schedule' => $l->payment_schedule,
            'mileage_cap' => $l->mileage_cap,
            'current_mileage' => $l->current_mileage,
            'residual_value_cents' => $l->residual_value_cents,
            'buyout_price_cents' => $l->buyout_price_cents,
            'status' => $l->status,
            'end_of_lease_decision' => $l->end_of_lease_decision,
            'decision_made_at' => $l->decision_made_at,
            'decision_eligible' => $l->end_of_lease_decision === null
                && in_array($l->status, [AssetLease::STATUS_ACTIVE, AssetLease::STATUS_PENDING_RENEWAL], true),
            'allowed_decisions' => AssetLease::ALLOWED_DECISIONS,
            'terms' => $l->terms,
            'notes' => $l->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAcquisition(AssetAcquisition $a): array
    {
        return [
            'id' => $a->id,
            'customer_id' => $a->customer_id,
            'site_id' => $a->site_id,
            'asset_type_id' => $a->asset_type_id,
            'requested_at' => $a->requested_at,
            'title' => $a->title,
            'description' => $a->description,
            'quantity' => $a->quantity,
            'target_install_date' => $a->target_install_date,
            'status' => $a->status,
            'is_terminal' => $a->isTerminal(),
            'estimate_id' => $a->estimate_id,
            'customer_approved_at' => $a->customer_approved_at,
            'customer_rejected_at' => $a->customer_rejected_at,
            'customer_rejection_reason' => $a->customer_rejection_reason,
            'vendor_name' => $a->vendor_name,
            'vendor_po_number' => $a->vendor_po_number,
            'vendor_po_total_cents' => $a->vendor_po_total_cents,
            'install_scheduled_at' => $a->install_scheduled_at,
            'installed_at' => $a->installed_at,
            'activated_at' => $a->activated_at,
            'cancelled_at' => $a->cancelled_at,
            'cancelled_reason' => $a->cancelled_reason,
            // Surface only the actions the portal user can actually take —
            // approve/reject from quoted, otherwise nothing. Cancel/PO/install
            // remain staff-side actions.
            'portal_actions' => $a->status === AssetAcquisition::STATUS_QUOTED
                ? ['approve', 'reject']
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDecommission(AssetDecommission $d): array
    {
        return [
            'id' => $d->id,
            'site_asset_id' => $d->site_asset_id,
            'customer_id' => $d->customer_id,
            'requested_at' => $d->requested_at,
            'reason' => $d->reason,
            'notes' => $d->notes,
            'requires_wipe' => $d->requiresWipe(),
            'recovery_method' => $d->recovery_method,
            'status' => $d->status,
            'is_terminal' => $d->isTerminal(),
            'wipe_completed_at' => $d->wipe_completed_at,
            'wipe_certificate_url' => $d->wipe_certificate_url,
            'recovery_completed_at' => $d->recovery_completed_at,
            'recovery_reference' => $d->recovery_reference,
            'recovery_value_cents' => $d->recovery_value_cents,
            'audited_at' => $d->audited_at,
            'retired_at' => $d->retired_at,
            'cancelled_at' => $d->cancelled_at,
            'cancelled_reason' => $d->cancelled_reason,
        ];
    }

    private function daysBetween(string $todayYmd, string $endDate): ?int
    {
        if ($endDate === '') {
            return null;
        }
        try {
            $today = new DateTimeImmutable($todayYmd);
            $end = new DateTimeImmutable($endDate);
        } catch (\Throwable) {
            return null;
        }
        $diff = $today->diff($end);
        return (int) $diff->format('%r%a');
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
