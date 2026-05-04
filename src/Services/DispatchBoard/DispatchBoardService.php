<?php

namespace App\Services\DispatchBoard;

use App\Database\Connection;
use App\Models\UserSkill;
use App\Services\Skills\SkillMatrixService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Phase 17 / M10 of docs/woms-expansion-plan.md — multi-trade dispatch board.
 *
 * Surfaces unassigned + in-progress workorders to the manager and, for each
 * one, computes the list of qualified technicians:
 *
 *   1. Filter the user roster by service_line_id (if the WO has one) — a
 *      tech needs to be a member of that service line via
 *      user_service_lines.
 *   2. If the WO has required_skill_id: intersect with users who hold that
 *      skill at >= min_proficiency_level (and whose cert hasn't expired).
 *   3. Score the remaining candidates so the UI can show a "best match"
 *      ordering — primary_service_line_id match boosts; lower current
 *      assignment count (workload) boosts.
 *
 * The board endpoint returns one combined payload (workorders + candidate
 * roster + counts) so the React kanban can render in one fetch instead of
 * N+1 lookups.
 *
 * assignWorkorder() updates workorders.assigned_technician_id and writes an
 * audit entry. It is intentionally narrow — full reassignment workflow lives
 * in App\Services\Workorder\ReassignmentService for cases that need
 * manager approval. This is the "drag the card to a tech" gesture for the
 * planning board.
 */
class DispatchBoardService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SkillMatrixService $skillMatrix,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Return the dispatch board payload.
     *
     * @param array{
     *     service_line_id?: int,
     *     status?: string|array<int, string>,
     *     priority?: string|array<int, string>,
     *     unassigned_only?: bool,
     *     date_from?: string,
     *     date_to?: string,
     * } $filters
     * @return array{
     *     workorders: array<int, array<string, mixed>>,
     *     candidates: array<int, array<int, array<string, mixed>>>,
     *     technicians: array<int, array<string, mixed>>,
     *     skills: array<int, array<string, mixed>>,
     * }
     */
    public function board(array $filters = []): array
    {
        $workorders = $this->fetchWorkorders($filters);
        $technicianRoster = $this->fetchTechnicianRoster($filters);
        $workloadByTech = $this->fetchOpenWorkloadCounts(array_keys($technicianRoster));

        $skills = $this->fetchSkillsLookup($workorders);
        $candidates = [];
        foreach ($workorders as $wo) {
            $candidates[$wo['id']] = $this->candidatesForWorkorder($wo, $technicianRoster, $workloadByTech);
        }

        return [
            'workorders' => $workorders,
            'candidates' => $candidates,
            'technicians' => array_values($technicianRoster),
            'skills' => array_values($skills),
        ];
    }

    /**
     * Assign a workorder to a technician (or unassign with NULL). Validates
     * that the chosen tech is in the WO's service line; if a skill is
     * required, also validates the cert. Writes an audit entry.
     */
    public function assignWorkorder(int $workorderId, ?int $technicianId, ?int $actorId = null): void
    {
        $pdo = $this->connection->pdo();
        $workorder = $this->loadWorkorderRow($workorderId);
        if ($workorder === null) {
            throw new InvalidArgumentException("workorder {$workorderId} not found");
        }
        if ($technicianId !== null) {
            $this->assertTechnicianEligible($workorder, $technicianId);
        }

        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $stmt = $pdo->prepare(
                'UPDATE workorders SET assigned_technician_id = :tid WHERE id = :id'
            );
            $stmt->bindValue(':id', $workorderId, PDO::PARAM_INT);
            if ($technicianId === null) {
                $stmt->bindValue(':tid', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':tid', $technicianId, PDO::PARAM_INT);
            }
            $stmt->execute();

            $this->audit->log(new AuditEntry(
                'dispatch_board.assigned',
                'workorder',
                (string) $workorderId,
                $actorId,
                [
                    'previous_technician_id' => $workorder['assigned_technician_id'] !== null
                        ? (int) $workorder['assigned_technician_id']
                        : null,
                    'new_technician_id' => $technicianId,
                    'service_line_id' => $workorder['service_line_id'] !== null
                        ? (int) $workorder['service_line_id']
                        : null,
                    'required_skill_id' => $workorder['required_skill_id'] !== null
                        ? (int) $workorder['required_skill_id']
                        : null,
                ]
            ));

            if ($startedTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---- internals -----------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetchWorkorders(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        $statuses = $this->normalizeStatusFilter($filters['status'] ?? null);
        if ($statuses !== []) {
            $placeholders = [];
            foreach ($statuses as $i => $s) {
                $key = 'st' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $s;
            }
            $where[] = 'status IN (' . implode(',', $placeholders) . ')';
        }
        $priorities = $this->normalizeStatusFilter($filters['priority'] ?? null);
        if ($priorities !== []) {
            $placeholders = [];
            foreach ($priorities as $i => $p) {
                $key = 'pr' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $p;
            }
            $where[] = 'priority IN (' . implode(',', $placeholders) . ')';
        }
        if (!empty($filters['service_line_id'])) {
            $where[] = 'service_line_id = :sl';
            $params['sl'] = (int) $filters['service_line_id'];
        }
        if (!empty($filters['unassigned_only'])) {
            $where[] = 'assigned_technician_id IS NULL';
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= :date_from';
            $params['date_from'] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= :date_to';
            $params['date_to'] = (string) $filters['date_to'];
        }

        $sql = 'SELECT id, number, customer_id, service_line_id, required_skill_id,
                       min_proficiency_level, status, priority, type,
                       assigned_technician_id, estimated_completion, started_at,
                       grand_total, created_at, updated_at
                  FROM workorders
                 WHERE ' . implode(' AND ', $where) . '
              ORDER BY FIELD(priority, "urgent", "high", "normal", "low"),
                       (assigned_technician_id IS NOT NULL),
                       created_at DESC
                 LIMIT 500';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'normalizeWorkorderRow'], $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>> keyed by user_id
     */
    private function fetchTechnicianRoster(array $filters): array
    {
        $sql = "SELECT u.id, u.name, u.email, u.role, u.primary_service_line_id, u.active,
                       GROUP_CONCAT(DISTINCT usl.service_line_id) AS service_line_ids
                  FROM users u
                  LEFT JOIN user_service_lines usl ON usl.user_id = u.id
                 WHERE u.active = 1
                   AND u.role IN ('admin', 'manager', 'technician')";
        $params = [];

        if (!empty($filters['service_line_id'])) {
            $sql .= ' AND (u.primary_service_line_id = :sl
                           OR EXISTS (SELECT 1 FROM user_service_lines usl2
                                       WHERE usl2.user_id = u.id AND usl2.service_line_id = :sl))';
            $params['sl'] = (int) $filters['service_line_id'];
        }

        $sql .= ' GROUP BY u.id ORDER BY u.name ASC LIMIT 500';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $serviceLineIds = $r['service_line_ids'] === null || $r['service_line_ids'] === ''
                ? []
                : array_map('intval', explode(',', (string) $r['service_line_ids']));
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'email' => (string) $r['email'],
                'role' => (string) $r['role'],
                'primary_service_line_id' => $r['primary_service_line_id'] !== null
                    ? (int) $r['primary_service_line_id']
                    : null,
                'service_line_ids' => $serviceLineIds,
                'active' => (bool) $r['active'],
            ];
        }
        return $out;
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, int>
     */
    private function fetchOpenWorkloadCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            "SELECT assigned_technician_id, COUNT(*) AS open_count
               FROM workorders
              WHERE assigned_technician_id IN ({$placeholders})
                AND status IN ('pending', 'in_progress', 'on_hold', 'parts_pending', 'awaiting_authorization', 'qc_required')
              GROUP BY assigned_technician_id"
        );
        $stmt->execute($userIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['assigned_technician_id']] = (int) $r['open_count'];
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $workorders
     * @return array<int, array<string, mixed>> keyed by skill id
     */
    private function fetchSkillsLookup(array $workorders): array
    {
        $skillIds = [];
        foreach ($workorders as $wo) {
            if (!empty($wo['required_skill_id'])) {
                $skillIds[(int) $wo['required_skill_id']] = true;
            }
        }
        $skillIds = array_keys($skillIds);
        if ($skillIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($skillIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, slug, name, service_line_id, category FROM skills WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute($skillIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'slug' => (string) $r['slug'],
                'name' => (string) $r['name'],
                'service_line_id' => $r['service_line_id'] !== null ? (int) $r['service_line_id'] : null,
                'category' => $r['category'] !== null ? (string) $r['category'] : null,
            ];
        }
        return $out;
    }

    /**
     * Score and rank candidates for a single workorder.
     *
     * @param array<string, mixed> $workorder
     * @param array<int, array<string, mixed>> $roster
     * @param array<int, int> $workloadByTech
     * @return array<int, array<string, mixed>>
     */
    private function candidatesForWorkorder(array $workorder, array $roster, array $workloadByTech): array
    {
        $serviceLineId = $workorder['service_line_id'] !== null ? (int) $workorder['service_line_id'] : null;
        $skillId = $workorder['required_skill_id'] !== null ? (int) $workorder['required_skill_id'] : null;
        $minProf = $workorder['min_proficiency_level'] !== null && $workorder['min_proficiency_level'] !== ''
            ? (string) $workorder['min_proficiency_level']
            : null;

        $skillHolders = null;
        if ($skillId !== null) {
            $ids = $this->skillMatrix->userIdsForSkill($skillId, $minProf);
            $skillHolders = array_flip($ids);
        }

        $out = [];
        foreach ($roster as $userId => $tech) {
            $matchesLine = $serviceLineId === null
                || $tech['primary_service_line_id'] === $serviceLineId
                || in_array($serviceLineId, $tech['service_line_ids'], true);
            if (!$matchesLine) {
                continue;
            }
            $hasSkill = $skillHolders === null ? null : isset($skillHolders[$userId]);
            if ($skillHolders !== null && !$hasSkill) {
                continue;
            }

            $score = 0;
            $reasons = [];
            if ($serviceLineId !== null && $tech['primary_service_line_id'] === $serviceLineId) {
                $score += 50;
                $reasons[] = 'primary_line';
            } elseif ($serviceLineId !== null) {
                $score += 20;
                $reasons[] = 'secondary_line';
            }
            if ($hasSkill === true) {
                $score += 40;
                $reasons[] = 'skill_match';
            }

            $workload = $workloadByTech[$userId] ?? 0;
            // Penalty: -2 per open WO so a tech with 10 open WOs is ranked
            // below an idle tech with no other strong signals.
            $score -= min(40, $workload * 2);

            $out[] = [
                'user_id' => $userId,
                'name' => $tech['name'],
                'email' => $tech['email'],
                'primary_service_line_id' => $tech['primary_service_line_id'],
                'open_workorder_count' => $workload,
                'has_required_skill' => $hasSkill,
                'score' => $score,
                'match_reasons' => $reasons,
            ];
        }

        usort($out, static fn (array $a, array $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, 20);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeWorkorderRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'number' => (string) $row['number'],
            'customer_id' => (int) $row['customer_id'],
            'service_line_id' => $row['service_line_id'] !== null ? (int) $row['service_line_id'] : null,
            'required_skill_id' => $row['required_skill_id'] !== null ? (int) $row['required_skill_id'] : null,
            'min_proficiency_level' => $row['min_proficiency_level'] !== null
                ? (string) $row['min_proficiency_level']
                : null,
            'status' => (string) $row['status'],
            'priority' => (string) $row['priority'],
            'type' => (string) $row['type'],
            'assigned_technician_id' => $row['assigned_technician_id'] !== null
                ? (int) $row['assigned_technician_id']
                : null,
            'estimated_completion' => $row['estimated_completion'],
            'started_at' => $row['started_at'],
            'grand_total' => (float) $row['grand_total'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function loadWorkorderRow(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, service_line_id, required_skill_id, min_proficiency_level,
                    assigned_technician_id, status
               FROM workorders WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function assertTechnicianEligible(array $workorder, int $technicianId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT u.id, u.role, u.active, u.primary_service_line_id,
                    GROUP_CONCAT(DISTINCT usl.service_line_id) AS service_line_ids
               FROM users u
               LEFT JOIN user_service_lines usl ON usl.user_id = u.id
              WHERE u.id = :id
              GROUP BY u.id"
        );
        $stmt->execute(['id' => $technicianId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new InvalidArgumentException("technician {$technicianId} not found");
        }
        if (!$row['active']) {
            throw new InvalidArgumentException("technician {$technicianId} is inactive");
        }
        if (!in_array($row['role'], ['admin', 'manager', 'technician'], true)) {
            throw new InvalidArgumentException("user {$technicianId} cannot be assigned to workorders");
        }

        $serviceLineId = $workorder['service_line_id'] !== null ? (int) $workorder['service_line_id'] : null;
        if ($serviceLineId !== null) {
            $serviceLineIds = $row['service_line_ids'] === null || $row['service_line_ids'] === ''
                ? []
                : array_map('intval', explode(',', (string) $row['service_line_ids']));
            $primary = $row['primary_service_line_id'] !== null ? (int) $row['primary_service_line_id'] : null;
            $matches = $primary === $serviceLineId || in_array($serviceLineId, $serviceLineIds, true);
            if (!$matches) {
                throw new InvalidArgumentException(
                    "technician {$technicianId} is not a member of service line {$serviceLineId}"
                );
            }
        }

        $skillId = $workorder['required_skill_id'] !== null ? (int) $workorder['required_skill_id'] : null;
        if ($skillId !== null) {
            $minProf = $workorder['min_proficiency_level'] !== null && $workorder['min_proficiency_level'] !== ''
                ? (string) $workorder['min_proficiency_level']
                : null;
            if ($minProf !== null && !in_array($minProf, UserSkill::ALLOWED_PROFICIENCY_LEVELS, true)) {
                throw new InvalidArgumentException("workorder min_proficiency_level '{$minProf}' is not valid");
            }
            $holders = $this->skillMatrix->userIdsForSkill($skillId, $minProf);
            if (!in_array($technicianId, $holders, true)) {
                throw new InvalidArgumentException(
                    "technician {$technicianId} does not hold required skill {$skillId}"
                    . ($minProf !== null ? " at >= {$minProf}" : '')
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStatusFilter(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            return [trim($value)];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $out[] = $v;
                }
            }
            return $out;
        }
        return [];
    }
}
