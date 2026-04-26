<?php

namespace App\Models;

/**
 * Phase 7.3 of docs/expansion-plan.md — see database/migrations/130_fleet_unit_downtime.sql.
 */
class FleetUnitDowntime extends BaseModel
{
    public const REASON_BREAKDOWN = 'breakdown';
    public const REASON_SCHEDULED = 'scheduled_maintenance';
    public const REASON_ACCIDENT = 'accident';
    public const REASON_INSPECTION = 'inspection';
    public const REASON_OTHER = 'other';

    public const ALLOWED_REASONS = [
        self::REASON_BREAKDOWN,
        self::REASON_SCHEDULED,
        self::REASON_ACCIDENT,
        self::REASON_INSPECTION,
        self::REASON_OTHER,
    ];

    public int $id = 0;
    public int $fleet_unit_id = 0;
    public string $reason = self::REASON_BREAKDOWN;
    public string $started_at = '';
    public ?string $ended_at = null;
    public ?string $notes = null;
    public int $started_by_user_id = 0;
    public ?int $ended_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
