<?php

namespace App\Models;

/**
 * Phase 18 / S5 — purchase order header.
 *
 * State machine (status):
 *   draft → sent → partial → received → closed
 *   {draft, sent, partial} → cancelled (only if no qty received)
 */
class PurchaseOrder extends BaseModel
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_PARTIAL,
        self::STATUS_RECEIVED,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
    ];

    public const KIND_INTERNAL = 'internal';
    public const KIND_CUSTOMER_BILLABLE = 'customer_billable';

    public const KINDS = [
        self::KIND_INTERNAL,
        self::KIND_CUSTOMER_BILLABLE,
    ];

    /**
     * Allowed status transitions. Used by PurchaseOrderService to gate
     * mutations — caller asks "can I move from X to Y?" and we look it up.
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SENT, self::STATUS_CANCELLED],
        self::STATUS_SENT => [self::STATUS_PARTIAL, self::STATUS_RECEIVED, self::STATUS_CANCELLED],
        self::STATUS_PARTIAL => [self::STATUS_RECEIVED, self::STATUS_CANCELLED],
        self::STATUS_RECEIVED => [self::STATUS_CLOSED],
    ];

    public int $id = 0;
    public string $po_number = '';
    public int $vendor_id = 0;
    public string $status = self::STATUS_DRAFT;
    public string $kind = self::KIND_INTERNAL;
    public ?int $customer_id = null;
    public ?int $site_id = null;
    public ?int $workorder_id = null;
    public string $currency = 'USD';
    public ?float $markup_pct = null;
    public bool $is_consigned = false;
    public int $subtotal_cents = 0;
    public int $tax_cents = 0;
    public int $shipping_cents = 0;
    public int $total_cents = 0;
    public ?string $notes = null;
    public ?string $ordered_at = null;
    public ?string $expected_at = null;
    public ?string $vendor_acknowledged_at = null;
    public ?int $vendor_acknowledged_via_token_id = null;
    public ?string $received_at = null;
    public ?string $closed_at = null;
    public ?string $cancelled_at = null;
    public ?string $cancel_reason = null;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
