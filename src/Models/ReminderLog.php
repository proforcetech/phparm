<?php

namespace App\Models;

class ReminderLog extends BaseModel
{
    public int $id;
    public int $campaign_id;
    public ?int $preference_id = null;
    public int $customer_id;
    public string $channel;
    public string $status;
    public ?string $scheduled_for = null;
    public ?string $sent_at = null;
    public ?string $body = null;
    public ?string $error = null;
    public ?string $created_at = null;
}
