<?php

namespace App\Models;

/**
 * Phase 10.2 — A customer-approved revision to an in-flight work order.
 *
 * Lifecycle:
 *   draft             → tech is composing line items; nothing visible to customer yet
 *   pending_approval  → submitted to customer; awaiting yes/no
 *   approved          → customer signed off; CO totals stand alongside the WO
 *   rejected          → customer declined; CO is dead-letter (cannot be revived)
 *   cancelled         → tech withdrew the request before customer saw it
 *
 * sequence_number is per-workorder so the customer sees CO #1, CO #2 in
 * stable, predictable numbering even as drafts are deleted/re-added.
 *
 * approval_method records HOW the customer authorized: a verbal yes captured
 * by the tech (verbal_signed_off), an in-shop signature (signed_inline), a
 * portal click-through (portal_approval), an email reply (email). This drives
 * what the printable CO PDF shows in its signature block.
 */
class WorkorderChangeOrder extends BaseModel
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** @var array<string, array<int, string>> */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING_APPROVAL => [self::STATUS_DRAFT],
        self::STATUS_APPROVED => [self::STATUS_PENDING_APPROVAL],
        self::STATUS_REJECTED => [self::STATUS_PENDING_APPROVAL],
        self::STATUS_CANCELLED => [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL],
    ];

    public const REASON_DISCOVERED = 'discovered_condition';
    public const REASON_CUSTOMER_REQUEST = 'customer_request';
    public const REASON_PARTS_UNAVAILABLE = 'parts_unavailable';
    public const REASON_SCOPE_CLARIFICATION = 'scope_clarification';
    public const REASON_OTHER = 'other';

    public const REASONS = [
        self::REASON_DISCOVERED,
        self::REASON_CUSTOMER_REQUEST,
        self::REASON_PARTS_UNAVAILABLE,
        self::REASON_SCOPE_CLARIFICATION,
        self::REASON_OTHER,
    ];

    public const APPROVAL_VERBAL = 'verbal_signed_off';
    public const APPROVAL_INLINE = 'signed_inline';
    public const APPROVAL_PORTAL = 'portal_approval';
    public const APPROVAL_EMAIL = 'email';

    public const APPROVAL_METHODS = [
        self::APPROVAL_VERBAL,
        self::APPROVAL_INLINE,
        self::APPROVAL_PORTAL,
        self::APPROVAL_EMAIL,
    ];

    public int $id = 0;
    public int $workorder_id = 0;
    public int $sequence_number = 1;
    public string $title = '';
    public ?string $description = null;
    public string $reason_code = self::REASON_OTHER;
    public string $status = self::STATUS_DRAFT;
    public int $subtotal_cents = 0;
    public int $tax_cents = 0;
    public int $total_cents = 0;
    public ?int $requested_by_user_id = null;
    public ?string $requested_at = null;
    public ?int $approved_by_user_id = null;
    public ?string $approved_at = null;
    public ?string $rejected_at = null;
    public ?string $cancelled_at = null;
    public ?string $applied_at = null;
    public ?string $approval_method = null;
    public ?string $rejection_reason = null;
    public ?string $customer_signature_name = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /** @var array<int, WorkorderChangeOrderItem> hydrated lazily by service */
    public array $items = [];
}
