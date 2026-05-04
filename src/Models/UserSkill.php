<?php

namespace App\Models;

/**
 * User-skill assignment — Phase 17 / S11 of docs/woms-expansion-plan.md.
 *
 * Records a (user_id, skill_id) pair plus proficiency_level + optional
 * certified_at / expires_at dates. The unique constraint in the schema
 * prevents duplicate (user, skill) rows so the matrix has at most one entry
 * per cell.
 *
 * proficiency_level vocabulary lives in PROFICIENCY_* constants below; the
 * column is VARCHAR rather than ENUM so we can extend it without another
 * migration.
 */
class UserSkill extends BaseModel
{
    public const PROFICIENCY_LEARNER = 'learner';
    public const PROFICIENCY_COMPETENT = 'competent';
    public const PROFICIENCY_EXPERT = 'expert';

    public const ALLOWED_PROFICIENCY_LEVELS = [
        self::PROFICIENCY_LEARNER,
        self::PROFICIENCY_COMPETENT,
        self::PROFICIENCY_EXPERT,
    ];

    public int $id = 0;
    public int $user_id = 0;
    public int $skill_id = 0;
    public string $proficiency_level = self::PROFICIENCY_COMPETENT;
    public ?string $certified_at = null;
    public ?string $expires_at = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * True when expires_at is set and falls before the comparison date.
     */
    public function isExpired(?string $now = null): bool
    {
        if ($this->expires_at === null || $this->expires_at === '') {
            return false;
        }
        $now ??= date('Y-m-d');
        return strtotime($this->expires_at) < strtotime($now);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
