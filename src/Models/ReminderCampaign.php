<?php

namespace App\Models;

class ReminderCampaign extends BaseModel
{
    public int $id;
    public string $name;
    public string $channel;
    public string $frequency;
    public string $status;
    public ?string $service_type_filter = null;
    public ?string $last_run_at = null;
    public ?string $next_run_at = null;
}
