<?php

namespace App\Models;

class AssetType extends BaseModel
{
    public int $id = 0;
    public ?int $division_id = null;
    public ?int $parent_id = null;
    public string $code = '';
    public string $name = '';
    public ?string $description = null;
    public ?string $icon = null;
    public bool $is_active = true;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
