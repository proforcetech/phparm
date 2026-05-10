<?php

namespace App\Models;

class PortalNotificationPreference extends BaseModel
{
    public int $id = 0;
    public int $portal_account_id = 0;
    public string $pref_key = '';
    public string $channel = '';
    public bool $enabled = true;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
