<?php

namespace App\Models;

class TrustedDevice extends BaseModel
{
    public ?int $id = null;
    public int $user_id = 0;
    public string $token_hash = '';
    public ?string $label = null;
    public ?string $user_agent = null;
    public ?string $ip_address = null;
    public ?string $last_used_at = null;
    public string $expires_at = '';
    public ?string $revoked_at = null;
    public ?string $created_at = null;
}
