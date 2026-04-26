<?php

namespace App\Models;

class TicketSlaClock extends BaseModel
{
    public int $id = 0;
    public int $ticket_id = 0;
    public ?int $policy_id = null;
    public string $clock_kind = 'response';
    public int $target_minutes = 0;
    public string $status = 'running';
    public int $accumulated_seconds = 0;
    public ?string $last_started_at = null;
    public ?string $paused_at = null;
    public ?string $stopped_at = null;
    public ?string $breached_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
