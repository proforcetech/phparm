<?php

namespace App\Models;

class PortalApiToken extends BaseModel
{
    public int $id = 0;
    public int $portal_account_id = 0;
    public string $name = '';
    public string $token_prefix = '';
    public string $token_hash = '';
    /** @var array<int, string>|null */
    public ?array $scopes = null;
    public ?string $last_used_at = null;
    public ?string $expires_at = null;
    public ?string $revoked_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && strtotime($this->expires_at) !== false
            && strtotime($this->expires_at) < time()
        ) {
            return false;
        }
        return true;
    }
}
