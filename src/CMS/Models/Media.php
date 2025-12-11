<?php

namespace App\CMS\Models;

use App\Models\BaseModel;

class Media extends BaseModel
{
    public int $id;
    public string $file_name;
    public string $slug;
    public string $url;
    public string $status = 'published';
    public ?string $mime_type = null;
    public ?int $size_bytes = null;
    public ?string $title = null;
    public ?string $alt_text = null;
    public ?string $published_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
