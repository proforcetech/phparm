<?php

namespace App\Models;

class LaborTask extends BaseModel
{
    public int $id;
    public string $name;
    public ?string $description = null;
    public float $flat_rate_minutes = 0;
    public ?float $labor_rate = null;
    public ?int $service_type_id = null;
    public ?int $service_line_id = null;
    public bool $is_active = true;
    public int $display_order = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
