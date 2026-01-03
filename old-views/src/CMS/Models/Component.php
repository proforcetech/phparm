<?php

namespace App\CMS\Models;

use App\Models\BaseModel;

class Component extends BaseModel
{
    public int $id;
    public string $name;
    public string $slug;
    public string $type = 'custom';
    public ?string $description = null;
    public string $content;
    public ?string $css = null;
    public ?string $javascript = null;
    public bool $is_active = true;
    public int $cache_ttl = 3600;
    public ?int $created_by = null;
    public ?int $updated_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
