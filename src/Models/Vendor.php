<?php

namespace App\Models;

/**
 * Phase 18 / S5 — first-class procurement vendor.
 *
 * Distinct from the older `inventory_lookups` rows where type='vendors'
 * (which are kept for the parts-supplier dropdown). New procurement code
 * lives against this table.
 */
class Vendor extends BaseModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_BLOCKED = 'blocked';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_BLOCKED,
    ];

    public int $id = 0;
    public string $name = '';
    public ?string $code = null;
    public string $status = self::STATUS_ACTIVE;
    public ?string $primary_contact_name = null;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $website = null;
    public ?string $street = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $postal_code = null;
    public ?string $country = null;
    public ?string $payment_terms = null;
    public ?string $currency = 'USD';
    public ?string $tax_id = null;
    public bool $requires_1099 = false;
    public bool $is_consignment_partner = false;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
