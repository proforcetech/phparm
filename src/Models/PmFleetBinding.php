<?php

namespace App\Models;

/**
 * Phase 7.2 of docs/expansion-plan.md — see database/migrations/129_pm_fleet_bindings.sql.
 */
class PmFleetBinding extends BaseModel
{
    public const SCOPE_UNIT = 'unit';
    public const SCOPE_UNIT_TYPE = 'unit_type';

    public const ALLOWED_SCOPES = [
        self::SCOPE_UNIT,
        self::SCOPE_UNIT_TYPE,
    ];

    public int $id = 0;
    public int $plan_id = 0;
    public int $company_id = 0;
    public string $scope_type = self::SCOPE_UNIT_TYPE;
    public ?int $fleet_unit_id = null;
    public ?string $unit_type = null;
    public string $frequency_kind = 'meter';
    /** @var array<string, mixed>|null */
    public ?array $frequency_config = null;
    public int $lead_time_days = 0;
    public string $starts_at = '';
    public int $is_active = 1;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
