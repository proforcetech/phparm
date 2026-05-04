<?php

namespace App\Services\Skills;

use App\Database\Connection;
use App\Models\Skill;
use App\Models\UserSkill;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use Throwable;

/**
 * Phase 17 / S11 of docs/woms-expansion-plan.md — orchestrates the technician
 * skill matrix.
 *
 * The repos do the SQL; this service wraps them with transaction discipline
 * and the audit-ledger writes that survive an HTTP/browser round-trip. Skill
 * grants/revokes are recorded under entity_type='user_skill' so we can later
 * answer "who granted this cert and when" without a separate change log.
 *
 * The dispatch board (Phase 17 / M10) calls usersForSkill() to filter
 * assignment candidates by required competency.
 */
class SkillMatrixService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SkillRepository $skills,
        private readonly UserSkillRepository $userSkills,
        private readonly AuditLogger $audit,
    ) {
    }

    // ---- catalog -------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function createSkill(array $data, ?int $actorId = null): Skill
    {
        return $this->withTransaction(function () use ($data, $actorId): Skill {
            $skill = $this->skills->create($data);
            $this->audit->log(new AuditEntry(
                'skill.created',
                'skill',
                (string) $skill->id,
                $actorId,
                ['slug' => $skill->slug, 'name' => $skill->name]
            ));
            return $skill;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateSkill(int $id, array $data, ?int $actorId = null): Skill
    {
        return $this->withTransaction(function () use ($id, $data, $actorId): Skill {
            $before = $this->skills->findById($id);
            $skill = $this->skills->update($id, $data);
            $this->audit->log(new AuditEntry(
                'skill.updated',
                'skill',
                (string) $skill->id,
                $actorId,
                [
                    'before' => $before?->toArray(),
                    'after' => $skill->toArray(),
                ]
            ));
            return $skill;
        });
    }

    public function deleteSkill(int $id, ?int $actorId = null): void
    {
        $this->withTransaction(function () use ($id, $actorId): void {
            $before = $this->skills->findById($id);
            if ($before === null) {
                return;
            }
            $this->skills->delete($id);
            $this->audit->log(new AuditEntry(
                'skill.deleted',
                'skill',
                (string) $id,
                $actorId,
                ['slug' => $before->slug, 'name' => $before->name]
            ));
        });
    }

    // ---- assignments ---------------------------------------------------

    /**
     * @param array{proficiency_level?: string, certified_at?: ?string,
     *              expires_at?: ?string, notes?: ?string} $data
     */
    public function grantSkill(int $userId, int $skillId, array $data = [], ?int $actorId = null): UserSkill
    {
        return $this->withTransaction(function () use ($userId, $skillId, $data, $actorId): UserSkill {
            $existing = $this->userSkills->find($userId, $skillId);
            $row = $this->userSkills->grant($userId, $skillId, $data);
            $this->audit->log(new AuditEntry(
                $existing === null ? 'user_skill.granted' : 'user_skill.updated',
                'user_skill',
                (string) $row->id,
                $actorId,
                [
                    'user_id' => $userId,
                    'skill_id' => $skillId,
                    'before' => $existing?->toArray(),
                    'after' => $row->toArray(),
                ]
            ));
            return $row;
        });
    }

    public function revokeSkill(int $userId, int $skillId, ?int $actorId = null): void
    {
        $this->withTransaction(function () use ($userId, $skillId, $actorId): void {
            $existing = $this->userSkills->find($userId, $skillId);
            if ($existing === null) {
                return;
            }
            $this->userSkills->revoke($userId, $skillId);
            $this->audit->log(new AuditEntry(
                'user_skill.revoked',
                'user_skill',
                (string) $existing->id,
                $actorId,
                [
                    'user_id' => $userId,
                    'skill_id' => $skillId,
                    'before' => $existing->toArray(),
                ]
            ));
        });
    }

    // ---- queries -------------------------------------------------------

    /**
     * Build a {user_id => [skill_id => UserSkill]} matrix for the given
     * users. Used by the React matrix view to render a grid in one fetch.
     *
     * @param array<int, int> $userIds
     * @return array<int, array<int, UserSkill>>
     */
    public function matrixForUsers(array $userIds): array
    {
        $rows = $this->userSkills->listForUsers($userIds);
        $out = [];
        foreach ($rows as $userId => $list) {
            $byId = [];
            foreach ($list as $us) {
                $byId[$us->skill_id] = $us;
            }
            $out[$userId] = $byId;
        }
        return $out;
    }

    /**
     * IDs of users who hold the skill at >= the minimum proficiency and
     * whose certification (if recorded) is still valid. Phase 17 / M10
     * uses this to filter dispatch candidates.
     *
     * @return array<int, int>
     */
    public function userIdsForSkill(int $skillId, ?string $minProficiency = null, ?string $today = null): array
    {
        $levels = null;
        if ($minProficiency !== null) {
            $levels = $this->levelsAtOrAbove($minProficiency);
        }
        return $this->userSkills->userIdsForSkill($skillId, $levels, $today);
    }

    /**
     * @return array<int, string>
     */
    private function levelsAtOrAbove(string $minimum): array
    {
        $order = [
            UserSkill::PROFICIENCY_LEARNER => 1,
            UserSkill::PROFICIENCY_COMPETENT => 2,
            UserSkill::PROFICIENCY_EXPERT => 3,
        ];
        $threshold = $order[$minimum] ?? 1;
        $out = [];
        foreach ($order as $level => $rank) {
            if ($rank >= $threshold) {
                $out[] = $level;
            }
        }
        return $out;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function withTransaction(callable $work)
    {
        $pdo = $this->connection->pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $result = $work();
            if ($startedTx) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
