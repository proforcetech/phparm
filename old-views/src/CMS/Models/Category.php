<?php

namespace App\CMS\Models;

use App\Models\BaseModel;

class Category extends BaseModel
{
    public int $id;
    public string $name;
    public string $slug;
    public ?string $description = null;
    public string $status = 'published';
    public int $sort_order = 0;
    public ?string $meta_title = null;
    public ?string $meta_description = null;
    public ?string $meta_keywords = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
