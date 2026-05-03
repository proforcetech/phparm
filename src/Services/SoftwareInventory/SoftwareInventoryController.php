<?php

namespace App\Services\SoftwareInventory;

use App\Models\InstalledSoftware;
use App\Models\LicenseAssignment;
use App\Models\LicenseSeat;
use App\Models\SoftwareAsset;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/software-* — Phase 14 / M9 of
 * docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   software_inventory.view   — read catalog, pools, assignments, compliance
 *   software_inventory.manage — write (create/update/delete software titles,
 *                               license pools, assignments, installs)
 *
 * All write paths route through SoftwareInventoryService so the denormalized
 * seats_assigned counter stays consistent with license_assignments and the
 * audit ledger captures every mutation.
 */
class SoftwareInventoryController
{
    public function __construct(
        private readonly SoftwareAssetRepository $softwareAssets,
        private readonly LicenseSeatRepository $seats,
        private readonly LicenseAssignmentRepository $assignments,
        private readonly InstalledSoftwareRepository $installs,
        private readonly SoftwareInventoryService $service,
        private readonly AccessGate $gate,
    ) {
    }

    // ---- software_assets ---------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{titles: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function indexTitles(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'software_inventory.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->softwareAssets->list($filters, $perPage, $offset);
        $total = $this->softwareAssets->count($filters);

        return [
            'titles' => array_map([self::class, 'softwareAssetToArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showTitle(User $user, int $id): array
    {
        $this->gate->assert($user, 'software_inventory.view');

        $row = $this->softwareAssets->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("software_asset {$id} not found");
        }
        return self::softwareAssetToArray($row);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function storeTitle(User $user, array $body): array
    {
        $this->gate->assert($user, 'software_inventory.manage');

        $row = $this->service->createSoftwareAsset($body, (int) $user->id);
        return self::softwareAssetToArray($row);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateTitle(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'software_inventory.manage');

        $allowed = ['customer_id', 'publisher', 'title', 'version', 'edition',
                    'category', 'platform', 'sku', 'description', 'is_active'];
        $updates = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $body)) {
                $updates[$key] = $body[$key];
            }
        }
        $row = $this->service->updateSoftwareAsset($id, $updates, (int) $user->id);
        return self::softwareAssetToArray($row);
    }

    public function destroyTitle(User $user, int $id): void
    {
        $this->gate->assert($user, 'software_inventory.manage');

        if ($this->softwareAssets->findById($id) === null) {
            throw new InvalidArgumentException("software_asset {$id} not found");
        }
        $this->softwareAssets->delete($id);
    }

    // ---- license_seats -----------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{pools: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function indexPools(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'software_inventory.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->seats->list($filters, $perPage, $offset);
        $total = $this->seats->count($filters);

        return [
            'pools' => array_map([self::class, 'licenseSeatToArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showPool(User $user, int $id): array
    {
        $this->gate->assert($user, 'software_inventory.view');

        $row = $this->seats->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("license_seat {$id} not found");
        }
        return self::licenseSeatToArray($row);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function storePool(User $user, array $body): array
    {
        $this->gate->assert($user, 'software_inventory.manage');

        $row = $this->service->createSeat($body, (int) $user->id);
        return self::licenseSeatToArray($row);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updatePool(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'software_inventory.manage');

        $allowed = ['license_type', 'license_key_ref', 'vendor_name',
                    'purchase_order_ref', 'purchased_at', 'expires_at',
                    'seats_owned', 'cost_per_seat_cents', 'status', 'notes'];
        $updates = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $body)) {
                $updates[$key] = $body[$key];
            }
        }
        $row = $this->service->updateSeat($id, $updates, (int) $user->id);
        return self::licenseSeatToArray($row);
    }

    public function destroyPool(User $user, int $id): void
    {
        $this->gate->assert($user, 'software_inventory.manage');

        $row = $this->seats->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("license_seat {$id} not found");
        }
        if ($row->seats_assigned > 0) {
            throw new InvalidArgumentException(
                "Cannot delete license_seat {$id}: {$row->seats_assigned} active assignments. Unassign first."
            );
        }
        $this->seats->delete($id);
    }

    // ---- license_assignments ----------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{assignments: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function indexAssignments(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'software_inventory.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->assignments->list($filters, $perPage, $offset);
        $total = $this->assignments->count($filters);

        return [
            'assignments' => array_map([self::class, 'licenseAssignmentToArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function assign(User $user, array $body): array
    {
        $this->gate->assert($user, 'software_inventory.manage');

        $seatId = (int) ($body['license_seat_id'] ?? 0);
        $assigneeType = trim((string) ($body['assignee_type'] ?? ''));
        $assigneeId = (int) ($body['assignee_id'] ?? 0);
        if ($seatId <= 0 || $assigneeType === '' || $assigneeId <= 0) {
            throw new InvalidArgumentException(
                'license_seat_id, assignee_type, and assignee_id are required'
            );
        }

        $row = $this->service->assign(
            $seatId,
            $assigneeType,
            $assigneeId,
            (int) $user->id,
            ['notes' => $body['notes'] ?? null]
        );
        return self::licenseAssignmentToArray($row);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function unassign(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'software_inventory.manage');

        $row = $this->service->unassign(
            $id,
            (int) $user->id,
            isset($body['reason']) ? trim((string) $body['reason']) : null
        );
        return self::licenseAssignmentToArray($row);
    }

    // ---- installed_software -----------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{installs: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function indexInstalls(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'software_inventory.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->installs->list($filters, $perPage, $offset);
        $total = $this->installs->count($filters);

        return [
            'installs' => array_map([self::class, 'installedSoftwareToArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function recordInstall(User $user, array $body): array
    {
        $this->gate->assert($user, 'software_inventory.manage');

        $siteAssetId = (int) ($body['site_asset_id'] ?? 0);
        $softwareAssetId = (int) ($body['software_asset_id'] ?? 0);
        if ($siteAssetId <= 0 || $softwareAssetId <= 0) {
            throw new InvalidArgumentException(
                'site_asset_id and software_asset_id are required'
            );
        }

        $row = $this->service->recordInstall($body, (int) $user->id);
        return self::installedSoftwareToArray($row);
    }

    public function removeInstall(User $user, int $id): void
    {
        $this->gate->assert($user, 'software_inventory.manage');

        $this->service->removeInstall($id, (int) $user->id);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function linkInstall(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'software_inventory.manage');

        $assignmentId = (int) ($body['license_assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            throw new InvalidArgumentException('license_assignment_id is required');
        }

        $row = $this->service->linkInstallToAssignment($id, $assignmentId, (int) $user->id);
        return self::installedSoftwareToArray($row);
    }

    // ---- compliance views --------------------------------------------------

    /**
     * @return array{summary: array<int, array<string, mixed>>,
     *               over_allocated: array<int, array<string, mixed>>,
     *               unlicensed_installs: array<int, array<string, mixed>>}
     */
    public function compliance(User $user, ?int $customerId = null): array
    {
        $this->gate->assert($user, 'software_inventory.view');

        return [
            'summary' => $this->service->complianceSummary($customerId),
            'over_allocated' => array_map(
                [self::class, 'licenseSeatToArray'],
                $this->service->overAllocatedPools($customerId)
            ),
            'unlicensed_installs' => array_map(
                [self::class, 'installedSoftwareToArray'],
                $this->service->unlicensedInstalls($customerId)
            ),
        ];
    }

    /**
     * @return array{fixes: array<int, array{seat_id: int, before: int, after: int}>}
     */
    public function reconcile(User $user): array
    {
        $this->gate->assert($user, 'software_inventory.manage');

        return ['fixes' => $this->service->reconcileSeatCounters()];
    }

    // ---- serializers -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function softwareAssetToArray(SoftwareAsset $row): array
    {
        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'publisher' => $row->publisher,
            'title' => $row->title,
            'version' => $row->version,
            'edition' => $row->edition,
            'category' => $row->category,
            'platform' => $row->platform,
            'sku' => $row->sku,
            'description' => $row->description,
            'is_active' => (bool) $row->is_active,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function licenseSeatToArray(LicenseSeat $row): array
    {
        return [
            'id' => $row->id,
            'software_asset_id' => $row->software_asset_id,
            'customer_id' => $row->customer_id,
            'license_type' => $row->license_type,
            'license_key_ref' => $row->license_key_ref,
            'vendor_name' => $row->vendor_name,
            'purchase_order_ref' => $row->purchase_order_ref,
            'purchased_at' => $row->purchased_at,
            'expires_at' => $row->expires_at,
            'seats_owned' => $row->seats_owned,
            'seats_assigned' => $row->seats_assigned,
            'seats_available' => $row->availableSeats(),
            'over_allocated' => $row->isOverAllocated(),
            'cost_per_seat_cents' => $row->cost_per_seat_cents,
            'status' => $row->status,
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function licenseAssignmentToArray(LicenseAssignment $row): array
    {
        return [
            'id' => $row->id,
            'license_seat_id' => $row->license_seat_id,
            'assignee_type' => $row->assignee_type,
            'assignee_user_id' => $row->assignee_user_id,
            'assignee_site_asset_id' => $row->assignee_site_asset_id,
            'assigned_at' => $row->assigned_at,
            'assigned_by_user_id' => $row->assigned_by_user_id,
            'unassigned_at' => $row->unassigned_at,
            'unassigned_by_user_id' => $row->unassigned_by_user_id,
            'unassign_reason' => $row->unassign_reason,
            'is_active' => $row->isActive(),
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function installedSoftwareToArray(InstalledSoftware $row): array
    {
        return [
            'id' => $row->id,
            'site_asset_id' => $row->site_asset_id,
            'software_asset_id' => $row->software_asset_id,
            'installed_version' => $row->installed_version,
            'installed_at' => $row->installed_at,
            'detected_at' => $row->detected_at,
            'source' => $row->source,
            'license_assignment_id' => $row->license_assignment_id,
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
