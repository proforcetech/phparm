<?php

namespace App\Models;

/**
 * Phase 10.1 — Subcontractor vendor master record.
 *
 * A subcontractor is an external vendor (electrician, plumber, HVAC tech, etc.)
 * the shop dispatches when in-house staff lack the right trade or capacity.
 * status governs assignability — only 'active' subs can be assigned to new
 * work orders. insurance_expires_at lets the UI flag subs whose certificate
 * of insurance is past due so dispatch doesn't accidentally pull them.
 */
class Subcontractor extends BaseModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_SUSPENDED,
    ];

    public int $id = 0;
    public string $company_name = '';
    public ?string $contact_name = null;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $address_line1 = null;
    public ?string $address_line2 = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $postal_code = null;
    public ?string $country = null;
    public ?string $tax_id = null;
    public ?string $payment_terms = null;
    public ?int $hourly_rate_cents = null;
    public string $status = self::STATUS_ACTIVE;
    public ?string $insurance_expires_at = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /** @var array<int, int> Division IDs this sub is approved for. Hydrated lazily by service. */
    public array $division_ids = [];
}
