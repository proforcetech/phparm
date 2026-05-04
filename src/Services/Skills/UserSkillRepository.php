<?php

namespace App\Services\Skills;

use App\Database\Connection;
use App\Models\UserSkill;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `user_skills` — Phase 17 / S11 of
 * docs/woms-expansion-plan.md.
 *
 * The table is the m:n join between users and skills with extra columns for
 * proficiency_level + cert dates. The dispatch board (Phase 17 / M10) reads
 * this through usersForSkill() to suggest assignees who can do the work.
 *
 * grant() upserts on (user_id, skill_id) so callers can re-issue the same
 * write idempotently — useful for cert renewals which just bump expires_at.
 */
class UserSkillRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, UserSkill>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM user_skills WHERE user_id = :uid
              ORDER BY skill_id ASC'
        );
        $stmt->execute(['uid' => $userId]);
        return array_map(
            static fn (array $row) => UserSkill::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Bulk-fetch keyed by user_id.
     *
     * @param array<int, int> $userIds
     * @return array<int, array<int, UserSkill>>
     */
    public function listForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM user_skills WHERE user_id IN (' . $placeholders . ')
              ORDER BY user_id ASC, skill_id ASC'
        );
        $stmt->execute($userIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $us = UserSkill::fromRow($row);
            $out[$us->user_id][] = $us;
        }
        return $out;
    }

    /**
     * @return array<int, UserSkill>
     */
    public function listForSkill(int $skillId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM user_skills WHERE skill_id = :sid
              ORDER BY user_id ASC'
        );
        $stmt->execute(['sid' => $skillId]);
        return array_map(
            static fn (array $row) => UserSkill::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function find(int $userId, int $skillId): ?UserSkill
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM user_skills WHERE user_id = :uid AND skill_id = :sid LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'sid' => $skillId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? UserSkill::fromRow($row) : null;
    }

    /**
     * Upsert — insert if missing, update if present. Idempotent on
     * (user_id, skill_id).
     *
     * @param array{proficiency_level?: string, certified_at?: ?string,
     *              expires_at?: ?string, notes?: ?string} $data
     */
    public function grant(int $userId, int $skillId, array $data = []): UserSkill
    {
        if ($userId <= 0 || $skillId <= 0) {
            throw new InvalidArgumentException('user_id and skill_id are required');
        }
        $proficiency = (string) ($data['proficiency_level'] ?? UserSkill::PROFICIENCY_COMPETENT);
        if (!in_array($proficiency, UserSkill::ALLOWED_PROFICIENCY_LEVELS, true)) {
            throw new InvalidArgumentException("Invalid proficiency_level '{$proficiency}'");
        }
        $certifiedAt = $this->nullableDate($data['certified_at'] ?? null);
        $expiresAt = $this->nullableDate($data['expires_at'] ?? null);
        $notes = $this->nullableString($data['notes'] ?? null);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO user_skills
                (user_id, skill_id, proficiency_level, certified_at, expires_at, notes)
             VALUES
                (:uid, :sid, :prof, :certified_at, :expires_at, :notes)
             ON DUPLICATE KEY UPDATE
                proficiency_level = VALUES(proficiency_level),
                certified_at = VALUES(certified_at),
                expires_at = VALUES(expires_at),
                notes = VALUES(notes)'
        );
        $stmt->execute([
            'uid' => $userId,
            'sid' => $skillId,
            'prof' => $proficiency,
            'certified_at' => $certifiedAt,
            'expires_at' => $expiresAt,
            'notes' => $notes,
        ]);

        $row = $this->find($userId, $skillId);
        if ($row === null) {
            throw new RuntimeException("user_skills row not found after grant for user {$userId} skill {$skillId}");
        }
        return $row;
    }

    public function revoke(int $userId, int $skillId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM user_skills WHERE user_id = :uid AND skill_id = :sid'
        );
        $stmt->execute(['uid' => $userId, 'sid' => $skillId]);
    }

    /**
     * @return array<int, UserSkill>
     */
    public function listExpiringBefore(string $date): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM user_skills
              WHERE expires_at IS NOT NULL AND expires_at <= :d
              ORDER BY expires_at ASC, user_id ASC'
        );
        $stmt->execute(['d' => $date]);
        return array_map(
            static fn (array $row) => UserSkill::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Find user_ids who hold the given skill at or above a minimum proficiency
     * and whose certification (if recorded) hasn't expired. Used by Phase 17 /
     * M10 dispatch matching.
     *
     * @param array<int, string>|null $proficiencyLevels NULL means any level.
     * @return array<int, int>
     */
    public function userIdsForSkill(int $skillId, ?array $proficiencyLevels = null, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $sql = 'SELECT user_id FROM user_skills
                 WHERE skill_id = :sid
                   AND (expires_at IS NULL OR expires_at >= :today)';
        $params = ['sid' => $skillId, 'today' => $today];
        if ($proficiencyLevels !== null && $proficiencyLevels !== []) {
            $placeholders = [];
            foreach (array_values($proficiencyLevels) as $i => $level) {
                $key = 'p' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (string) $level;
            }
            $sql .= ' AND proficiency_level IN (' . implode(',', $placeholders) . ')';
        }
        $sql .= ' ORDER BY user_id ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if (!$ts) {
            throw new InvalidArgumentException('Invalid date value');
        }
        return date('Y-m-d', $ts);
    }
}
