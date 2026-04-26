<?php

namespace App\Models;

class TicketEvent extends BaseModel
{
    public int $id = 0;
    public int $ticket_id = 0;
    public string $event_kind = '';
    public ?int $actor_user_id = null;
    public ?int $actor_contact_id = null;
    public ?string $message = null;
    public int $is_internal = 1;
    public ?array $payload = null;
    public ?string $created_at = null;
}
