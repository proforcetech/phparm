<?php

namespace App\Models;

class TicketCategory extends BaseModel
{
    public int $id = 0;
    public ?int $division_id = null;
    public ?int $parent_id = null;
    public string $code = '';
    public string $name = '';
    public ?string $description = null;
    public int $is_active = 1;
    public int $portal_visible = 0;
    public string $default_priority = 'p3_normal';
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
