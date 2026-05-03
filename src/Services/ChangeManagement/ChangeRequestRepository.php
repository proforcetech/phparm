<?php

namespace App\Services\ChangeManagement;

use App\Database\Connection;
use App\Models\ChangeRequest;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `change_requests` — Phase 14 / S3 of
 * docs/woms-expansion-plan.md.
 *
 * State transitions MUST go through ChangeRequestService::transition() so
 * the timestamp columns (submitted_at, decision_at, completed_at, etc.)
 * stay in sync with status and the audit trail captures the move. This
 * repository's update() refuses direct status writes for that reason.
 */
class ChangeRequestRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id?: int, status?: string, change_type?: string,
     *              risk_level?: string, requested_by_user_id?: int,
     *              assigned_to_user_id?: int, originating_ticket_id?: int,
     *              affected_site_asset_id?: int,
     *              window_start?: string, window_end?: string} $filters
     * @return array<int, ChangeRequest>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM change_requests ' . $where
            . ' ORDER BY COALESCE(planned_start_at, created_at) DESC, id DESC'
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => ChangeRequest::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM change_requests ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?ChangeRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM change_requests WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ChangeRequest::fromRow($row) : null;
    }

    /**
     * Locking read used by ChangeRequestService::transition() so concurrent
     * decision/start/complete attempts on the same RFC serialize. Must be
     * called inside a transaction or the lock has no effect.
     */
    public function findByIdForUpdate(int $id): ?ChangeRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM change_requests WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ChangeRequest::fromRow($row) : null;
    }

    /**
     * Change-window calendar query — RFCs whose planned window overlaps the
     * given range. Used by the scheduling view to paint the week.
     *
     * @return array<int, ChangeRequest>
     */
    public function listInWindow(string $start, string $end): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM change_requests
              WHERE planned_start_at IS NOT NULL
                AND planned_end_at IS NOT NULL
                AND planned_start_at < :end
                AND planned_end_at > :start
              ORDER BY planned_start_at ASC'
        );
        $stmt->execute(['start' => $start, 'end' => $end]);
        return array_map(
            static fn (array $row) => ChangeRequest::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): ChangeRequest
    {
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $requestedBy = (int) ($data['requested_by_user_id'] ?? 0);
        if ($title === '' || $description === '' || $requestedBy <= 0) {
            throw new InvalidArgumentException(
                'title, description, and requested_by_user_id are required'
            );
        }

        $changeType = (string) ($data['change_type'] ?? ChangeRequest::TYPE_NORMAL);
        if (!in_array($changeType, ChangeRequest::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('Invalid change_type');
        }
        $risk = (string) ($data['risk_level'] ?? ChangeRequest::RISK_MEDIUM);
        if (!in_array($risk, ChangeRequest::ALLOWED_RISK_LEVELS, true)) {
            throw new InvalidArgumentException('Invalid risk_level');
        }
        $impact = (string) ($data['impact_level'] ?? ChangeRequest::RISK_MEDIUM);
        if (!in_array($impact, ChangeRequest::ALLOWED_IMPACT_LEVELS, true)) {
            throw new InvalidArgumentException('Invalid impact_level');
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO change_requests
                (customer_id, originating_ticket_id, affected_site_asset_id,
                 requested_by_user_id, assigned_to_user_id, title, description,
                 change_type, risk_level, impact_level, status,
                 implementation_plan, backout_plan, test_plan,
                 business_justification, planned_start_at, planned_end_at)
             VALUES
                (:customer_id, :originating_ticket_id, :affected_site_asset_id,
                 :requested_by_user_id, :assigned_to_user_id, :title, :description,
                 :change_type, :risk_level, :impact_level, :status,
                 :implementation_plan, :backout_plan, :test_plan,
                 :business_justification, :planned_start_at, :planned_end_at)'
        );
        $stmt->execute([
            'customer_id' => $this->nullableInt($data['customer_id'] ?? null),
            'originating_ticket_id' => $this->nullableInt($data['originating_ticket_id'] ?? null),
            'affected_site_asset_id' => $this->nullableInt($data['affected_site_asset_id'] ?? null),
            'requested_by_user_id' => $requestedBy,
            'assigned_to_user_id' => $this->nullableInt($data['assigned_to_user_id'] ?? null),
            'title' => $title,
            'description' => $description,
            'change_type' => $changeType,
            'risk_level' => $risk,
            'impact_level' => $impact,
            'status' => ChangeRequest::STATUS_DRAFT,
            'implementation_plan' => $this->nullableString($data['implementation_plan'] ?? null),
            'backout_plan' => $this->nullableString($data['backout_plan'] ?? null),
            'test_plan' => $this->nullableString($data['test_plan'] ?? null),
            'business_justification' => $this->nullableString($data['business_justification'] ?? null),
            'planned_start_at' => $this->nullableString($data['planned_start_at'] ?? null),
            'planned_end_at' => $this->nullableString($data['planned_end_at'] ?? null),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created change_request');
        }
        return $row;
    }

    /**
     * Partial update for non-status columns. Refuses to write `status` —
     * use ChangeRequestService::transition() instead so the audit + state
     * timestamps stay aligned.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ChangeRequest
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("change_request {$id} not found");
        }
        if (array_key_exists('status', $data)) {
            throw new InvalidArgumentException(
                'status must be mutated through ChangeRequestService::transition'
            );
        }
        if (array_key_exists('change_type', $data)
            && !in_array((string) $data['change_type'], ChangeRequest::ALLOWED_TYPES, true)
        ) {
            throw new InvalidArgumentException('Invalid change_type');
        }
        if (array_key_exists('risk_level', $data)
            && !in_array((string) $data['risk_level'], ChangeRequest::ALLOWED_RISK_LEVELS, true)
        ) {
            throw new InvalidArgumentException('Invalid risk_level');
        }
        if (array_key_exists('impact_level', $data)
            && !in_array((string) $data['impact_level'], ChangeRequest::ALLOWED_IMPACT_LEVELS, true)
        ) {
            throw new InvalidArgumentException('Invalid impact_level');
        }

        $fields = [];
        $params = ['id' => $id];

        $stringCols = ['title', 'description', 'change_type', 'risk_level',
                       'impact_level', 'implementation_plan', 'backout_plan',
                       'test_plan', 'business_justification', 'planned_start_at',
                       'planned_end_at'];
        foreach ($stringCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }
        $intCols = ['customer_id', 'originating_ticket_id', 'affected_site_asset_id',
                    'assigned_to_user_id'];
        foreach ($intCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $this->nullableInt($data[$key]);
            }
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE change_requests SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("change_request {$id} not found after update");
        }
        return $row;
    }

    /**
     * Service-only state writer. Updates status + the relevant timestamp
     * column atomically so the change_requests row stays consistent. Caller
     * (ChangeRequestService) MUST run inside a transaction, must hold the
     * row lock from findByIdForUpdate(), and must validate the transition
     * against ChangeRequest::TRANSITIONS first.
     *
     * @param array<string, mixed> $extra additional column writes paired with
     *                                     this transition (e.g. decision_notes,
     *                                     rollback_reason, cancellation_reason)
     */
    public function applyTransition(int $id, string $toStatus, array $extra = []): ChangeRequest
    {
        $fields = ['status = :status'];
        $params = ['id' => $id, 'status' => $toStatus];

        $now = date('Y-m-d H:i:s');
        switch ($toStatus) {
            case ChangeRequest::STATUS_SUBMITTED:
                $fields[] = 'submitted_at = :submitted_at';
                $params['submitted_at'] = $now;
                break;
            case ChangeRequest::STATUS_CAB_REVIEW:
                $fields[] = 'cab_review_started_at = :cab_review_started_at';
                $params['cab_review_started_at'] = $now;
                break;
            case ChangeRequest::STATUS_APPROVED:
            case ChangeRequest::STATUS_REJECTED:
                $fields[] = 'decision_at = :decision_at';
                $params['decision_at'] = $now;
                break;
            case ChangeRequest::STATUS_IN_PROGRESS:
                $fields[] = 'actual_start_at = :actual_start_at';
                $params['actual_start_at'] = $now;
                break;
            case ChangeRequest::STATUS_COMPLETED:
                $fields[] = 'actual_end_at = :actual_end_at';
                $fields[] = 'completed_at = :completed_at';
                $params['actual_end_at'] = $now;
                $params['completed_at'] = $now;
                break;
            case ChangeRequest::STATUS_ROLLED_BACK:
                $fields[] = 'actual_end_at = :actual_end_at';
                $fields[] = 'rolled_back_at = :rolled_back_at';
                $params['actual_end_at'] = $now;
                $params['rolled_back_at'] = $now;
                break;
            case ChangeRequest::STATUS_CANCELLED:
                $fields[] = 'cancelled_at = :cancelled_at';
                $params['cancelled_at'] = $now;
                break;
        }

        $extraCols = ['decision_by_user_id', 'decision_notes',
                      'rollback_reason', 'cancellation_reason'];
        foreach ($extraCols as $key) {
            if (array_key_exists($key, $extra)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $extra[$key];
            }
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE change_requests SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("change_request {$id} not found after transition");
        }
        return $row;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM change_requests WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (isset($filters['customer_id'])) {
            $where .= ' AND customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['change_type'])) {
            $where .= ' AND change_type = :change_type';
            $params['change_type'] = (string) $filters['change_type'];
        }
        if (!empty($filters['risk_level'])) {
            $where .= ' AND risk_level = :risk_level';
            $params['risk_level'] = (string) $filters['risk_level'];
        }
        if (isset($filters['requested_by_user_id'])) {
            $where .= ' AND requested_by_user_id = :requested_by_user_id';
            $params['requested_by_user_id'] = (int) $filters['requested_by_user_id'];
        }
        if (isset($filters['assigned_to_user_id'])) {
            $where .= ' AND assigned_to_user_id = :assigned_to_user_id';
            $params['assigned_to_user_id'] = (int) $filters['assigned_to_user_id'];
        }
        if (isset($filters['originating_ticket_id'])) {
            $where .= ' AND originating_ticket_id = :originating_ticket_id';
            $params['originating_ticket_id'] = (int) $filters['originating_ticket_id'];
        }
        if (isset($filters['affected_site_asset_id'])) {
            $where .= ' AND affected_site_asset_id = :affected_site_asset_id';
            $params['affected_site_asset_id'] = (int) $filters['affected_site_asset_id'];
        }
        if (!empty($filters['window_start'])) {
            $where .= ' AND planned_end_at IS NOT NULL AND planned_end_at >= :window_start';
            $params['window_start'] = (string) $filters['window_start'];
        }
        if (!empty($filters['window_end'])) {
            $where .= ' AND planned_start_at IS NOT NULL AND planned_start_at <= :window_end';
            $params['window_end'] = (string) $filters['window_end'];
        }

        return [$where, $params];
    }
}
