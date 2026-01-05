<?php

namespace App\Models;

class TowingServiceClass extends BaseModel
{
    public int $id;
    public string $name;
    public ?string $description = null;
    public ?int $weight_min = null;
    public ?int $weight_max = null;
    public bool $is_active = true;
    public int $sort_order = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
