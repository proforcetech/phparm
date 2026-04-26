<?php

namespace App\Services\Workorder;

use App\Database\Connection;
use App\Models\WorkorderReassignmentHistory;
use App\Models\WorkorderReassignmentRequest;
use PDO;
use RuntimeException;

/**
 * Phase 10.4 — persistence for workorder_reassignment_requests + the related
 * append-only workorder_reassignment_history audit log. Mirrors the
 * COLUMNS-const + private-hydrate pattern used by other Phase-10 repos.
 *
 * Two logical surfaces share one connection:
 *   request CRUD + lifecycle column writes
 *   history append + per-WO history read
 *
 * Also exposes loadCurrentAssignee() so the service can snapshot the WO's
 * primary tech at request creation time, and applyAssignment() to perform
 * the transactional swap (workorders.assigned_technician_id update + history
 * insert) at fulfilment time.
 */
class ReassignmentRepository
{
    private const REQUEST_COLUMNS = 'id, workorder_id, requested_by_user_id,
        current_assignee_user_id, proposed_assignee_user_id,
        reassignment_reason, reason, urgency, status, requested_at,
        approved_by_user_id, approved_at, declined_by_user_id, declined_at,
        cancelled_by_user_id, cancelled_at, fulfilled_by_user_id, fulfilled_at,
        new_assignee_user_id, rejection_reason, notes, created_at, updated_at';

    private const HISTORY_COLUMNS = 'id, workorder_id, request_id, from_user_id,
        to_user_id, reassigned_by_user_id, reassigned_at, reason, notes, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    // ──────────────────────────────────────────────── requests ────

    /**
     * @return array<int, WorkorderReassignmentRequest>
     */
    public function listRequestsForWorkorder(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM workorder_reassignment_requests
             WHERE workorder_id = :w
             ORDER BY id DESC'
        );
        $stmt->execute(['w' => $workorderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new WorkorderReassignmentRequest($r), $rows);
    }

    /**
     * @param array{status?: string, urgency?: string, requested_by_user_id?: int, reassignment_reason?: string, limit?: int, offset?: int} $filters
     * @return array<int, WorkorderReassignmentRequest>
     */
    public function listRequests(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['urgency'])) {
            $where[] = 'urgency = :urg';
            $params['urg'] = (string) $filters['urgency'];
        }
        if (!empty($filters['requested_by_user_id'])) {
            $where[] = 'requested_by_user_id = :req';
            $params['req'] = (int) $filters['requested_by_user_id'];
        }
        if (!empty($filters['reassignment_reason'])) {
            $where[] = 'reassignment_reason = :rsn';
            $params['rsn'] = (string) $filters['reassignment_reason'];
        }
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = implode(' AND ', $where);

        $sql = 'SELECT ' . self::REQUEST_COLUMNS . " FROM workorder_reassignment_requests
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new WorkorderReassignmentRequest($r), $rows);
    }

    public function findRequest(int $id): ?WorkorderReassignmentRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::REQUEST_COLUMNS . ' FROM workorder_reassignment_requests
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new WorkorderReassignmentRequest($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createRequest(array $data): WorkorderReassignmentRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO workorder_reassignment_requests
             (workorder_id, requested_by_user_id, current_assignee_user_id,
              proposed_assignee_user_id, reassignment_reason, reason, urgency,
              status, requested_at, notes)
             VALUES
             (:workorder_id, :requested_by_user_id, :current_assignee_user_id,
              :proposed_assignee_user_id, :reassignment_reason, :reason, :urgency,
              :status, :requested_at, :notes)'
        );
        $stmt->execute([
            'workorder_id' => (int) ($data['workorder_id'] ?? 0),
            'requested_by_user_id' => (int) ($data['requested_by_user_id'] ?? 0),
            'current_assignee_user_id' => self::nullableInt($data['current_assignee_user_id'] ?? null),
            'proposed_assignee_user_id' => self::nullableInt($data['proposed_assignee_user_id'] ?? null),
            'reassignment_reason' => (string) ($data['reassignment_reason'] ?? WorkorderReassignmentRequest::REASON_OTHER),
            'reason' => (string) ($data['reason'] ?? ''),
            'urgency' => (string) ($data['urgency'] ?? WorkorderReassignmentRequest::URGENCY_NORMAL),
            'status' => (string) ($data['status'] ?? WorkorderReassignmentRequest::STATUS_PENDING),
            'requested_at' => self::nullableString($data['requested_at'] ?? null),
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findRequest($id);
        if ($found === null) {
            throw new RuntimeException('workorder_reassignment_requests insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateRequest(int $id, array $data): WorkorderReassignmentRequest
    {
        $writable = ['proposed_assignee_user_id', 'reassignment_reason', 'reason',
            'urgency', 'status', 'requested_at',
            'approved_by_user_id', 'approved_at',
            'declined_by_user_id', 'declined_at',
            'cancelled_by_user_id', 'cancelled_at',
            'fulfilled_by_user_id', 'fulfilled_at', 'new_assignee_user_id',
            'rejection_reason', 'notes'];
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
            $sql = 'UPDATE workorder_reassignment_requests SET ' . implode(', ', $fields)
                . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findRequest($id);
        if ($found === null) {
            throw new RuntimeException("workorder_reassignment_requests {$id} not found");
        }
        return $found;
    }

    public function deleteRequest(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM workorder_reassignment_requests WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────────── workorder bridge ────

    /**
     * Read the current primary tech off a workorder row. Used to snapshot
     * current_assignee_user_id at request creation time and to source the
     * from_user_id at fulfilment time.
     */
    public function loadCurrentAssignee(int $workorderId): ?int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT assigned_technician_id FROM workorders WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $workorderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $row['assigned_technician_id'] === null
            ? null
            : (int) $row['assigned_technician_id'];
    }

    /**
     * Atomically update workorders.assigned_technician_id and append a
     * history row. Used by both the request-fulfilment path (request_id
     * populated) and the manager-direct path (request_id null).
     */
    public function applyAssignment(
        int $workorderId,
        ?int $fromUserId,
        int $toUserId,
        int $reassignedByUserId,
        string $reassignedAt,
        ?int $requestId,
        ?string $reason,
        ?string $notes
    ): WorkorderReassignmentHistory {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare(
                'UPDATE workorders SET assigned_technician_id = :to,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $upd->execute(['to' => $toUserId, 'id' => $workorderId]);

            $hist = $this->insertHistory([
                'workorder_id' => $workorderId,
                'request_id' => $requestId,
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'reassigned_by_user_id' => $reassignedByUserId,
                'reassigned_at' => $reassignedAt,
                'reason' => $reason,
                'notes' => $notes,
            ]);

            $pdo->commit();
            return $hist;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────── history ────

    /**
     * @return array<int, WorkorderReassignmentHistory>
     */
    public function listHistoryForWorkorder(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::HISTORY_COLUMNS . ' FROM workorder_reassignment_history
             WHERE workorder_id = :w
             ORDER BY reassigned_at ASC, id ASC'
        );
        $stmt->execute(['w' => $workorderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new WorkorderReassignmentHistory($r), $rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertHistory(array $data): WorkorderReassignmentHistory
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO workorder_reassignment_history
             (workorder_id, request_id, from_user_id, to_user_id,
              reassigned_by_user_id, reassigned_at, reason, notes)
             VALUES
             (:workorder_id, :request_id, :from_user_id, :to_user_id,
              :reassigned_by_user_id, :reassigned_at, :reason, :notes)'
        );
        $stmt->execute([
            'workorder_id' => (int) $data['workorder_id'],
            'request_id' => self::nullableInt($data['request_id']),
            'from_user_id' => self::nullableInt($data['from_user_id']),
            'to_user_id' => (int) $data['to_user_id'],
            'reassigned_by_user_id' => self::nullableInt($data['reassigned_by_user_id']),
            'reassigned_at' => (string) $data['reassigned_at'],
            'reason' => self::nullableString($data['reason']),
            'notes' => self::nullableString($data['notes']),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::HISTORY_COLUMNS . ' FROM workorder_reassignment_history
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('workorder_reassignment_history insert did not return a row');
        }
        return new WorkorderReassignmentHistory($row);
    }

    // ─────────────────────────────────────────────── helpers ────

    private static function castRequestColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'proposed_assignee_user_id', 'approved_by_user_id', 'declined_by_user_id',
            'cancelled_by_user_id', 'fulfilled_by_user_id',
            'new_assignee_user_id' => self::nullableInt($value),
            'reason' => (string) ($value ?? ''),
            'reassignment_reason', 'urgency', 'status' => (string) ($value ?? ''),
            'rejection_reason', 'notes',
            'requested_at', 'approved_at', 'declined_at',
            'cancelled_at', 'fulfilled_at' => self::nullableString($value),
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

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;
        return $s === '' ? null : $s;
    }
}
