<?php

namespace App\Models;

/**
 * Phase 10.3 — A secondary tech assigned to a work order alongside the
 * primary tech (workorders.assigned_technician_id remains the primary).
 *
 * removed_at uses a soft-removal pattern: clearing a tech off the WO sets
 * removed_at + removed_by_user_id rather than deleting the row, so the
 * historical record of "Carol worked this WO from 2pm to 4pm before being
 * pulled to a more urgent job" is preserved for time-tracking attribution
 * and accountability reviews.
 *
 * tech_role drives downstream display and time-tracking attribution:
 *   secondary_tech — full helper, gets time-tracking buckets
 *   specialist     — brought in for a specific skill (HVAC, electrical, etc)
 *   shadow         — trainee shadowing for learning, no time billing
 *   apprentice     — trainee actively assisting, time billed at apprentice rate
 *
 * request_id is nullable because a manager can drop an extra tech onto a WO
 * directly without going through a formal request flow (common when
 * dispatch reassigns mid-day).
 */
class WorkorderAdditionalTech extends BaseModel
{
    public const ROLE_SECONDARY = 'secondary_tech';
    public const ROLE_SPECIALIST = 'specialist';
    public const ROLE_SHADOW = 'shadow';
    public const ROLE_APPRENTICE = 'apprentice';

    public const ROLES = [
        self::ROLE_SECONDARY,
        self::ROLE_SPECIALIST,
        self::ROLE_SHADOW,
        self::ROLE_APPRENTICE,
    ];

    public int $id = 0;
    public int $workorder_id = 0;
    public int $user_id = 0;
    public ?int $request_id = null;
    public string $tech_role = self::ROLE_SECONDARY;
    public ?string $added_at = null;
    public ?int $added_by_user_id = null;
    public ?string $removed_at = null;
    public ?int $removed_by_user_id = null;
    public ?string $removal_reason = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }
}
