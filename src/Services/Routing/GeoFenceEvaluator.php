<?php

namespace App\Services\Routing;

use App\Models\GeoFence;

/**
 * Phase 10.6 — Pure-logic point-in-fence evaluator.
 *
 * Stateless on purpose: every method takes its inputs as arguments so the
 * heuristic is fully unit-testable without needing a database, and so the
 * mobile-side rule (where we may eventually want to evaluate fences offline
 * for instant entered/exited UX) can use the same algorithm.
 *
 * Two membership tests:
 *   circle  — haversine great-circle distance compared against radius_meters.
 *   polygon — ray-casting against the polygon stored as polygon_geojson, a
 *             JSON array of [lng, lat] pairs (GeoJSON convention). The ring
 *             may or may not be closed (first==last); the algorithm doesn't
 *             require closure.
 *
 * Returned distances/values are in meters. Earth radius constant is the
 * WGS84 mean radius — sufficient for the ~few-hundred-meter scale we
 * actually care about (a customer site fence is rarely larger than 200m).
 */
class GeoFenceEvaluator
{
    public const EARTH_RADIUS_METERS = 6371008;

    /**
     * Returns true if the point (lat, lng) lies inside the given fence.
     * Inactive fences always return false so callers can pass mixed lists
     * without pre-filtering.
     */
    public function contains(GeoFence $fence, float $latitude, float $longitude): bool
    {
        if (!$fence->active) {
            return false;
        }

        if ($fence->shape_type === GeoFence::SHAPE_CIRCLE) {
            if ($fence->center_latitude === null || $fence->center_longitude === null || $fence->radius_meters === null) {
                return false;
            }
            $distance = $this->haversineDistance(
                $latitude,
                $longitude,
                $fence->center_latitude,
                $fence->center_longitude
            );
            return $distance <= (float)$fence->radius_meters;
        }

        if ($fence->shape_type === GeoFence::SHAPE_POLYGON) {
            if ($fence->polygon_geojson === null || $fence->polygon_geojson === '') {
                return false;
            }
            $ring = json_decode($fence->polygon_geojson, true);
            if (!is_array($ring) || count($ring) < 3) {
                return false;
            }
            return $this->pointInPolygon($latitude, $longitude, $ring);
        }

        return false;
    }

    /**
     * Filter a list of fences to those containing the point.
     *
     * @param GeoFence[] $fences
     * @return GeoFence[]
     */
    public function matchingFences(array $fences, float $latitude, float $longitude): array
    {
        $matches = [];
        foreach ($fences as $fence) {
            if ($this->contains($fence, $latitude, $longitude)) {
                $matches[] = $fence;
            }
        }
        return $matches;
    }

    /**
     * Great-circle distance in meters between two (lat, lng) points.
     */
    public function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2
            + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Ray-casting point-in-polygon. Ring is an array of [lng, lat] pairs
     * (GeoJSON ordering). Open rings are accepted; closure is implicit.
     *
     * @param array<int, array{0: float|int, 1: float|int}> $ring
     */
    public function pointInPolygon(float $latitude, float $longitude, array $ring): bool
    {
        $count = count($ring);
        if ($count < 3) {
            return false;
        }

        $inside = false;
        $j = $count - 1;
        for ($i = 0; $i < $count; $i++) {
            if (!isset($ring[$i][0], $ring[$i][1], $ring[$j][0], $ring[$j][1])) {
                $j = $i;
                continue;
            }
            $xi = (float)$ring[$i][0];
            $yi = (float)$ring[$i][1];
            $xj = (float)$ring[$j][0];
            $yj = (float)$ring[$j][1];

            $intersect = (($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < ($xj - $xi) * ($latitude - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
            $j = $i;
        }

        return $inside;
    }
}
