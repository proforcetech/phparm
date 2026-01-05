<?php

namespace App\Models;

class TowingPriceMatrix extends BaseModel
{
    public int $id;
    public int $service_class_id;
    public int $service_type_id;
    public float $rollout_fee = 0.0;
    public float $hookup_fee = 0.0;
    public float $onhook_fee = 0.0;
    public float $mileage_rate = 0.0;
    public float $deadhead_rate = 0.0;
    public float $accident_cleanup_fee = 0.0;
    public float $winch_fee = 0.0;
    public float $storage_daily_rate = 0.0;
    public float $after_hours_multiplier = 1.0;
    public float $minimum_charge = 0.0;
    public bool $is_active = true;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    // Joined fields for display
    public ?string $service_class_name = null;
    public ?string $service_type_name = null;
    public ?string $service_type_code = null;
}
