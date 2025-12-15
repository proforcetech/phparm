<?php

namespace App\CMS\Models;

use App\Models\BaseModel;

class Page extends BaseModel
{
    public int $id;
    public string $title;
    public string $slug;
    public ?int $template_id = null;
    public string $status = 'draft';
    public ?string $meta_title = null;
    public ?string $meta_description = null;
    public ?string $meta_keywords = null;
    public ?string $summary = null;
    public ?string $content = null;
    public ?string $publish_start_at = null;
    public ?string $publish_end_at = null;
    public ?string $published_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
