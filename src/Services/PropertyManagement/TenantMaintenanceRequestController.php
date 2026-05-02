<?php

namespace App\Services\PropertyManagement;

use App\Database\Connection;
use App\Models\Tenant;
use App\Models\TenantLease;
use App\Models\TenantMaintenanceRequest;
use App\Models\User;
use App\Models\Workorder;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Tenant-side maintenance request flow — Phase 12 closeout of
 * docs/woms-expansion-plan.md.
 *
 * Two surfaces:
 *
 *   Tenant portal (auth via Tenant.portal_user_id; no role gate beyond
 *   "is a tenant"): submit, list-mine, cancel-mine.
 *
 *   Staff queue (gated on `property.units.manage`): list-all, triage,
 *   convert-to-workorder, decline.
 *
 * The conversion path creates a workorder pre-pinned to the unit, then
 * immediately reuses TenantBillingResolver to snapshot the billing party.
 * That snapshot is what makes the billing routing immutable: a later lease
 * change can never retroactively re-route a converted request.
 */
class TenantMaintenanceRequestController
{
    public function __construct(
        private readonly TenantMaintenanceRequestRepository $repository,
        private readonly TenantRepository $tenantRepository,
        private readonly TenantLeaseRepository $leaseRepository,
        private readonly UnitRepository $unitRepository,
        private readonly TenantBillingResolver $billingResolver,
        private readonly WorkorderRepository $workorderRepository,
        private readonly Connection $connection,
        private readonly AccessGate $gate,
    ) {
    }

    // -------------------------------------------------------------------------
    // Tenant-side endpoints
    // -------------------------------------------------------------------------

    /**
     * Resolves the authenticated user's tenant identity and returns the units
     * they have an active lease on. Drives the tenant-portal "My Units"
     * surface and seeds the unit picker on the request form.
     *
     * @return array{tenant: array<string, mixed>, units: array<int, array<string, mixed>>}
     */
    public function me(User $user): array
    {
        $tenant = $this->tenantRepository->findByPortalUserId($user->id);
        if ($tenant === null) {
            throw new InvalidArgumentException('No tenant profile is linked to this account.');
        }

        $units = $this->fetchUnitsForTenant($tenant->id);

        return [
            'tenant' => $this->tenantToArray($tenant),
            'units' => $units,
        ];
    }

    /**
     * @return array{requests: array<int, array<string, mixed>>, total: int}
     */
    public function listMine(User $user, int $page = 1, int $perPage = 50): array
    {
        $tenant = $this->requireTenant($user);

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->repository->list(['tenant_id' => $tenant->id], $perPage, $offset);
        $total = $this->repository->count(['tenant_id' => $tenant->id]);

        return [
            'requests' => array_map([self::class, 'toArray'], $rows),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(User $user, array $body): array
    {
        $tenant = $this->requireTenant($user);

        $unitId = (int) ($body['unit_id'] ?? 0);
        if ($unitId <= 0) {
            throw new InvalidArgumentException('unit_id is required.');
        }

        // Tenant must have an active lease on the unit at request time.
        // Without this check, any tenant could file requests against any
        // unit by guessing IDs.
        $lease = $this->leaseRepository->findActiveForUnit($unitId);
        if ($lease === null || (int) $lease->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException(
                'You do not have an active lease on this unit.'
            );
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('title is required.');
        }

        $request = $this->repository->create([
            'tenant_id' => $tenant->id,
            'unit_id' => $unitId,
            'tenant_lease_id' => $lease->id,
            'category' => isset($body['category']) ? (string) $body['category'] : null,
            'priority' => isset($body['priority']) ? (string) $body['priority'] : 'normal',
            'title' => $title,
            'description' => isset($body['description']) ? (string) $body['description'] : null,
        ]);

        return self::toArray($request);
    }

    public function cancelMine(User $user, int $id): array
    {
        $tenant = $this->requireTenant($user);

        $request = $this->repository->findById($id);
        if ($request === null || (int) $request->tenant_id !== (int) $tenant->id) {
            throw new InvalidArgumentException('Maintenance request not found.');
        }
        if ($request->status !== TenantMaintenanceRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(
                'Only pending requests can be cancelled.'
            );
        }

        $cancelled = $this->repository->markCancelledByTenant($id, $tenant->id);
        if ($cancelled === null) {
            throw new RuntimeException('Failed to cancel maintenance request.');
        }
        return self::toArray($cancelled);
    }

    // -------------------------------------------------------------------------
    // Staff-side endpoints
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{requests: array<int, array<string, mixed>>, total: int}
     */
    public function staffList(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'property.units.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->repository->list($filters, $perPage, $offset);
        $total = $this->repository->count($filters);

        return [
            'requests' => array_map([self::class, 'toArray'], $rows),
            'total' => $total,
        ];
    }

    public function triage(User $user, int $id): array
    {
        $this->gate->assert($user, 'property.units.manage');

        $request = $this->repository->findById($id);
        if ($request === null) {
            throw new InvalidArgumentException('Maintenance request not found.');
        }
        if ($request->status !== TenantMaintenanceRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(
                'Only pending requests can be triaged. Current: ' . $request->status
            );
        }

        $updated = $this->repository->markTriaged($id, $user->id);
        if ($updated === null) {
            throw new RuntimeException('Failed to update maintenance request.');
        }
        return self::toArray($updated);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function decline(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'property.units.manage');

        $request = $this->repository->findById($id);
        if ($request === null) {
            throw new InvalidArgumentException('Maintenance request not found.');
        }
        if (in_array($request->status, [
            TenantMaintenanceRequest::STATUS_CONVERTED,
            TenantMaintenanceRequest::STATUS_CANCELLED,
        ], true)) {
            throw new InvalidArgumentException(
                'Cannot decline a request that is already ' . $request->status . '.'
            );
        }

        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('reason is required when declining.');
        }

        $updated = $this->repository->markDeclined($id, $reason, $user->id);
        if ($updated === null) {
            throw new RuntimeException('Failed to update maintenance request.');
        }
        return self::toArray($updated);
    }

    /**
     * Creates a workorder pre-pinned to the request's unit, snapshots the
     * billing party from the unit's *current* active lease (which may be the
     * same lease the request was filed under or a successor), and links the
     * WO back to the request.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function convertToWorkorder(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'property.units.manage');

        $request = $this->repository->findById($id);
        if ($request === null) {
            throw new InvalidArgumentException('Maintenance request not found.');
        }
        if ($request->status === TenantMaintenanceRequest::STATUS_CONVERTED) {
            throw new InvalidArgumentException('Request has already been converted.');
        }
        if (in_array($request->status, [
            TenantMaintenanceRequest::STATUS_DECLINED,
            TenantMaintenanceRequest::STATUS_CANCELLED,
        ], true)) {
            throw new InvalidArgumentException(
                'Cannot convert a request that is ' . $request->status . '.'
            );
        }

        $tenant = $this->tenantRepository->findById($request->tenant_id);
        if ($tenant === null || $tenant->company_id === null) {
            throw new InvalidArgumentException(
                'Tenant has no linked customer/company; cannot create a workorder.'
            );
        }

        $unit = $this->unitRepository->findById($request->unit_id);
        if ($unit === null) {
            throw new InvalidArgumentException('Unit not found.');
        }

        $branchId = isset($body['branch_id']) ? (int) $body['branch_id'] : null;

        // Snapshot the current billing routing decision. We re-resolve here
        // (rather than reusing the lease the request was filed under) so the
        // WO picks up the *current* lease — matches what would happen if a
        // staff member had created the WO directly via WorkorderController.
        $lease = $this->leaseRepository->findActiveForUnit($request->unit_id);
        $billingParty = null;
        if ($lease !== null) {
            $billingParty = $this->billingResolver->resolveForLease($lease, $request->category);
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $woNumber = $this->generateWorkorderNumber('REQ-' . $request->id);

            $stmt = $pdo->prepare(
                'INSERT INTO workorders
                    (number, customer_id, branch_id, unit_id, tenant_billable_party,
                     status, priority, internal_notes, customer_notes,
                     created_at, updated_at)
                 VALUES
                    (:number, :customer_id, :branch_id, :unit_id, :billing_party,
                     :status, :priority, :internal_notes, :customer_notes,
                     NOW(), NOW())'
            );
            $stmt->execute([
                'number' => $woNumber,
                'customer_id' => (int) $tenant->company_id,
                'branch_id' => $branchId,
                'unit_id' => $request->unit_id,
                'billing_party' => $billingParty,
                'status' => Workorder::STATUS_PENDING,
                'priority' => $request->priority,
                'internal_notes' => sprintf(
                    "Created from tenant maintenance request #%d (category: %s).",
                    $request->id,
                    $request->category ?? 'uncategorized'
                ),
                'customer_notes' => $request->title
                    . ($request->description ? "\n\n" . $request->description : ''),
            ]);

            $workorderId = (int) $pdo->lastInsertId();

            $updated = $this->repository->markConverted($id, $workorderId, $user->id);
            if ($updated === null) {
                throw new RuntimeException('Failed to mark request as converted.');
            }

            $pdo->commit();

            return [
                'request' => self::toArray($updated),
                'workorder_id' => $workorderId,
                'workorder_number' => $woNumber,
                'tenant_billable_party' => $billingParty,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function requireTenant(User $user): Tenant
    {
        $tenant = $this->tenantRepository->findByPortalUserId($user->id);
        if ($tenant === null) {
            throw new InvalidArgumentException('No tenant profile is linked to this account.');
        }
        return $tenant;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchUnitsForTenant(int $tenantId): array
    {
        // Pull active leases for the tenant, then each unit, in one round-trip.
        // Operational policy: a tenant may have multiple active leases (e.g.,
        // a residence + a separate storage unit), so we don't LIMIT 1.
        $stmt = $this->connection->pdo()->prepare(
            'SELECT u.id, u.site_id, u.code, u.name, u.unit_type, u.floor,
                    u.status AS unit_status,
                    l.id AS lease_id, l.start_date, l.end_date,
                    l.billing_responsibility
               FROM tenant_leases l
               JOIN units u ON u.id = l.unit_id
              WHERE l.tenant_id = :tenant_id
                AND l.status = :active
                AND l.start_date <= CURDATE()
                AND (l.end_date IS NULL OR l.end_date >= CURDATE())
              ORDER BY u.code, u.id'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'active' => TenantLease::STATUS_ACTIVE,
        ]);

        return array_map(
            static fn (array $row) => [
                'id' => (int) $row['id'],
                'site_id' => (int) $row['site_id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'unit_type' => $row['unit_type'],
                'floor' => $row['floor'],
                'status' => $row['unit_status'],
                'lease' => [
                    'id' => (int) $row['lease_id'],
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'billing_responsibility' => $row['billing_responsibility'],
                ],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    private function generateWorkorderNumber(string $base): string
    {
        $candidate = 'WO-' . $base;
        $suffix = 1;
        while ($this->workorderNumberExists($candidate)) {
            $candidate = 'WO-' . $base . '-' . $suffix;
            $suffix++;
        }
        return $candidate;
    }

    private function workorderNumberExists(string $number): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT 1 FROM workorders WHERE number = :number LIMIT 1'
        );
        $stmt->execute(['number' => $number]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(TenantMaintenanceRequest $request): array
    {
        return [
            'id' => $request->id,
            'tenant_id' => $request->tenant_id,
            'unit_id' => $request->unit_id,
            'tenant_lease_id' => $request->tenant_lease_id,
            'category' => $request->category,
            'priority' => $request->priority,
            'status' => $request->status,
            'title' => $request->title,
            'description' => $request->description,
            'workorder_id' => $request->workorder_id,
            'triaged_at' => $request->triaged_at,
            'triaged_by' => $request->triaged_by,
            'converted_at' => $request->converted_at,
            'converted_by' => $request->converted_by,
            'declined_reason' => $request->declined_reason,
            'created_at' => $request->created_at,
            'updated_at' => $request->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantToArray(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'display_name' => $tenant->display_name,
            'entity_type' => $tenant->entity_type,
            'primary_email' => $tenant->primary_email,
            'primary_phone' => $tenant->primary_phone,
            'company_id' => $tenant->company_id,
            'status' => $tenant->status,
        ];
    }
}
