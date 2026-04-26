<?php

namespace App\Services\Routing;

/**
 * Phase 10.6 — Result returned by a RouteOptimizerInterface.
 *
 * orderedStops is the input list reordered into visit sequence (origin is
 * implicit — not included). totalDistanceMeters and totalDurationMinutes
 * are the optimizer's *estimate* of the full route including any return
 * leg to the origin if that was requested.
 *
 * Duration estimation in the default heuristic uses a flat average speed
 * (40 km/h, configurable per optimizer) plus per-stop service minutes —
 * routing-grade accuracy lives downstream in OSRM/Google Directions, this
 * is a planning-time approximation.
 */
final class RouteOptimizationResult
{
    /**
     * @param RouteStop[] $orderedStops
     */
    public function __construct(
        public readonly array $orderedStops,
        public readonly int $totalDistanceMeters,
        public readonly int $totalDurationMinutes,
    ) {
    }
}
