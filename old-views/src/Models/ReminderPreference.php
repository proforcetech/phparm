<?php

namespace App\Models;

class ReminderPreference extends BaseModel
{
    public int $id;
    public int $customer_id;
    public ?string $email = null;
    public ?string $phone = null;
    public string $timezone = 'UTC';
    public string $preferred_channel = 'both';
    public int $lead_days = 3;
    public int $preferred_hour = 9;
    public bool $is_active = true;
    public ?string $source = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
