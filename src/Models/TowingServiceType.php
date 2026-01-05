<?php

namespace App\Models;

class TowingServiceType extends BaseModel
{
    public int $id;
    public string $name;
    public string $code;
    public ?string $description = null;
    public bool $is_active = true;
    public int $sort_order = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
