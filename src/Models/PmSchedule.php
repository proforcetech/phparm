<?php

namespace App\Models;

class PmSchedule extends BaseModel
{
    public int $id = 0;
    public int $plan_id = 0;
    public int $company_id = 0;
    public ?int $site_id = null;
    public ?int $asset_id = null;
    public ?int $fleet_unit_id = null;
    public string $frequency_kind = 'fixed_interval';
    /** @var array<string, mixed>|null */
    public ?array $frequency_config = null;
    public string $starts_at = '';
    public ?string $next_due_at = null;
    public ?string $last_generated_at = null;
    public int $lead_time_days = 0;
    public string $status = 'active';
    public ?int $contract_id = null;
    public ?int $contract_entitlement_id = null;
    public ?string $notes = null;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
