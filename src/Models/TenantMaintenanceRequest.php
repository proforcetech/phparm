<?php

namespace App\Models;

/**
 * Tenant-side maintenance request — Phase 12 of docs/woms-expansion-plan.md.
 *
 * The intake doc a tenant submits when something in their unit needs
 * attention. This is NOT a workorder; staff triage and convert it. On
 * conversion, a workorder is created and pinned to the unit, which triggers
 * the existing TenantBillingResolver snapshot path (see migrations 157/158
 * and WorkorderController::updateUnit) so the eventual invoice routes to the
 * right party per the lease's billing terms.
 *
 * See migration 159_tenant_maintenance_requests.sql for the schema.
 */
class TenantMaintenanceRequest extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_TRIAGED = 'triaged';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_TRIAGED,
        self::STATUS_CONVERTED,
        self::STATUS_DECLINED,
        self::STATUS_CANCELLED,
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public int $id = 0;
    public int $tenant_id = 0;
    public int $unit_id = 0;
    public ?int $tenant_lease_id = null;
    public ?string $category = null;
    public string $priority = 'normal';
    public string $status = self::STATUS_PENDING;
    public string $title = '';
    public ?string $description = null;
    public ?int $workorder_id = null;
    public ?string $triaged_at = null;
    public ?int $triaged_by = null;
    public ?string $converted_at = null;
    public ?int $converted_by = null;
    public ?string $declined_reason = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
