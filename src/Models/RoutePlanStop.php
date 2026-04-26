<?php

namespace App\Models;

/**
 * Phase 10.6 — A single stop within a RoutePlan.
 *
 * sequence_order is the dispatch-assigned order; the optimizer rewrites it
 * during optimize() runs. Two stops on the same plan may not share a
 * sequence_order — a partial unique index enforces that at the database
 * layer.
 *
 * Lifecycle:
 *   planned    initial state — tech hasn't started moving toward the stop
 *   en_route   tech started navigation; not yet arrived
 *   arrived    tech reached the location (auto-stamp via geofence event,
 *              or manual stamp from the mobile UI)
 *   completed  service work at the stop is done; tech can move on
 *   skipped    stop bypassed (no-show, can't access, dispatcher reroute)
 *
 * Once en_route, a stop can return to planned only via dispatch override
 * (handled at the service layer, not via standard transition). The standard
 * ALLOWED_TRANSITIONS table handles the forward path; rollback is a separate
 * dispatcher-only action.
 */
class RoutePlanStop extends BaseModel
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_EN_ROUTE = 'en_route';
    public const STATUS_ARRIVED = 'arrived';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_EN_ROUTE,
        self::STATUS_ARRIVED,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
    ];

    public const ALLOWED_TRANSITIONS = [
        self::STATUS_PLANNED => [self::STATUS_EN_ROUTE, self::STATUS_ARRIVED, self::STATUS_SKIPPED],
        self::STATUS_EN_ROUTE => [self::STATUS_ARRIVED, self::STATUS_SKIPPED],
        self::STATUS_ARRIVED => [self::STATUS_COMPLETED, self::STATUS_SKIPPED],
        self::STATUS_COMPLETED => [],
        self::STATUS_SKIPPED => [],
    ];

    public int $id = 0;
    public int $route_plan_id = 0;
    public int $sequence_order = 0;
    public ?int $workorder_id = null;
    public ?int $appointment_id = null;
    public string $stop_label = '';
    public float $latitude = 0.0;
    public float $longitude = 0.0;
    public ?string $estimated_arrival_at = null;
    public ?string $estimated_departure_at = null;
    public ?int $service_minutes_planned = null;
    public ?string $arrived_at = null;
    public ?string $departed_at = null;
    public string $status = self::STATUS_PLANNED;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
