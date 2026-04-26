<?php

namespace App\Models;

/**
 * Phase 10.5 — A scorer-generated suggestion for triaging a ticket.
 *
 * The suggestion captures what an analytical scorer (rule-based or AI-backed,
 * pluggable via TicketTriageScorerInterface) would recommend doing with the
 * ticket: priority, category, suggested assignee, plus diagnostic signals
 * (sentiment, urgency, model confidence) that human triagers can sanity-check
 * the recommendation against.
 *
 * Lifecycle:
 *   pending   → just generated, waiting for a triage agent to act
 *   accepted  → triager accepted the suggestion; applied_changes records what
 *               actually got written back to the ticket row (the suggestion
 *               might recommend three fields but the triager only accepted
 *               the priority change, etc.)
 *   rejected  → triager rejected with a reason — useful as negative training
 *               signal for evaluating scorer accuracy
 *   stale     → a newer suggestion was generated for the same ticket, so this
 *               one is no longer the live recommendation; it's preserved for
 *               history rather than deleted
 *
 * Re-running the scorer on a ticket that already has a pending suggestion
 * marks the prior pending row as 'stale' first, so the triage queue widget
 * only ever surfaces the latest pending suggestion per ticket.
 */
class TicketTriageSuggestion extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_STALE = 'stale';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_STALE,
    ];

    /**
     * Allowed terminal moves out of pending. accepted/rejected/stale are all
     * terminal — a stale suggestion can't be revived; the scorer just produces
     * a fresh one. accepted/rejected can't be undone via the API because the
     * applied_changes side-effects on the ticket would be ambiguous to roll
     * back.
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_ACCEPTED => [self::STATUS_PENDING],
        self::STATUS_REJECTED => [self::STATUS_PENDING],
        self::STATUS_STALE => [self::STATUS_PENDING],
    ];

    /**
     * Mirror of the priority vocabulary used on Ticket. Kept here as a
     * scorer-output whitelist so the service can refuse a suggestion that
     * proposes a priority outside the known set (e.g., a buggy AI provider
     * inventing "p99_critical").
     */
    public const SUGGESTED_PRIORITIES = [
        'p1_critical',
        'p2_high',
        'p3_normal',
        'p4_low',
    ];

    public int $id = 0;
    public int $ticket_id = 0;
    public ?string $generated_at = null;
    public string $generated_by = 'heuristic_v1';
    public ?string $suggested_priority = null;
    public ?int $suggested_category_id = null;
    public ?int $suggested_assignee_user_id = null;
    public ?float $sentiment_score = null;
    public ?float $urgency_score = null;
    public ?float $confidence = null;
    public ?string $reasoning = null;
    public string $status = self::STATUS_PENDING;
    public ?int $accepted_by_user_id = null;
    public ?string $accepted_at = null;
    public ?string $applied_changes = null;
    public ?int $rejected_by_user_id = null;
    public ?string $rejected_at = null;
    public ?string $rejection_reason = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
