<?php

namespace App\Services\Workorder;

use App\Database\Connection;
use App\Models\Workorder;
use App\Models\WorkorderJob;
use App\Models\WorkorderItem;
use App\Models\WorkorderStatusHistory;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class WorkorderRepository
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    public function find(int $id): ?Workorder
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM workorders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapWorkorder($row) : null;
    }

    public function findByNumber(string $number): ?Workorder
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM workorders WHERE number = :number LIMIT 1');
        $stmt->execute(['number' => $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapWorkorder($row) : null;
    }

    public function findByEstimateId(int $estimateId): ?Workorder
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM workorders WHERE estimate_id = :estimate_id LIMIT 1');
        $stmt->execute(['estimate_id' => $estimateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapWorkorder($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, Workorder>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = [];
        $bindings = [];

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = [];
                foreach ($filters['status'] as $i => $status) {
                    $key = 'status_' . $i;
                    $placeholders[] = ':' . $key;
                    $bindings[$key] = $status;
                }
                $clauses[] = 'status IN (' . implode(',', $placeholders) . ')';
            } else {
                $clauses[] = 'status = :status';
                $bindings['status'] = $filters['status'];
            }
        }

        if (!empty($filters['customer_id'])) {
            $clauses[] = 'customer_id = :customer_id';
            $bindings['customer_id'] = (int) $filters['customer_id'];
        }

        if (!empty($filters['vehicle_id'])) {
            $clauses[] = 'vehicle_id = :vehicle_id';
            $bindings['vehicle_id'] = (int) $filters['vehicle_id'];
        }

        if (!empty($filters['technician_id'])) {
            $clauses[] = 'assigned_technician_id = :technician_id';
            $bindings['technician_id'] = (int) $filters['technician_id'];
        }

        if (!empty($filters['priority'])) {
            $clauses[] = 'priority = :priority';
            $bindings['priority'] = $filters['priority'];
        }

        if (!empty($filters['term'])) {
            $clauses[] = '(number LIKE :term)';
            $bindings['term'] = '%' . $filters['term'] . '%';
        }

        if (!empty($filters['created_from'])) {
            $clauses[] = 'created_at >= :created_from';
            $bindings['created_from'] = $filters['created_from'];
        }

        if (!empty($filters['created_to'])) {
            $clauses[] = 'created_at <= :created_to';
            $bindings['created_to'] = $filters['created_to'];
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $sql = 'SELECT * FROM workorders ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = $this->mapWorkorder($row);
        }

        return $results;
    }

    public function count(array $filters = []): int
    {
        $clauses = [];
        $bindings = [];

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $placeholders = [];
                foreach ($filters['status'] as $i => $status) {
                    $key = 'status_' . $i;
                    $placeholders[] = ':' . $key;
                    $bindings[$key] = $status;
                }
                $clauses[] = 'status IN (' . implode(',', $placeholders) . ')';
            } else {
                $clauses[] = 'status = :status';
                $bindings['status'] = $filters['status'];
            }
        }

        if (!empty($filters['technician_id'])) {
            $clauses[] = 'assigned_technician_id = :technician_id';
            $bindings['technician_id'] = (int) $filters['technician_id'];
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $sql = 'SELECT COUNT(*) FROM workorders ' . $where;
        $stmt = $this->connection->pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null, ?string $notes = null): ?Workorder
    {
        $workorder = $this->find($id);
        if ($workorder === null) {
            return null;
        }

        if (!in_array($status, Workorder::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status for workorder lifecycle.');
        }

        if ($workorder->status === $status) {
            return $workorder;
        }

        if (!$workorder->canTransitionTo($status)) {
            throw new InvalidArgumentException("Cannot transition from {$workorder->status} to {$status}");
        }

        $fromStatus = $workorder->status;
        $pdo = $this->connection->pdo();

        $updateFields = ['status = :status', 'updated_at = NOW()'];
        $params = ['status' => $status, 'id' => $id];

        if ($status === Workorder::STATUS_IN_PROGRESS && $workorder->started_at === null) {
            $updateFields[] = 'started_at = NOW()';
        }

        if ($status === Workorder::STATUS_COMPLETED) {
            $updateFields[] = 'completed_at = NOW()';
        }

        $stmt = $pdo->prepare('UPDATE workorders SET ' . implode(', ', $updateFields) . ' WHERE id = :id');
        $stmt->execute($params);

        // Record status history
        $this->recordStatusHistory($id, $fromStatus, $status, $actorId, $notes);

        $workorder = $this->find($id);
        $this->log('workorder.status_changed', $id, $actorId, [
            'from_status' => $fromStatus,
            'to_status' => $status,
            'notes' => $notes,
        ]);

        return $workorder;
    }

    public function assignTechnician(int $id, ?int $technicianId, ?int $actorId = null): ?Workorder
    {
        $workorder = $this->find($id);
        if ($workorder === null) {
            return null;
        }

        $previousTechnicianId = $workorder->assigned_technician_id;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE workorders SET assigned_technician_id = :technician_id, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['technician_id' => $technicianId, 'id' => $id]);

        $this->log('workorder.technician_assigned', $id, $actorId, [
            'previous_technician_id' => $previousTechnicianId,
            'new_technician_id' => $technicianId,
        ]);

        return $this->find($id);
    }

    public function updatePriority(int $id, string $priority, ?int $actorId = null): ?Workorder
    {
        if (!in_array($priority, Workorder::ALLOWED_PRIORITIES, true)) {
            throw new InvalidArgumentException('Invalid priority value.');
        }

        $workorder = $this->find($id);
        if ($workorder === null) {
            return null;
        }

        $previousPriority = $workorder->priority;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE workorders SET priority = :priority, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['priority' => $priority, 'id' => $id]);

        $this->log('workorder.priority_changed', $id, $actorId, [
            'previous_priority' => $previousPriority,
            'new_priority' => $priority,
        ]);

        return $this->find($id);
    }

    /**
     * @return array<int, WorkorderJob>
     */
    public function getJobs(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM workorder_jobs WHERE workorder_id = :workorder_id ORDER BY position ASC'
        );
        $stmt->execute(['workorder_id' => $workorderId]);

        return array_map(
            fn($row) => new WorkorderJob($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return array<int, WorkorderItem>
     */
    public function getJobItems(int $jobId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM workorder_items WHERE workorder_job_id = :job_id ORDER BY position ASC'
        );
        $stmt->execute(['job_id' => $jobId]);

        return array_map(
            fn($row) => new WorkorderItem($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getJobsWithItems(int $workorderId): array
    {
        $jobs = $this->getJobs($workorderId);
        $result = [];

        foreach ($jobs as $job) {
            $result[] = [
                'job' => $job,
                'items' => $this->getJobItems($job->id),
            ];
        }

        return $result;
    }

    public function updateJobStatus(int $jobId, string $status, ?int $actorId = null): ?WorkorderJob
    {
        if (!in_array($status, WorkorderJob::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid status for workorder job.');
        }

        $updateFields = ['status = :status', 'updated_at = NOW()'];
        $params = ['status' => $status, 'id' => $jobId];

        if ($status === WorkorderJob::STATUS_IN_PROGRESS) {
            $updateFields[] = 'started_at = COALESCE(started_at, NOW())';
        }

        if ($status === WorkorderJob::STATUS_COMPLETED) {
            $updateFields[] = 'completed_at = NOW()';
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE workorder_jobs SET ' . implode(', ', $updateFields) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM workorder_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->log('workorder_job.status_changed', $row['workorder_id'], $actorId, [
                'job_id' => $jobId,
                'status' => $status,
            ]);
        }

        return $row ? new WorkorderJob($row) : null;
    }

    public function assignJobTechnician(int $jobId, ?int $technicianId, ?int $actorId = null): ?WorkorderJob
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE workorder_jobs SET assigned_technician_id = :technician_id, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['technician_id' => $technicianId, 'id' => $jobId]);

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM workorder_jobs WHERE id = :id');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new WorkorderJob($row) : null;
    }

    /**
     * @return array<int, WorkorderStatusHistory>
     */
    public function getStatusHistory(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM workorder_status_history WHERE workorder_id = :workorder_id ORDER BY created_at ASC'
        );
        $stmt->execute(['workorder_id' => $workorderId]);

        return array_map(
            fn($row) => new WorkorderStatusHistory($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function recordStatusHistory(int $workorderId, ?string $fromStatus, string $toStatus, ?int $changedBy, ?string $notes): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO workorder_status_history (workorder_id, from_status, to_status, changed_by, notes, created_at)
            VALUES (:workorder_id, :from_status, :to_status, :changed_by, :notes, NOW())
        SQL);

        $stmt->execute([
            'workorder_id' => $workorderId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $changedBy,
            'notes' => $notes,
        ]);
    }

    private function mapWorkorder(array $row): Workorder
    {
        return new Workorder([
            'id' => (int) $row['id'],
            'number' => (string) $row['number'],
            'estimate_id' => (int) $row['estimate_id'],
            'customer_id' => (int) $row['customer_id'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'status' => (string) $row['status'],
            'priority' => (string) ($row['priority'] ?? 'normal'),
            'assigned_technician_id' => $row['assigned_technician_id'] !== null ? (int) $row['assigned_technician_id'] : null,
            'started_at' => $row['started_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'estimated_completion' => $row['estimated_completion'] ?? null,
            'subtotal' => (float) ($row['subtotal'] ?? 0),
            'tax' => (float) ($row['tax'] ?? 0),
            'call_out_fee' => (float) ($row['call_out_fee'] ?? 0),
            'mileage_total' => (float) ($row['mileage_total'] ?? 0),
            'discounts' => (float) ($row['discounts'] ?? 0),
            'shop_fee' => (float) ($row['shop_fee'] ?? 0),
            'hazmat_disposal_fee' => (float) ($row['hazmat_disposal_fee'] ?? 0),
            'grand_total' => (float) ($row['grand_total'] ?? 0),
            'internal_notes' => $row['internal_notes'] ?? null,
            'customer_notes' => $row['customer_notes'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }

    private function log(string $event, int $workorderId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'workorder', (string) $workorderId, $actorId, $context));
    }
}
