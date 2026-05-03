<?php

namespace App\Models;

class TicketEscalationRule extends BaseModel
{
    public int $id = 0;
    public string $name = '';
    public ?string $description = null;
    public int $is_active = 1;
    public string $trigger_kind = 'stale';
    public ?int $trigger_minutes = null;
    public ?int $trigger_seconds = null;
    public ?string $trigger_sla_kind = null;
    public ?int $match_division_id = null;
    public ?int $match_queue_id = null;
    public ?string $match_priority = null;
    public ?string $match_severity = null;
    public ?string $match_status = null;
    public ?int $action_reassign_queue_id = null;
    public ?string $action_raise_priority_to = null;
    public ?int $action_notify_user_id = null;
    public int $cooldown_minutes = 60;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
