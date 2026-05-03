<?php

namespace App\Models;

/**
 * Seat consumption ledger for the software CMDB — Phase 14 / M9 of
 * docs/woms-expansion-plan.md.
 *
 * One row per active or historical assignment. unassigned_at IS NULL means
 * the seat is currently in use. Either assignee_user_id (named-user
 * license) or assignee_site_asset_id (machine/device license) is set —
 * never both. assignee_type makes the discriminator explicit.
 *
 * See migration 163_software_cmdb.sql.
 */
class LicenseAssignment extends BaseModel
{
    public const ASSIGNEE_USER = 'user';
    public const ASSIGNEE_SITE_ASSET = 'site_asset';

    public const ALLOWED_ASSIGNEE_TYPES = [
        self::ASSIGNEE_USER,
        self::ASSIGNEE_SITE_ASSET,
    ];

    public int $id = 0;
    public int $license_seat_id = 0;
    public string $assignee_type = self::ASSIGNEE_USER;
    public ?int $assignee_user_id = null;
    public ?int $assignee_site_asset_id = null;
    public ?string $assigned_at = null;
    public ?int $assigned_by_user_id = null;
    public ?string $unassigned_at = null;
    public ?int $unassigned_by_user_id = null;
    public ?string $unassign_reason = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function isActive(): bool
    {
        return $this->unassigned_at === null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
