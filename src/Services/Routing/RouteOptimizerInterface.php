<?php

namespace App\Services\Routing;

/**
 * Phase 10.6 — pluggable route-optimizer contract.
 *
 * Implementations take an origin and a list of stops, return a result
 * containing the reordered stops plus the computed totals (distance in
 * meters, duration in minutes). The default implementation,
 * NearestNeighborRouteOptimizer, is a greedy O(n²) heuristic — good
 * enough for the typical 8-12-stop technician day. Future implementations
 * (or-tools binding, OSRM-driven optimizer, etc.) can plug in via the
 * routes/modules/routing.php DI binding.
 *
 * label() is a short identifier persisted on route_plans.optimization_method
 * so we can A/B-test optimizers across the fleet and attribute outcomes
 * back to the algorithm that produced the plan.
 */
interface RouteOptimizerInterface
{
    /**
     * @param RouteStop[] $stops
     */
    public function optimize(
        float $originLatitude,
        float $originLongitude,
        array $stops,
        bool $returnToOrigin = false,
    ): RouteOptimizationResult;

    public function label(): string;
}
