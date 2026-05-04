<?php

namespace App\Services\Skills;

use App\Database\Connection;
use App\Models\Skill;
use App\Models\User;
use App\Models\UserSkill;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;
use PDO;

/**
 * HTTP controller for /api/skills + /api/users/{id}/skills — Phase 17 / S11
 * of docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   skills.view   — read the catalog + per-user matrix
 *   skills.manage — create/edit/delete catalog entries; grant/revoke per user
 *
 * The matrix() endpoint returns one combined payload (catalog + technician
 * roster + assignments grid) so the React view can render the table in one
 * fetch instead of N+1.
 */
class SkillMatrixController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SkillRepository $skills,
        private readonly UserSkillRepository $userSkills,
        private readonly SkillMatrixService $service,
        private readonly AccessGate $gate,
    ) {
    }

    // ---- catalog -------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{skills: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 250): array
    {
        $this->gate->assert($user, 'skills.view');

        $page = max(1, $page);
        $perPage = min(1000, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->skills->list($filters, $perPage, $offset);
        $total = $this->skills->count($filters);

        return [
            'skills' => array_map(
                static fn (Skill $row) => self::skillToArray($row),
                $rows
            ),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->gate->assert($user, 'skills.view');

        $skill = $this->skills->findById($id);
        if ($skill === null) {
            throw new InvalidArgumentException("skill {$id} not found");
        }
        $assignments = $this->userSkills->listForSkill($id);
        $payload = self::skillToArray($skill);
        $payload['assignments'] = array_map(
            [self::class, 'userSkillToArray'],
            $assignments
        );
        $payload['assignment_count'] = count($assignments);
        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->gate->assert($user, 'skills.manage');

        $skill = $this->service->createSkill($data, (int) $user->id);
        return self::skillToArray($skill);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $data): array
    {
        $this->gate->assert($user, 'skills.manage');

        $skill = $this->service->updateSkill($id, $data, (int) $user->id);
        return self::skillToArray($skill);
    }

    public function destroy(User $user, int $id): void
    {
        $this->gate->assert($user, 'skills.manage');

        $this->service->deleteSkill($id, (int) $user->id);
    }

    // ---- assignments ---------------------------------------------------

    /**
     * Per-user list — used by the per-technician detail view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(User $user, int $userId): array
    {
        $this->gate->assert($user, 'skills.view');

        $assignments = $this->userSkills->listForUser($userId);
        $skillsById = $this->skills->findManyById(
            array_map(static fn (UserSkill $us) => $us->skill_id, $assignments)
        );
        return array_map(
            static fn (UserSkill $us) => self::userSkillWithSkill($us, $skillsById[$us->skill_id] ?? null),
            $assignments
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function grant(User $user, int $userId, int $skillId, array $data = []): array
    {
        $this->gate->assert($user, 'skills.manage');

        $row = $this->service->grantSkill($userId, $skillId, $data, (int) $user->id);
        return self::userSkillToArray($row);
    }

    public function revoke(User $user, int $userId, int $skillId): void
    {
        $this->gate->assert($user, 'skills.manage');

        $this->service->revokeSkill($userId, $skillId, (int) $user->id);
    }

    /**
     * Combined matrix payload: catalog of skills + roster of relevant users +
     * assignments grid keyed by user_id then skill_id. Lets the React grid
     * render in one fetch.
     *
     * @param array{role?: string|array<int, string>, search?: string,
     *              service_line_id?: int} $filters
     * @return array{users: array<int, array<string, mixed>>,
     *               skills: array<int, array<string, mixed>>,
     *               assignments: array<int, array<int, array<string, mixed>>>}
     */
    public function matrix(User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'skills.view');

        $users = $this->fetchUsers($filters);
        $skillFilters = [];
        if (array_key_exists('service_line_id', $filters)) {
            $skillFilters['service_line_id'] = $filters['service_line_id'];
        }
        $skillFilters['is_active'] = true;
        $skills = $this->skills->list($skillFilters, 1000, 0);

        $userIds = array_map(static fn (array $u) => (int) $u['id'], $users);
        $rawMatrix = $this->service->matrixForUsers($userIds);
        $matrix = [];
        foreach ($rawMatrix as $userId => $bySkill) {
            $byId = [];
            foreach ($bySkill as $skillId => $us) {
                $byId[$skillId] = self::userSkillToArray($us);
            }
            $matrix[$userId] = $byId;
        }

        return [
            'users' => $users,
            'skills' => array_map(
                static fn (Skill $s) => self::skillToArray($s),
                $skills
            ),
            'assignments' => $matrix,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function skillToArray(Skill $row): array
    {
        return [
            'id' => $row->id,
            'slug' => $row->slug,
            'name' => $row->name,
            'description' => $row->description,
            'service_line_id' => $row->service_line_id,
            'category' => $row->category,
            'sort_order' => $row->sort_order,
            'is_active' => $row->is_active,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function userSkillToArray(UserSkill $row): array
    {
        return [
            'id' => $row->id,
            'user_id' => $row->user_id,
            'skill_id' => $row->skill_id,
            'proficiency_level' => $row->proficiency_level,
            'certified_at' => $row->certified_at,
            'expires_at' => $row->expires_at,
            'is_expired' => $row->isExpired(),
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function userSkillWithSkill(UserSkill $row, ?Skill $skill): array
    {
        $payload = self::userSkillToArray($row);
        $payload['skill'] = $skill ? self::skillToArray($skill) : null;
        return $payload;
    }

    /**
     * Roster of staff who can hold skills — admins, managers, and
     * technicians by default. Optional role + search filters.
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetchUsers(array $filters): array
    {
        $roles = $filters['role'] ?? ['admin', 'manager', 'technician'];
        if (is_string($roles)) {
            $roles = [$roles];
        }
        $roles = array_values(array_filter(array_map('strval', (array) $roles)));
        if ($roles === []) {
            $roles = ['admin', 'manager', 'technician'];
        }

        $rolePlaceholders = [];
        $params = [];
        foreach ($roles as $i => $role) {
            $key = 'r' . $i;
            $rolePlaceholders[] = ':' . $key;
            $params[$key] = $role;
        }

        $sql = 'SELECT id, name, email, role, primary_service_line_id, active
                  FROM users
                 WHERE active = 1
                   AND role IN (' . implode(',', $rolePlaceholders) . ')';

        if (!empty($filters['search'])) {
            $sql .= ' AND (name LIKE :search OR email LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['primary_service_line_id'])) {
            $sql .= ' AND primary_service_line_id = :primary_service_line_id';
            $params['primary_service_line_id'] = (int) $filters['primary_service_line_id'];
        }

        $sql .= ' ORDER BY name ASC, id ASC LIMIT 500';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(
            static fn (array $r) => [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'email' => (string) $r['email'],
                'role' => (string) $r['role'],
                'primary_service_line_id' => $r['primary_service_line_id'] !== null ? (int) $r['primary_service_line_id'] : null,
                'active' => (bool) $r['active'],
            ],
            $rows
        );
    }
}
