<?php

namespace App\Models;

class NotFoundLog extends BaseModel
{
    public int $id;
    public string $uri = '';
    public ?string $referrer = null;
    public ?string $user_agent = null;
    public ?string $ip_address = null;
    public string $first_seen;
    public string $last_seen;
    public int $hits = 1;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
