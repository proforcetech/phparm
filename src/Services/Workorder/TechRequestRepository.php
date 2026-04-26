<?php

namespace App\Services\Workorder;

use App\Database\Connection;
use App\Models\WorkorderAdditionalTech;
use App\Models\WorkorderTechRequest;
use PDO;
use RuntimeException;

/**
 * Phase 10.3 — persistence for workorder_tech_requests + the related
 * workorder_additional_techs assignment table. Mirrors the COLUMNS-const +
 * private-hydrate pattern used by other Phase-10 repos.
 *
 * Two logical surfaces share one connection:
 *   request CRUD + lifecycle column writes
 *   additional-tech assignment CRUD with soft-removal helpers
 */
class TechRequestRepository
{
    private const REQUEST_COLUMNS = 'id, workorder_id, requested_by_user_id, request_type,
        reason, estimated_hours, skills_needed, urgency, status,
        requested_at, approved_by_user_id, approved_at, declined_at,
        cancelled_at, fulfilled_at, fulfilled_user_id, rejection_reason,
        notes, created_at, updated_at';

    private const TECH_COLUMNS = 'id, workorder_id, user_id, request_id, tech_role,
        added_at, added_by_user_id, removed_at, removed_by_user_id,
        removal_reason, notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    // ──────────────────────────────────────────────── requests ────

    /**
     * @return array<int, WorkorderTechRequest>
     */
    public function listRequestsForWorkorder(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::REQUEST_COLUMNS . " FROM workorder_tech_requests
             WHERE workorder_id = :w
             ORDER BY id DESC"
        );
        $stmt->execute(['w' => $workorderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new WorkorderTechRequest($r), $rows);
    }

    /**
     * @param array{status?: string, requested_by_user_id?: int, urgency?: string, limit?: int, offset?: int} $filters
     * @return array<int, WorkorderTechRequest>
     */
    public function listRequests(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['requested_by_user_id'])) {
            $where[] = 'requested_by_user_id = :req';
            $params['req'] = (int) $filters['requested_by_user_id'];
        }
        if (!empty($filters['urgency'])) {
            $where[] = 'urgency = :urg';
            $params['urg'] = (string) $filters['urgency'];
        }
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = implode(' AND ', $where);

        $sql = 'SELECT ' . self::REQUEST_COLUMNS . " FROM workorder_tech_requests
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new WorkorderTechRequest($r), $rows);
    }

    public function findRequest(int $id): ?WorkorderTechRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM workorder_tech_requests
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new WorkorderTechRequest($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRequest(array $data): WorkorderTechRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO workorder_tech_requests
             (workorder_id, requested_by_user_id, request_type, reason,
              estimated_hours, skills_needed, urgency, status,
              requested_at, notes)
             VALUES
             (:workorder_id, :requested_by_user_id, :request_type, :reason,
              :estimated_hours, :skills_needed, :urgency, :status,
              :requested_at, :notes)'
        );
        $stmt->execute([
            'workorder_id' => (int) ($data['workorder_id'] ?? 0),
            'requested_by_user_id' => (int) ($data['requested_by_user_id'] ?? 0),
            'request_type' => (string) ($data['request_type'] ?? WorkorderTechRequest::TYPE_EXTRA_HANDS),
            'reason' => (string) ($data['reason'] ?? ''),
            'estimated_hours' => self::nullableFloat($data['estimated_hours'] ?? null),
            'skills_needed' => self::nullableString($data['skills_needed'] ?? null),
            'urgency' => (string) ($data['urgency'] ?? WorkorderTechRequest::URGENCY_NORMAL),
            'status' => (string) ($data['status'] ?? WorkorderTechRequest::STATUS_PENDING),
            'requested_at' => self::nullableString($data['requested_at'] ?? null),
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findRequest($id);
        if ($found === null) {
            throw new RuntimeException('workorder_tech_requests insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateRequest(int $id, array $data): WorkorderTechRequest
    {
        $writable = ['request_type', 'reason', 'estimated_hours', 'skills_needed',
            'urgency', 'status', 'requested_at', 'approved_by_user_id',
            'approved_at', 'declined_at', 'cancelled_at', 'fulfilled_at',
            'fulfilled_user_id', 'rejection_reason', 'notes'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $fields[] = "{$col} = :{$col}";
            $params[$col] = self::castRequestColumn($col, $data[$col]);
        }
        if ($fields !== []) {
            $sql = 'UPDATE workorder_tech_requests SET ' . implode(', ', $fields)
                . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findRequest($id);
        if ($found === null) {
            throw new RuntimeException("workorder_tech_requests {$id} not found");
        }
        return $found;
    }

    public function deleteRequest(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM workorder_tech_requests WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────── additional techs ────────

    /**
     * @return array<int, WorkorderAdditionalTech>
     */
    public function listTechsForWorkorder(int $workorderId, bool $activeOnly = false): array
    {
        $where = 'workorder_id = :w';
        if ($activeOnly) {
            $where .= ' AND removed_at IS NULL';
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::TECH_COLUMNS . " FROM workorder_additional_techs
             WHERE {$where}
             ORDER BY id ASC"
        );
        $stmt->execute(['w' => $workorderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new WorkorderAdditionalTech($r), $rows);
    }

    public function findTech(int $id): ?WorkorderAdditionalTech
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::TECH_COLUMNS . ' FROM workorder_additional_techs
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new WorkorderAdditionalTech($row) : null;
    }

    public function findActiveTech(int $workorderId, int $userId): ?WorkorderAdditionalTech
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::TECH_COLUMNS . ' FROM workorder_additional_techs
             WHERE workorder_id = :w AND user_id = :u AND removed_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['w' => $workorderId, 'u' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new WorkorderAdditionalTech($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createTech(array $data): WorkorderAdditionalTech
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO workorder_additional_techs
             (workorder_id, user_id, request_id, tech_role,
              added_at, added_by_user_id, notes)
             VALUES
             (:workorder_id, :user_id, :request_id, :tech_role,
              :added_at, :added_by_user_id, :notes)'
        );
        $stmt->execute([
            'workorder_id' => (int) ($data['workorder_id'] ?? 0),
            'user_id' => (int) ($data['user_id'] ?? 0),
            'request_id' => self::nullableInt($data['request_id'] ?? null),
            'tech_role' => (string) ($data['tech_role'] ?? WorkorderAdditionalTech::ROLE_SECONDARY),
            'added_at' => self::nullableString($data['added_at'] ?? null),
            'added_by_user_id' => self::nullableInt($data['added_by_user_id'] ?? null),
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findTech($id);
        if ($found === null) {
            throw new RuntimeException('workorder_additional_techs insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateTech(int $id, array $data): WorkorderAdditionalTech
    {
        $writable = ['tech_role', 'removed_at', 'removed_by_user_id',
            'removal_reason', 'notes'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $fields[] = "{$col} = :{$col}";
            $params[$col] = self::castTechColumn($col, $data[$col]);
        }
        if ($fields !== []) {
            $sql = 'UPDATE workorder_additional_techs SET ' . implode(', ', $fields)
                . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findTech($id);
        if ($found === null) {
            throw new RuntimeException("workorder_additional_techs {$id} not found");
        }
        return $found;
    }

    // ───────────────────────────────────────────── helpers ────

    private static function castRequestColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'estimated_hours' => self::nullableFloat($value),
            'approved_by_user_id', 'fulfilled_user_id' => self::nullableInt($value),
            'reason' => (string) ($value ?? ''),
            'request_type', 'urgency', 'status' => (string) ($value ?? ''),
            'skills_needed', 'rejection_reason', 'notes',
            'requested_at', 'approved_at', 'declined_at',
            'cancelled_at', 'fulfilled_at' => self::nullableString($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function castTechColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'removed_by_user_id' => self::nullableInt($value),
            'tech_role' => (string) ($value ?? ''),
            'removal_reason', 'notes', 'removed_at' => self::nullableString($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;
        return $s === '' ? null : $s;
    }
}
