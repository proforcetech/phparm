<?php

namespace App\Models;

class TicketQueue extends BaseModel
{
    public int $id = 0;
    public string $code = '';
    public string $name = '';
    public ?string $description = null;
    public ?int $division_id = null;
    public int $is_active = 1;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
