<?php

namespace App\Models;

/**
 * Phase 7.1 of docs/expansion-plan.md — see database/migrations/128_fleet_units.sql.
 */
class FleetUnitReading extends BaseModel
{
    public const TYPE_ODOMETER = 'odometer';
    public const TYPE_ENGINE_HOURS = 'engine_hours';

    public const ALLOWED_TYPES = [
        self::TYPE_ODOMETER,
        self::TYPE_ENGINE_HOURS,
    ];

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_WORKORDER = 'workorder';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_TELEMATICS = 'telematics';

    public const ALLOWED_SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_WORKORDER,
        self::SOURCE_IMPORT,
        self::SOURCE_TELEMATICS,
    ];

    public int $id = 0;
    public int $fleet_unit_id = 0;
    public string $reading_type = self::TYPE_ODOMETER;
    public float $value = 0.0;
    public string $recorded_at = '';
    public string $source = self::SOURCE_MANUAL;
    public ?int $workorder_id = null;
    public ?string $notes = null;
    public int $recorded_by_user_id = 0;
    public ?string $created_at = null;
}
