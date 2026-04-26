<?php

namespace App\Services\Routing;

/**
 * Phase 10.6 — Plain DTO passed to optimizers.
 *
 * Optimizers read inputs from this DTO and emit a reordered list of the
 * same DTO instances back to the service. They do not touch persistence —
 * the service applies the optimizer's output to the route_plan_stops rows.
 *
 * id is the primary key on the existing stop (or null for stops that don't
 * exist in the database yet, e.g., when an "optimize this proposal" preview
 * runs before the plan is saved).
 *
 * lat/lng are required — optimizers can't reorder stops without coordinates.
 */
final class RouteStop
{
    public function __construct(
        public readonly ?int $id,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $label = '',
        public readonly int $serviceMinutes = 0,
    ) {
    }
}
