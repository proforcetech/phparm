<?php

namespace App\CMS\Models;

use App\Models\BaseModel;

class Menu extends BaseModel
{
    public int $id;
    public string $name;
    public string $slug;
    public string $status = 'draft';
    public ?string $description = null;
    public ?string $items = null;
    public ?string $meta_title = null;
    public ?string $meta_description = null;
    public ?string $published_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
