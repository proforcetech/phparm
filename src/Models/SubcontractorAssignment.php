<?php

namespace App\Models;

/**
 * Phase 10.1 — A single subcontractor's engagement on one work order.
 *
 * State machine:
 *   pending      → assignment created, awaiting sub acknowledgement
 *   accepted     → sub confirmed they'll take the job
 *   declined     → sub passed; dispatch must reassign
 *   in_progress  → sub on-site / actively working
 *   completed    → work finished; final cost + rating recorded
 *   cancelled    → dispatch pulled the job back before completion
 *
 * The shop tracks both estimated and actual cost so finance can reconcile
 * any deviation against the invoice the sub submits. rating (1–5) plus
 * rating_notes feed a future scorecard view that helps managers pick the
 * best-performing sub when multiple are available.
 */
class SubcontractorAssignment extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Statuses from which a planner may transition to a given target.
     * Enforced in service layer to prevent illegal jumps (e.g. completed → pending).
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_ACCEPTED => [self::STATUS_PENDING],
        self::STATUS_DECLINED => [self::STATUS_PENDING],
        self::STATUS_IN_PROGRESS => [self::STATUS_PENDING, self::STATUS_ACCEPTED],
        self::STATUS_COMPLETED => [self::STATUS_ACCEPTED, self::STATUS_IN_PROGRESS],
        self::STATUS_CANCELLED => [
            self::STATUS_PENDING,
            self::STATUS_ACCEPTED,
            self::STATUS_IN_PROGRESS,
        ],
    ];

    public int $id = 0;
    public int $subcontractor_id = 0;
    public int $workorder_id = 0;
    public string $status = self::STATUS_PENDING;
    public ?string $description = null;
    public ?string $internal_notes = null;
    public ?int $estimated_cost_cents = null;
    public ?int $actual_cost_cents = null;
    public ?string $assigned_at = null;
    public ?string $accepted_at = null;
    public ?string $declined_at = null;
    public ?string $completed_at = null;
    public ?string $cancelled_at = null;
    public ?int $assigned_by_user_id = null;
    public ?int $rating = null;
    public ?string $rating_notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
