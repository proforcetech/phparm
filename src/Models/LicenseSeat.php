<?php

namespace App\Models;

/**
 * License pool for one customer's purchase of a software_asset — Phase 14 /
 * M9 of docs/woms-expansion-plan.md.
 *
 * `seats_owned` is the capacity; `seats_assigned` is a denormalized
 * usage counter that SoftwareInventoryService maintains inside the same
 * transaction as license_assignments inserts/updates. Over-allocation
 * checks are O(1) against this row.
 *
 * See migration 163_software_cmdb.sql.
 */
class LicenseSeat extends BaseModel
{
    public const TYPE_PERPETUAL = 'perpetual';
    public const TYPE_SUBSCRIPTION = 'subscription';
    public const TYPE_VOLUME = 'volume';
    public const TYPE_OEM = 'oem';
    public const TYPE_NAMED_USER = 'named_user';
    public const TYPE_CONCURRENT = 'concurrent';
    public const TYPE_DEVICE = 'device';
    public const TYPE_TRIAL = 'trial';

    public const ALLOWED_TYPES = [
        self::TYPE_PERPETUAL,
        self::TYPE_SUBSCRIPTION,
        self::TYPE_VOLUME,
        self::TYPE_OEM,
        self::TYPE_NAMED_USER,
        self::TYPE_CONCURRENT,
        self::TYPE_DEVICE,
        self::TYPE_TRIAL,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_SUSPENDED = 'suspended';

    public const ALLOWED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
        self::STATUS_SUSPENDED,
    ];

    public int $id = 0;
    public int $software_asset_id = 0;
    public int $customer_id = 0;
    public string $license_type = self::TYPE_SUBSCRIPTION;
    public ?string $license_key_ref = null;
    public ?string $vendor_name = null;
    public ?string $purchase_order_ref = null;
    public ?string $purchased_at = null;
    public ?string $expires_at = null;
    public int $seats_owned = 0;
    public int $seats_assigned = 0;
    public ?int $cost_per_seat_cents = null;
    public string $status = self::STATUS_ACTIVE;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function availableSeats(): int
    {
        return max(0, $this->seats_owned - $this->seats_assigned);
    }

    public function isOverAllocated(): bool
    {
        return $this->seats_assigned > $this->seats_owned;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
