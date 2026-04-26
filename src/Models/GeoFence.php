<?php

namespace App\Models;

/**
 * Phase 10.6 — A spatial region used for membership tests against position
 * updates from mobile clients (auto-clock-in, "tech left site mid-job"
 * alerts, after-the-fact "where did Carol's truck go today").
 *
 * Two shapes are supported:
 *   circle   centered at (center_latitude, center_longitude) with
 *            radius_meters. Cheap point-in-fence test via haversine.
 *   polygon  arbitrary ring stored as polygon_geojson — a JSON array of
 *            [longitude, latitude] pairs (GeoJSON ordering convention).
 *            First and last vertex must match to close the ring; the
 *            evaluator handles this requirement during point-in-polygon.
 *
 * purpose drives downstream behavior:
 *   shop_zone        the home base — entered events trigger clock-in
 *                    candidates, exited events end shop time
 *   customer_site    a job location — entered events on an active WO
 *                    auto-stamp "arrived" on the relevant route stop
 *   service_zone     a broad geographic region the org operates in;
 *                    used for territory/coverage reporting, not events
 *   restricted_zone  off-limits area (e.g., competitor lot) — entered
 *                    events should fire alerts to dispatch
 */
class GeoFence extends BaseModel
{
    public const SHAPE_CIRCLE = 'circle';
    public const SHAPE_POLYGON = 'polygon';

    public const SHAPES = [
        self::SHAPE_CIRCLE,
        self::SHAPE_POLYGON,
    ];

    public const PURPOSE_SHOP_ZONE = 'shop_zone';
    public const PURPOSE_CUSTOMER_SITE = 'customer_site';
    public const PURPOSE_SERVICE_ZONE = 'service_zone';
    public const PURPOSE_RESTRICTED_ZONE = 'restricted_zone';

    public const PURPOSES = [
        self::PURPOSE_SHOP_ZONE,
        self::PURPOSE_CUSTOMER_SITE,
        self::PURPOSE_SERVICE_ZONE,
        self::PURPOSE_RESTRICTED_ZONE,
    ];

    public int $id = 0;
    public string $name = '';
    public string $shape_type = self::SHAPE_CIRCLE;
    public ?float $center_latitude = null;
    public ?float $center_longitude = null;
    public ?int $radius_meters = null;
    public ?string $polygon_geojson = null;
    public string $purpose = self::PURPOSE_SERVICE_ZONE;
    public ?int $customer_id = null;
    public ?int $workorder_id = null;
    public ?int $asset_id = null;
    public bool $active = true;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
