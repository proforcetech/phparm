<?php

namespace App\Models;

class AssetLink extends BaseModel
{
    public int $id = 0;
    public int $asset_id = 0;
    public string $related_type = '';
    public int $related_id = 0;
    public ?string $relation = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?int $created_by = null;
}
