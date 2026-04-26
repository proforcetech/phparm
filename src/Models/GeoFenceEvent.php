<?php

namespace App\Models;

/**
 * Phase 10.6 — A discrete crossing/dwell event detected against a geofence.
 *
 * The mobile app sends position updates; the backend evaluates them against
 * the active fence set, and when state transitions are detected, an event is
 * persisted. Downstream consumers (auto-clock-in, route stop arrival
 * stamping, dispatch alerts) listen on these events.
 *
 * event_type:
 *   entered   user crossed into the fence (was outside on prior fix)
 *   exited    user crossed out of the fence (was inside on prior fix)
 *   dwell     user has been continuously inside for the fence's dwell window
 *             (used for "tech is parked at customer site" detection separate
 *              from the initial arrival event)
 *
 * source distinguishes how the position was reported:
 *   mobile_gps        live foreground/background fix from the mobile client
 *   manual            dispatch manually punched the event (e.g., correcting
 *                     a missed arrival)
 *   background_sync   queued from offline mode and replayed on reconnect
 */
class GeoFenceEvent extends BaseModel
{
    public const EVENT_ENTERED = 'entered';
    public const EVENT_EXITED = 'exited';
    public const EVENT_DWELL = 'dwell';

    public const EVENT_TYPES = [
        self::EVENT_ENTERED,
        self::EVENT_EXITED,
        self::EVENT_DWELL,
    ];

    public const SOURCE_MOBILE_GPS = 'mobile_gps';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_BACKGROUND_SYNC = 'background_sync';

    public const SOURCES = [
        self::SOURCE_MOBILE_GPS,
        self::SOURCE_MANUAL,
        self::SOURCE_BACKGROUND_SYNC,
    ];

    public int $id = 0;
    public int $geo_fence_id = 0;
    public int $user_id = 0;
    public ?int $workorder_id = null;
    public string $event_type = self::EVENT_ENTERED;
    public ?string $occurred_at = null;
    public ?float $latitude = null;
    public ?float $longitude = null;
    public ?int $accuracy_meters = null;
    public string $source = self::SOURCE_MOBILE_GPS;
    public ?string $notes = null;
    public ?string $created_at = null;
}
