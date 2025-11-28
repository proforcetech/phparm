<?php

namespace App\Models;

class Setting extends BaseModel
{
    public int $id;
    public string $key;
    public string $group;
    public string $type;
    public mixed $value;
    public ?string $description = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
