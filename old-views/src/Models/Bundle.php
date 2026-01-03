<?php

namespace App\Models;

class Bundle extends BaseModel
{
    public int $id;
    public string $name;
    public ?string $description = null;
    public ?int $service_type_id = null;
    public string $default_job_title;
    public bool $is_active = true;
    public int $sort_order = 0;
    public ?int $item_count = null;
}
