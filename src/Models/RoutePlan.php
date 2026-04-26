<?php

namespace App\Models;

/**
 * Phase 10.6 — A planned sequence of stops for one technician on one date.
 *
 * Lifecycle:
 *   draft      dispatcher is composing the plan; freely editable
 *   active     dispatch has dispatched it to the field; tech is executing
 *   completed  every stop has reached a terminal status
 *   cancelled  abandoned before completion (weather, sick day, reroute)
 *
 * ALLOWED_TRANSITIONS deliberately excludes draft↔active loops to keep the
 * audit story clean — once a plan is dispatched, "moving back to draft" is
 * better modeled as cancelling and creating a new plan that references the
 * unfinished stops.
 *
 * optimization_method records which optimizer produced the current sequence
 * (e.g., 'nearest_neighbor', 'manual', 'or_tools') so that A/B testing of
 * alternative optimizers stays attributable on the historical record.
 *
 * total_distance_meters and total_duration_minutes are estimates from the
 * optimizer at the time of planning — actuals are derived after the fact
 * from the per-stop arrival/departure stamps.
 */
class RoutePlan extends BaseModel
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_ACTIVE => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    public const METHOD_MANUAL = 'manual';
    public const METHOD_NEAREST_NEIGHBOR = 'nearest_neighbor';

    public const OPTIMIZATION_METHODS = [
        self::METHOD_MANUAL,
        self::METHOD_NEAREST_NEIGHBOR,
    ];

    public int $id = 0;
    public int $planned_for_user_id = 0;
    public ?string $plan_date = null;
    public string $status = self::STATUS_DRAFT;
    public ?float $origin_latitude = null;
    public ?float $origin_longitude = null;
    public ?string $origin_label = null;
    public bool $return_to_origin = true;
    public string $optimization_method = self::METHOD_MANUAL;
    public ?int $total_distance_meters = null;
    public ?int $total_duration_minutes = null;
    public ?string $optimized_at = null;
    public ?string $activated_at = null;
    public ?string $completed_at = null;
    public ?string $cancelled_at = null;
    public ?int $created_by_user_id = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
