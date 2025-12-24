<?php

namespace App\Models;

class Redirect extends BaseModel
{
    public int $id;
    public string $source_path = '';
    public string $destination_path = '';
    public string $redirect_type = '301';
    public bool $is_active = true;
    public string $match_type = 'exact';
    public ?string $description = null;
    public int $hits = 0;
    public ?int $created_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
