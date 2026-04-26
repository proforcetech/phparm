<?php

namespace App\Models;

class SavedReport extends BaseModel
{
    public ?int $id = null;
    public string $report_key = '';
    public string $name = '';
    public ?string $description = null;
    public ?array $parameters = null;
    public ?array $columns_visible = null;
    public ?array $drill_down = null;
    public ?int $owner_user_id = null;
    public bool $is_shared = false;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
