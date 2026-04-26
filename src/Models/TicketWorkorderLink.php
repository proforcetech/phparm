<?php

namespace App\Models;

class TicketWorkorderLink extends BaseModel
{
    public int $id = 0;
    public int $ticket_id = 0;
    public int $workorder_id = 0;
    public string $link_kind = 'spawned';
    public ?int $linked_by_user_id = null;
    public ?string $note = null;
    public ?string $created_at = null;
}
