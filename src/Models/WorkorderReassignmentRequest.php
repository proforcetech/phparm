<?php

namespace App\Models;

/**
 * Phase 10.4 — A request to swap the primary tech assigned to a work order.
 *
 * Lifecycle:
 *   pending    → tech (or dispatcher acting on tech's behalf) filed the
 *                request; manager hasn't acted yet
 *   approved   → manager said yes but hasn't picked the replacement yet
 *   declined   → manager said no with a reason
 *   cancelled  → requestor withdrew before manager acted (or after approval
 *                but before fulfilment, e.g. the tech's emergency cleared up)
 *   fulfilled  → manager picked a replacement, the workorder row was updated,
 *                and a row was appended to workorder_reassignment_history
 *
 * Approved + fulfilled are split because dispatch reality says "yes we can
 * reassign this" often happens before "and here's the replacement" — the
 * fulfilment step is the actual workorder.assigned_technician_id mutation.
 *
 * reassignment_reason drives reporting and downstream policy:
 *   emergency_unavailable — tech has a personal/medical emergency
 *   skill_mismatch        — wrong specialty for the job
 *   scheduling_conflict   — double-booked across calls/jobs
 *   safety_concern        — tech can't safely complete (equipment/site issue)
 *   customer_request      — customer asked for someone else
 *   other                 — free-text reason field carries the detail
 */
class WorkorderReassignmentRequest extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_DECLINED,
        self::STATUS_CANCELLED,
        self::STATUS_FULFILLED,
    ];

    /** @var array<string, array<int, string>> */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_APPROVED => [self::STATUS_PENDING],
        self::STATUS_DECLINED => [self::STATUS_PENDING],
        self::STATUS_FULFILLED => [self::STATUS_APPROVED],
        self::STATUS_CANCELLED => [self::STATUS_PENDING, self::STATUS_APPROVED],
    ];

    public const REASON_EMERGENCY = 'emergency_unavailable';
    public const REASON_SKILL_MISMATCH = 'skill_mismatch';
    public const REASON_SCHEDULING_CONFLICT = 'scheduling_conflict';
    public const REASON_SAFETY_CONCERN = 'safety_concern';
    public const REASON_CUSTOMER_REQUEST = 'customer_request';
    public const REASON_OTHER = 'other';

    public const REASONS = [
        self::REASON_EMERGENCY,
        self::REASON_SKILL_MISMATCH,
        self::REASON_SCHEDULING_CONFLICT,
        self::REASON_SAFETY_CONCERN,
        self::REASON_CUSTOMER_REQUEST,
        self::REASON_OTHER,
    ];

    public const URGENCY_LOW = 'low';
    public const URGENCY_NORMAL = 'normal';
    public const URGENCY_HIGH = 'high';
    public const URGENCY_URGENT = 'urgent';

    public const URGENCIES = [
        self::URGENCY_LOW,
        self::URGENCY_NORMAL,
        self::URGENCY_HIGH,
        self::URGENCY_URGENT,
    ];

    public int $id = 0;
    public int $workorder_id = 0;
    public int $requested_by_user_id = 0;
    public ?int $current_assignee_user_id = null;
    public ?int $proposed_assignee_user_id = null;
    public string $reassignment_reason = self::REASON_OTHER;
    public string $reason = '';
    public string $urgency = self::URGENCY_NORMAL;
    public string $status = self::STATUS_PENDING;
    public ?string $requested_at = null;
    public ?int $approved_by_user_id = null;
    public ?string $approved_at = null;
    public ?int $declined_by_user_id = null;
    public ?string $declined_at = null;
    public ?int $cancelled_by_user_id = null;
    public ?string $cancelled_at = null;
    public ?int $fulfilled_by_user_id = null;
    public ?string $fulfilled_at = null;
    public ?int $new_assignee_user_id = null;
    public ?string $rejection_reason = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
