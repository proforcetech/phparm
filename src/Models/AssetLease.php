<?php

namespace App\Models;

/**
 * Lease record for an installed asset — Phase 13 of
 * docs/woms-expansion-plan.md (Must M3).
 *
 * Lessor-facing: WE lease the asset FROM `lessor_name`. The asset itself is
 * tracked in `site_assets`; this row carries the lease terms (payment, end
 * date, residual, mileage cap for fleet) and the per-row alert-sent
 * timestamps that make the 90/60/30/0-day expiry worker idempotent.
 *
 * `end_of_lease_decision` is set when the customer chooses how to close the
 * lease at expiry (renew / buyout / return / replace) — this drives the
 * downstream workflow handed off to acquisition (replace) or decommission
 * (return).
 *
 * See migration 160_asset_lease_lifecycle.sql for the schema.
 */
class AssetLease extends BaseModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING_RENEWAL = 'pending_renewal';
    public const STATUS_RENEWED = 'renewed';
    public const STATUS_BUYOUT_PENDING = 'buyout_pending';
    public const STATUS_BOUGHT_OUT = 'bought_out';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_TERMINATED = 'terminated';

    public const ALLOWED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PENDING_RENEWAL,
        self::STATUS_RENEWED,
        self::STATUS_BUYOUT_PENDING,
        self::STATUS_BOUGHT_OUT,
        self::STATUS_RETURNED,
        self::STATUS_EXPIRED,
        self::STATUS_TERMINATED,
    ];

    public const DECISION_RENEW = 'renew';
    public const DECISION_BUYOUT = 'buyout';
    public const DECISION_RETURN = 'return';
    public const DECISION_REPLACE = 'replace';

    public const ALLOWED_DECISIONS = [
        self::DECISION_RENEW,
        self::DECISION_BUYOUT,
        self::DECISION_RETURN,
        self::DECISION_REPLACE,
    ];

    public const SCHEDULE_MONTHLY = 'monthly';
    public const SCHEDULE_QUARTERLY = 'quarterly';
    public const SCHEDULE_ANNUAL = 'annual';
    public const SCHEDULE_CUSTOM = 'custom';

    public const ALLOWED_SCHEDULES = [
        self::SCHEDULE_MONTHLY,
        self::SCHEDULE_QUARTERLY,
        self::SCHEDULE_ANNUAL,
        self::SCHEDULE_CUSTOM,
    ];

    public int $id = 0;
    public int $site_asset_id = 0;
    public ?int $customer_id = null;
    public string $lessor_name = '';
    public ?string $lessor_contact = null;
    public ?string $lease_number = null;
    public string $start_date = '';
    public string $end_date = '';
    public ?int $monthly_payment_cents = null;
    public string $payment_schedule = self::SCHEDULE_MONTHLY;
    public ?int $mileage_cap = null;
    public ?int $current_mileage = null;
    public ?int $residual_value_cents = null;
    public ?int $buyout_price_cents = null;
    public string $status = self::STATUS_ACTIVE;
    public ?string $end_of_lease_decision = null;
    public ?string $decision_made_at = null;
    public ?int $decision_made_by = null;
    public ?string $alert_90d_sent_at = null;
    public ?string $alert_60d_sent_at = null;
    public ?string $alert_30d_sent_at = null;
    public ?string $alert_0d_sent_at = null;
    public ?string $terms = null;
    public ?string $notes = null;
    public ?array $attachments = null;
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
