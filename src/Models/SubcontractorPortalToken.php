<?php

namespace App\Models;

/**
 * Phase 18 / C2 — Bearer token issued to a subcontractor for self-service
 * portal access. Stored hashed; the plaintext is shown to the staff user
 * exactly once at issue time so they can hand it to the sub.
 */
class SubcontractorPortalToken extends BaseModel
{
    public int $id = 0;
    public int $subcontractor_id = 0;
    public string $token_hash = '';
    public ?string $label = null;
    public ?string $expires_at = null;
    public ?string $last_used_at = null;
    public ?string $last_used_ip = null;
    public ?string $revoked_at = null;
    public ?string $revoked_reason = null;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;

    public function isActive(?string $now = null): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null) {
            $when = $now ?? date('Y-m-d H:i:s');
            if ($this->expires_at < $when) {
                return false;
            }
        }
        return true;
    }
}
