<?php

namespace App\CMS\Models;

use App\Models\BaseModel;

class Template extends BaseModel
{
    public int $id;
    public string $name;
    public string $slug;
    public ?string $description = null;
    public ?string $structure = null;
    public ?string $default_css = null;
    public ?string $default_js = null;
    public bool $is_active = true;
    public ?int $created_by = null;
    public ?int $updated_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
