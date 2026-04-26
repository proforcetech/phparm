<?php

namespace App\Models;

/**
 * Phase 10.3 — A request from the primary tech for additional help on a WO.
 *
 * Lifecycle:
 *   pending    → tech filed the request; manager hasn't acted yet
 *   approved   → manager said yes but hasn't picked the helper user yet
 *   declined   → manager said no with a reason
 *   cancelled  → primary tech withdrew the request before manager acted
 *   fulfilled  → manager picked a helper, who's now on workorder_additional_techs
 *
 * Approved + fulfilled are split because dispatch reality says "yes you can
 * have help" often happens before "and here's WHO can help you" — the
 * fulfilment step is the assignment of the actual user.
 *
 * request_type drives suggested skill matching downstream:
 *   extra_hands   — generic second body, any available tech
 *   specialty     — needs a specific skill (HVAC, electrical, etc) — pair
 *                   with skills_needed for the picker
 *   second_opinion — a peer review / consult, doesn't need to do work
 *   training      — bring along a trainee/apprentice for shadowing
 */
class WorkorderTechRequest extends BaseModel
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

    public const TYPE_EXTRA_HANDS = 'extra_hands';
    public const TYPE_SPECIALTY = 'specialty';
    public const TYPE_SECOND_OPINION = 'second_opinion';
    public const TYPE_TRAINING = 'training';

    public const REQUEST_TYPES = [
        self::TYPE_EXTRA_HANDS,
        self::TYPE_SPECIALTY,
        self::TYPE_SECOND_OPINION,
        self::TYPE_TRAINING,
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
    public string $request_type = self::TYPE_EXTRA_HANDS;
    public string $reason = '';
    public ?float $estimated_hours = null;
    public ?string $skills_needed = null;
    public string $urgency = self::URGENCY_NORMAL;
    public string $status = self::STATUS_PENDING;
    public ?string $requested_at = null;
    public ?int $approved_by_user_id = null;
    public ?string $approved_at = null;
    public ?string $declined_at = null;
    public ?string $cancelled_at = null;
    public ?string $fulfilled_at = null;
    public ?int $fulfilled_user_id = null;
    public ?string $rejection_reason = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
