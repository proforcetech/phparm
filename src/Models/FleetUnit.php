<?php

namespace App\Models;

/**
 * Phase 7.1 of docs/expansion-plan.md — see database/migrations/128_fleet_units.sql.
 */
class FleetUnit extends BaseModel
{
    public const TYPE_TRUCK = 'truck';
    public const TYPE_VAN = 'van';
    public const TYPE_TRAILER = 'trailer';
    public const TYPE_EQUIPMENT = 'equipment';
    public const TYPE_OTHER = 'other';

    public const ALLOWED_TYPES = [
        self::TYPE_TRUCK,
        self::TYPE_VAN,
        self::TYPE_TRAILER,
        self::TYPE_EQUIPMENT,
        self::TYPE_OTHER,
    ];

    public const METER_ODOMETER = 'odometer';
    public const METER_ENGINE_HOURS = 'engine_hours';
    public const METER_BOTH = 'both';

    public const ALLOWED_METERS = [
        self::METER_ODOMETER,
        self::METER_ENGINE_HOURS,
        self::METER_BOTH,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_OUT_OF_SERVICE = 'out_of_service';
    public const STATUS_RETIRED = 'retired';

    public const ALLOWED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_OUT_OF_SERVICE,
        self::STATUS_RETIRED,
    ];

    public int $id = 0;
    public int $company_id = 0;
    public string $unit_number = '';
    public string $unit_type = self::TYPE_TRUCK;
    public ?string $vin = null;
    public ?int $year = null;
    public ?string $make = null;
    public ?string $model = null;
    public ?string $trim = null;
    public ?string $license_plate = null;
    public ?int $home_site_id = null;
    public string $meter_type = self::METER_ODOMETER;
    public ?int $current_odometer = null;
    public ?float $current_engine_hours = null;
    public ?string $odometer_last_read_at = null;
    public ?string $engine_hours_last_read_at = null;
    public string $status = self::STATUS_ACTIVE;
    public ?string $notes = null;
    public int $created_by_user_id = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
