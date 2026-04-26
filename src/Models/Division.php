<?php

namespace App\Models;

class Division extends BaseModel
{
    public int $id = 0;
    public string $code = '';
    public string $name = '';
    public ?string $description = null;
    public bool $is_active = true;
    public int $sort_order = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
