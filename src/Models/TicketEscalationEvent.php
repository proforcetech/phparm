<?php

namespace App\Models;

class TicketEscalationEvent extends BaseModel
{
    public int $id = 0;
    public int $ticket_id = 0;
    public int $rule_id = 0;
    public ?string $fired_at = null;
    public ?array $actions_applied = null;
}
