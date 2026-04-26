<?php

namespace App\Services\Routing;

use App\Models\RoutePlan;

/**
 * Phase 10.6 — Greedy nearest-neighbor route optimizer.
 *
 * Algorithm: start at the origin, repeatedly pick the unvisited stop
 * closest to the current position (haversine great-circle distance), move
 * to it, repeat. O(n²) and not optimal — but for the typical 8-12-stop
 * technician day, it's within ~10-15% of optimal and runs in microseconds.
 *
 * Travel time estimate is distance / AVG_SPEED_KMH plus the cumulative
 * service minutes. Real-world routing (OSRM, Google Directions) belongs
 * downstream when actually dispatching — this is a planning-time number.
 */
class NearestNeighborRouteOptimizer implements RouteOptimizerInterface
{
    public const AVG_SPEED_KMH = 40.0;

    public function __construct(private readonly GeoFenceEvaluator $evaluator)
    {
    }

    public function optimize(
        float $originLatitude,
        float $originLongitude,
        array $stops,
        bool $returnToOrigin = false,
    ): RouteOptimizationResult {
        if ($stops === []) {
            return new RouteOptimizationResult([], 0, 0);
        }

        $remaining = array_values($stops);
        $ordered = [];
        $totalDistance = 0.0;
        $totalServiceMinutes = 0;

        $currentLat = $originLatitude;
        $currentLng = $originLongitude;

        while ($remaining !== []) {
            $bestIdx = 0;
            $bestDist = $this->evaluator->haversineDistance(
                $currentLat,
                $currentLng,
                $remaining[0]->latitude,
                $remaining[0]->longitude,
            );
            for ($i = 1; $i < count($remaining); $i++) {
                $d = $this->evaluator->haversineDistance(
                    $currentLat,
                    $currentLng,
                    $remaining[$i]->latitude,
                    $remaining[$i]->longitude,
                );
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx = $i;
                }
            }

            $next = $remaining[$bestIdx];
            $totalDistance += $bestDist;
            $totalServiceMinutes += max(0, $next->serviceMinutes);
            $ordered[] = $next;
            $currentLat = $next->latitude;
            $currentLng = $next->longitude;
            array_splice($remaining, $bestIdx, 1);
        }

        if ($returnToOrigin) {
            $totalDistance += $this->evaluator->haversineDistance(
                $currentLat,
                $currentLng,
                $originLatitude,
                $originLongitude,
            );
        }

        $travelMinutes = (int) round(($totalDistance / 1000.0) / self::AVG_SPEED_KMH * 60.0);
        $totalDurationMinutes = $travelMinutes + $totalServiceMinutes;

        return new RouteOptimizationResult(
            $ordered,
            (int) round($totalDistance),
            $totalDurationMinutes,
        );
    }

    public function label(): string
    {
        return RoutePlan::METHOD_NEAREST_NEIGHBOR;
    }
}
