<?php

namespace App\Models;

/**
 * Lease binding a tenant to a unit — Phase 12 of
 * docs/woms-expansion-plan.md. See migration
 * 157_property_management_tenants_units_leases.sql for the schema.
 *
 * `billing_responsibility` is the routing key consumed by the
 * TenantBillingResolver when a property-mgmt WO is converted to an invoice.
 * `maintenance_terms` is a JSON map of category → responsible_party for the
 * 'split' case (e.g., {"plumbing":"landlord","fixtures":"tenant"}).
 */
class TenantLease extends BaseModel
{
    public const BILLING_LANDLORD = 'landlord';
    public const BILLING_TENANT = 'tenant';
    public const BILLING_SPLIT = 'split';

    public const ALLOWED_BILLING_PARTIES = [
        self::BILLING_LANDLORD,
        self::BILLING_TENANT,
        self::BILLING_SPLIT,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_TERMINATED = 'terminated';
    public const STATUS_RENEWED = 'renewed';

    public const ALLOWED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_EXPIRED,
        self::STATUS_TERMINATED,
        self::STATUS_RENEWED,
    ];

    public int $id = 0;
    public int $tenant_id = 0;
    public int $unit_id = 0;
    public string $start_date = '';
    public ?string $end_date = null;
    public ?float $monthly_rent = null;
    public ?float $deposit_amount = null;
    public string $billing_responsibility = self::BILLING_LANDLORD;
    public ?array $maintenance_terms = null;
    public string $status = self::STATUS_ACTIVE;
    public ?string $terms = null;
    public ?string $notes = null;
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
