<?php

namespace App\Models;

class ReminderCampaign extends BaseModel
{
    public int $id;
    public string $name;
    public ?string $description = null;
    public string $channel;
    public string $frequency;
    public string $frequency_unit = 'day';
    public int $frequency_interval = 1;
    public string $status;
    public ?string $service_type_filter = null;
    public ?string $email_subject = null;
    public ?string $email_body = null;
    public ?string $sms_body = null;
    public ?string $last_run_at = null;
    public ?string $next_run_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
