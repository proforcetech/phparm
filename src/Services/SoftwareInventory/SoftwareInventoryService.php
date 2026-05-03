<?php

namespace App\Services\SoftwareInventory;

use App\Database\Connection;
use App\Models\InstalledSoftware;
use App\Models\LicenseAssignment;
use App\Models\LicenseSeat;
use App\Models\SoftwareAsset;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Phase 14 / M9 of docs/woms-expansion-plan.md — software / license CMDB
 * write coordinator.
 *
 * The repositories are happy to read on their own, but writes to
 * `license_assignments` MUST go through this service so that the
 * denormalized `license_seats.seats_assigned` counter stays consistent and
 * the over-allocation guard fires before a seat is consumed.
 *
 * Concurrency: assign() opens a transaction and re-loads the seat with
 * SELECT ... FOR UPDATE before the capacity check. This serializes
 * concurrent assigners against the same pool so two requests cannot both
 * pass a "0 seats remaining" check and then both insert.
 *
 * Audit: every assign / unassign / seat-mutation writes an `audit_logs`
 * row keyed by entity_type='license_seat' or 'license_assignment' so the
 * compliance UI can render the full ledger.
 */
class SoftwareInventoryService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SoftwareAssetRepository $softwareAssets,
        private readonly LicenseSeatRepository $seats,
        private readonly LicenseAssignmentRepository $assignments,
        private readonly InstalledSoftwareRepository $installs,
        private readonly AuditLogger $audit,
    ) {
    }

    // ---- software_assets passthrough (audit-wrapped) -----------------------

    /**
     * @param array<string, mixed> $data
     */
    public function createSoftwareAsset(array $data, ?int $actorId = null): SoftwareAsset
    {
        $row = $this->softwareAssets->create($data);
        $this->audit->log(new AuditEntry(
            'software_asset.created',
            'software_asset',
            (string) $row->id,
            $actorId,
            [
                'customer_id' => $row->customer_id,
                'publisher' => $row->publisher,
                'title' => $row->title,
                'version' => $row->version,
            ]
        ));
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateSoftwareAsset(int $id, array $data, ?int $actorId = null): SoftwareAsset
    {
        $row = $this->softwareAssets->update($id, $data);
        $this->audit->log(new AuditEntry(
            'software_asset.updated',
            'software_asset',
            (string) $row->id,
            $actorId,
            ['changed' => array_keys($data)]
        ));
        return $row;
    }

    // ---- license_seats passthrough (audit-wrapped) -------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function createSeat(array $data, ?int $actorId = null): LicenseSeat
    {
        $software = $this->softwareAssets->findById((int) ($data['software_asset_id'] ?? 0));
        if ($software === null) {
            throw new InvalidArgumentException('software_asset_id does not exist');
        }
        $customerId = (int) ($data['customer_id'] ?? 0);
        if ($software->customer_id !== null && $software->customer_id !== $customerId) {
            throw new InvalidArgumentException(
                'customer_id must match software_assets.customer_id when the catalog row is customer-scoped'
            );
        }

        $row = $this->seats->create($data);
        $this->audit->log(new AuditEntry(
            'license_seat.created',
            'license_seat',
            (string) $row->id,
            $actorId,
            [
                'customer_id' => $row->customer_id,
                'software_asset_id' => $row->software_asset_id,
                'seats_owned' => $row->seats_owned,
                'license_type' => $row->license_type,
                'expires_at' => $row->expires_at,
            ]
        ));
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateSeat(int $id, array $data, ?int $actorId = null): LicenseSeat
    {
        $before = $this->seats->findById($id);
        if ($before === null) {
            throw new RuntimeException("license_seat {$id} not found");
        }
        $row = $this->seats->update($id, $data);
        $this->audit->log(new AuditEntry(
            'license_seat.updated',
            'license_seat',
            (string) $row->id,
            $actorId,
            [
                'changed' => array_keys($data),
                'seats_owned_before' => $before->seats_owned,
                'seats_owned_after' => $row->seats_owned,
            ]
        ));
        return $row;
    }

    // ---- assignment lifecycle (capacity-guarded) ---------------------------

    /**
     * Assign one seat from a pool to a user OR a site_asset. Refuses if the
     * pool is at capacity, expired, or not active. Idempotent against
     * duplicate (seat, assignee) pairs — returns the existing active row if
     * the assignment already exists.
     *
     * @param array{notes?: string} $opts
     */
    public function assign(
        int $licenseSeatId,
        string $assigneeType,
        int $assigneeId,
        ?int $actorId = null,
        array $opts = []
    ): LicenseAssignment {
        if (!in_array($assigneeType, LicenseAssignment::ALLOWED_ASSIGNEE_TYPES, true)) {
            throw new InvalidArgumentException(
                'Invalid assignee_type. Allowed: ' . implode(', ', LicenseAssignment::ALLOWED_ASSIGNEE_TYPES)
            );
        }
        if ($assigneeId <= 0) {
            throw new InvalidArgumentException('assignee_id must be > 0');
        }

        $pdo = $this->connection->pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            $seat = $this->seats->findByIdForUpdate($licenseSeatId);
            if ($seat === null) {
                throw new InvalidArgumentException("license_seat {$licenseSeatId} not found");
            }
            if ($seat->status !== LicenseSeat::STATUS_ACTIVE) {
                throw new InvalidArgumentException(
                    "Cannot assign from a {$seat->status} pool"
                );
            }
            if ($seat->expires_at !== null && $seat->expires_at < date('Y-m-d')) {
                throw new InvalidArgumentException(
                    "license_seat {$licenseSeatId} expired on {$seat->expires_at}"
                );
            }

            $existing = $this->assignments->findActiveForAssignee(
                $licenseSeatId,
                $assigneeType,
                $assigneeId
            );
            if ($existing !== null) {
                if ($startedTx) {
                    $pdo->commit();
                }
                return $existing;
            }

            if ($seat->seats_assigned >= $seat->seats_owned) {
                throw new InvalidArgumentException(
                    "license_seat {$licenseSeatId} has no available seats "
                    . "({$seat->seats_assigned}/{$seat->seats_owned})"
                );
            }

            $payload = [
                'license_seat_id' => $licenseSeatId,
                'assignee_type' => $assigneeType,
                'assignee_user_id' => $assigneeType === LicenseAssignment::ASSIGNEE_USER ? $assigneeId : null,
                'assignee_site_asset_id' => $assigneeType === LicenseAssignment::ASSIGNEE_SITE_ASSET ? $assigneeId : null,
                'assigned_by_user_id' => $actorId,
                'notes' => $opts['notes'] ?? null,
            ];
            $assignment = $this->assignments->create($payload);
            $this->seats->incrementAssigned($licenseSeatId);

            if ($startedTx) {
                $pdo->commit();
            }

            $this->audit->log(new AuditEntry(
                'license_assignment.created',
                'license_assignment',
                (string) $assignment->id,
                $actorId,
                [
                    'license_seat_id' => $licenseSeatId,
                    'assignee_type' => $assigneeType,
                    'assignee_id' => $assigneeId,
                    'seats_after' => $seat->seats_assigned + 1,
                    'seats_owned' => $seat->seats_owned,
                ]
            ));
            return $assignment;
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function unassign(
        int $licenseAssignmentId,
        ?int $actorId = null,
        ?string $reason = null
    ): LicenseAssignment {
        $assignment = $this->assignments->findById($licenseAssignmentId);
        if ($assignment === null) {
            throw new InvalidArgumentException("license_assignment {$licenseAssignmentId} not found");
        }
        if (!$assignment->isActive()) {
            return $assignment;
        }

        $pdo = $this->connection->pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            $updated = $this->assignments->markUnassigned($licenseAssignmentId, $actorId, $reason);
            $this->seats->decrementAssigned($assignment->license_seat_id);
            $this->installs->clearAssignment($licenseAssignmentId);

            if ($startedTx) {
                $pdo->commit();
            }

            $this->audit->log(new AuditEntry(
                'license_assignment.unassigned',
                'license_assignment',
                (string) $licenseAssignmentId,
                $actorId,
                [
                    'license_seat_id' => $assignment->license_seat_id,
                    'assignee_type' => $assignment->assignee_type,
                    'assignee_id' => $assignment->assignee_user_id ?? $assignment->assignee_site_asset_id,
                    'reason' => $reason,
                ]
            ));
            return $updated;
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---- installed_software helpers ---------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function recordInstall(array $data, ?int $actorId = null): InstalledSoftware
    {
        $existing = $this->installs->findByPair(
            (int) ($data['site_asset_id'] ?? 0),
            (int) ($data['software_asset_id'] ?? 0)
        );
        if ($existing !== null) {
            $row = $this->installs->update($existing->id, [
                'installed_version' => $data['installed_version'] ?? $existing->installed_version,
                'detected_at' => $data['detected_at'] ?? date('Y-m-d H:i:s'),
                'source' => $data['source'] ?? $existing->source,
                'notes' => $data['notes'] ?? $existing->notes,
            ]);
            $this->audit->log(new AuditEntry(
                'installed_software.updated',
                'installed_software',
                (string) $row->id,
                $actorId,
                ['site_asset_id' => $row->site_asset_id, 'software_asset_id' => $row->software_asset_id]
            ));
            return $row;
        }
        $row = $this->installs->create($data);
        $this->audit->log(new AuditEntry(
            'installed_software.created',
            'installed_software',
            (string) $row->id,
            $actorId,
            ['site_asset_id' => $row->site_asset_id, 'software_asset_id' => $row->software_asset_id]
        ));
        return $row;
    }

    public function removeInstall(int $installId, ?int $actorId = null): void
    {
        $existing = $this->installs->findById($installId);
        if ($existing === null) {
            return;
        }
        $this->installs->delete($installId);
        $this->audit->log(new AuditEntry(
            'installed_software.removed',
            'installed_software',
            (string) $installId,
            $actorId,
            [
                'site_asset_id' => $existing->site_asset_id,
                'software_asset_id' => $existing->software_asset_id,
            ]
        ));
    }

    /**
     * Link an existing installed_software row to a license_assignment.
     * Useful when an install was discovered before the licensing was
     * sorted out and a seat is later assigned to the host.
     */
    public function linkInstallToAssignment(
        int $installId,
        int $licenseAssignmentId,
        ?int $actorId = null
    ): InstalledSoftware {
        $assignment = $this->assignments->findById($licenseAssignmentId);
        if ($assignment === null || !$assignment->isActive()) {
            throw new InvalidArgumentException(
                "license_assignment {$licenseAssignmentId} is missing or unassigned"
            );
        }
        $row = $this->installs->update($installId, [
            'license_assignment_id' => $licenseAssignmentId,
        ]);
        $this->audit->log(new AuditEntry(
            'installed_software.linked',
            'installed_software',
            (string) $installId,
            $actorId,
            ['license_assignment_id' => $licenseAssignmentId]
        ));
        return $row;
    }

    // ---- compliance views --------------------------------------------------

    /**
     * Snapshot of license-pool health for a customer (or all customers).
     *
     * @return array<int, array<string, mixed>>
     */
    public function complianceSummary(?int $customerId = null): array
    {
        $filters = $customerId !== null ? ['customer_id' => $customerId] : [];
        $allSeats = $this->seats->list($filters, 1000, 0);

        $rows = [];
        foreach ($allSeats as $seat) {
            $rows[] = [
                'license_seat_id' => $seat->id,
                'software_asset_id' => $seat->software_asset_id,
                'customer_id' => $seat->customer_id,
                'license_type' => $seat->license_type,
                'status' => $seat->status,
                'seats_owned' => $seat->seats_owned,
                'seats_assigned' => $seat->seats_assigned,
                'seats_available' => $seat->availableSeats(),
                'over_allocated' => $seat->isOverAllocated(),
                'expires_at' => $seat->expires_at,
                'expires_within_30d' => $seat->expires_at !== null
                    && $seat->expires_at <= date('Y-m-d', strtotime('+30 days')),
            ];
        }
        return $rows;
    }

    /**
     * Pools whose seats_assigned > seats_owned. Should always be empty
     * unless seats_owned was lowered after assignments existed (the
     * repository guards against this) or the counter drifted.
     *
     * @return array<int, LicenseSeat>
     */
    public function overAllocatedPools(?int $customerId = null): array
    {
        return $this->seats->listOverAllocated($customerId);
    }

    /**
     * Installs without a license_assignment — the unlicensed-software
     * compliance feed.
     *
     * @return array<int, InstalledSoftware>
     */
    public function unlicensedInstalls(?int $customerId = null, int $limit = 500): array
    {
        return $this->installs->listUnlicensed($customerId, $limit);
    }

    /**
     * Reconcile the denormalized seats_assigned counter against the actual
     * count of active license_assignments. Returns the rows that needed a
     * fix. Intended for an admin "rebuild counters" action — never call
     * during normal request flow.
     *
     * @return array<int, array{seat_id: int, before: int, after: int}>
     */
    public function reconcileSeatCounters(): array
    {
        $fixes = [];
        $allSeats = $this->seats->list([], 1000, 0);
        foreach ($allSeats as $seat) {
            $actual = $this->assignments->countActiveForSeat($seat->id);
            if ($actual !== $seat->seats_assigned) {
                $delta = $actual - $seat->seats_assigned;
                if ($delta > 0) {
                    $this->seats->incrementAssigned($seat->id, $delta);
                } else {
                    $this->seats->decrementAssigned($seat->id, abs($delta));
                }
                $fixes[] = [
                    'seat_id' => $seat->id,
                    'before' => $seat->seats_assigned,
                    'after' => $actual,
                ];
            }
        }
        if ($fixes !== []) {
            $this->audit->log(new AuditEntry(
                'license_seat.counters_reconciled',
                'license_seat',
                '0',
                null,
                ['fixes' => $fixes]
            ));
        }
        return $fixes;
    }
}
