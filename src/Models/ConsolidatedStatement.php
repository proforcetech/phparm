<?php

namespace App\Models;

/**
 * Consolidated monthly statement — Phase 17 / M11 of
 * docs/woms-expansion-plan.md.
 *
 * Bundles N customer invoices that landed within [period_start, period_end]
 * into one billing envelope. The child invoices keep their own status, public
 * token, and audit history; this row is a roll-up so a chain customer
 * receives one statement instead of dozens.
 *
 * See migration 169_consolidated_monthly_statements.sql.
 */
class ConsolidatedStatement extends BaseModel
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALLOWED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_PAID,
        self::STATUS_PARTIAL,
        self::STATUS_CANCELLED,
    ];

    public int $id = 0;
    public string $number = '';
    public int $customer_id = 0;
    public string $period_start = '';
    public string $period_end = '';
    public string $status = self::STATUS_DRAFT;
    public float $subtotal = 0.0;
    public float $tax = 0.0;
    public float $total = 0.0;
    public float $amount_paid = 0.0;
    public float $balance_due = 0.0;
    public int $invoice_count = 0;
    public ?string $notes = null;
    public ?string $sent_at = null;
    public ?string $paid_at = null;
    public ?string $cancelled_at = null;
    public ?int $generated_by_user_id = null;
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
