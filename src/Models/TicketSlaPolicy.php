<?php

namespace App\Models;

class TicketSlaPolicy extends BaseModel
{
    public int $id = 0;
    public ?int $division_id = null;
    public string $priority = 'p3_normal';
    public string $name = '';
    public ?int $response_minutes = null;
    public ?int $arrival_minutes = null;
    public ?int $resolution_minutes = null;
    public int $is_active = 1;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
