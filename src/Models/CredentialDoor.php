<?php

namespace App\Models;

/**
 * Credential ↔ door (site_asset) assignment — Phase 16 / S1 of
 * docs/woms-expansion-plan.md.
 *
 * One row per (credential, door) pair. Optional access_schedule_id binds the
 * assignment to a recurring time-window policy; NULL means 24/7 access at
 * this door for this credential. Revocation flips revoked_at + reason
 * instead of DELETE so the per-credential history view stays populated.
 *
 * See migration 166_security_pos_verticals.sql.
 */
class CredentialDoor extends BaseModel
{
    public int $id = 0;
    public int $credential_id = 0;
    public int $site_asset_id = 0;
    public ?int $access_schedule_id = null;
    public ?string $granted_at = null;
    public ?int $granted_by_user_id = null;
    public ?string $revoked_at = null;
    public ?int $revoked_by_user_id = null;
    public ?string $revoke_reason = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
