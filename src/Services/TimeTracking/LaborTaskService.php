<?php

namespace App\Services\TimeTracking;

use App\Database\Connection;
use App\Models\LaborTask;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class LaborTaskService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * List all labor tasks with optional filtering.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $baseSql = 'FROM labor_tasks lt LEFT JOIN service_types st ON st.id = lt.service_type_id WHERE 1=1';
        $params = [];

        if (isset($filters['is_active'])) {
            $baseSql .= ' AND lt.is_active = :is_active';
            $params['is_active'] = (int) $filters['is_active'];
        }

        if (!empty($filters['search'])) {
            $baseSql .= ' AND (lt.name LIKE :search OR lt.description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['service_type_id'])) {
            $baseSql .= ' AND lt.service_type_id = :service_type_id';
            $params['service_type_id'] = (int) $filters['service_type_id'];
        }

        if (!empty($filters['service_line_id'])) {
            $baseSql .= ' AND lt.service_line_id = :service_line_id';
            $params['service_line_id'] = (int) $filters['service_line_id'];
        }

        $sql = 'SELECT lt.*, st.name AS service_type_name ' . $baseSql . ' ORDER BY lt.display_order ASC, lt.name ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $type);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = count($rows);
        $total = $rowCount < $limit
            ? $offset + $rowCount
            : (function () use ($baseSql, $params): int {
                $countStmt = $this->connection->pdo()->prepare('SELECT COUNT(*) ' . $baseSql);
                $countStmt->execute($params);
                return (int) $countStmt->fetchColumn();
            })();
        $data = array_map(static function (array $row) {
            $task = new LaborTask($row);
            $arr = $task->toArray();
            $arr['service_type_name'] = $row['service_type_name'] ?? null;
            return $arr;
        }, $rows);

        return [
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * Get active tasks for technician selection dropdown.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveTasks(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT id, name, description, flat_rate_minutes, service_type_id FROM labor_tasks WHERE is_active = 1 ORDER BY display_order ASC, name ASC'
        );

        return array_map(static fn (array $row) => (new LaborTask($row))->toArray(), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $id): ?LaborTask
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM labor_tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? new LaborTask($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, int $actorId): LaborTask
    {
        if (empty($data['name'])) {
            throw new InvalidArgumentException('Task name is required');
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO labor_tasks (name, description, flat_rate_minutes, labor_rate, service_type_id, service_line_id, is_active, display_order, created_at, updated_at) '
            . 'VALUES (:name, :description, :flat_rate_minutes, :labor_rate, :service_type_id, :service_line_id, :is_active, :display_order, NOW(), NOW())'
        );

        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'flat_rate_minutes' => $data['flat_rate_minutes'] ?? 0,
            'labor_rate' => $data['labor_rate'] ?? null,
            'service_type_id' => $data['service_type_id'] ?? null,
            'service_line_id' => isset($data['service_line_id']) && $data['service_line_id'] !== null
                ? (int) $data['service_line_id']
                : null,
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'display_order' => $data['display_order'] ?? 0,
        ]);

        $taskId = (int) $this->connection->pdo()->lastInsertId();
        $task = $this->find($taskId);

        $this->log($actorId, 'labor_task.create', $taskId, $task?->toArray() ?? []);

        return $task ?? new LaborTask(['id' => $taskId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data, int $actorId): ?LaborTask
    {
        $task = $this->find($id);
        if ($task === null) {
            return null;
        }

        $name = $data['name'] ?? $task->name;
        $description = array_key_exists('description', $data) ? $data['description'] : $task->description;
        $flatRateMinutes = $data['flat_rate_minutes'] ?? $task->flat_rate_minutes;
        $laborRate = array_key_exists('labor_rate', $data) ? $data['labor_rate'] : $task->labor_rate;
        $serviceTypeId = array_key_exists('service_type_id', $data) ? $data['service_type_id'] : $task->service_type_id;
        $serviceLineId = array_key_exists('service_line_id', $data) ? $data['service_line_id'] : $task->service_line_id;
        $isActive = isset($data['is_active']) ? (bool) $data['is_active'] : $task->is_active;
        $displayOrder = $data['display_order'] ?? $task->display_order;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE labor_tasks SET name = :name, description = :description, flat_rate_minutes = :flat_rate_minutes, '
            . 'labor_rate = :labor_rate, service_type_id = :service_type_id, service_line_id = :service_line_id, '
            . 'is_active = :is_active, display_order = :display_order, updated_at = NOW() WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'flat_rate_minutes' => $flatRateMinutes,
            'labor_rate' => $laborRate,
            'service_type_id' => $serviceTypeId,
            'service_line_id' => $serviceLineId !== null ? (int) $serviceLineId : null,
            'is_active' => $isActive ? 1 : 0,
            'display_order' => $displayOrder,
        ]);

        $updated = $this->find($id);
        $this->log($actorId, 'labor_task.update', $id, [
            'before' => $task->toArray(),
            'after' => $updated?->toArray(),
        ]);

        return $updated;
    }

    public function delete(int $id, int $actorId): bool
    {
        $task = $this->find($id);
        if ($task === null) {
            return false;
        }

        // Check if task is used in time entries
        $checkStmt = $this->connection->pdo()->prepare('SELECT COUNT(*) FROM time_entries WHERE task_id = :id');
        $checkStmt->execute(['id' => $id]);
        $count = (int) $checkStmt->fetchColumn();

        if ($count > 0) {
            throw new InvalidArgumentException('Cannot delete task that has been used in time entries. Deactivate instead.');
        }

        $stmt = $this->connection->pdo()->prepare('DELETE FROM labor_tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $this->log($actorId, 'labor_task.delete', $id, $task->toArray());

        return true;
    }

    /**
     * Get efficiency report for a technician or all technicians.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getEfficiencyReport(array $filters = []): array
    {
        $sql = 'SELECT
                te.technician_id,
                u.name AS technician_name,
                lt.id AS task_id,
                lt.name AS task_name,
                COUNT(te.id) AS total_completions,
                SUM(te.duration_minutes) AS total_actual_minutes,
                SUM(te.flat_rate_minutes) AS total_flat_rate_minutes,
                AVG(te.duration_minutes) AS avg_actual_minutes,
                AVG(te.flat_rate_minutes) AS avg_flat_rate_minutes,
                CASE
                    WHEN SUM(te.duration_minutes) > 0 THEN
                        ROUND((SUM(te.flat_rate_minutes) / SUM(te.duration_minutes)) * 100, 2)
                    ELSE 0
                END AS efficiency_percentage
            FROM time_entries te
            JOIN users u ON u.id = te.technician_id
            LEFT JOIN labor_tasks lt ON lt.id = te.task_id
            WHERE te.ended_at IS NOT NULL
                AND te.status = \'approved\'
                AND te.flat_rate_minutes IS NOT NULL
                AND te.flat_rate_minutes > 0';
        $params = [];

        if (!empty($filters['technician_id'])) {
            $sql .= ' AND te.technician_id = :technician_id';
            $params['technician_id'] = (int) $filters['technician_id'];
        }

        if (!empty($filters['start_date'])) {
            $sql .= ' AND te.started_at >= :start_date';
            $params['start_date'] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $sql .= ' AND te.started_at <= :end_date';
            $params['end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        $groupBy = $filters['group_by'] ?? 'technician';
        if ($groupBy === 'task') {
            $sql .= ' GROUP BY te.technician_id, u.name, lt.id, lt.name ORDER BY u.name, lt.name';
        } else {
            $sql .= ' GROUP BY te.technician_id, u.name ORDER BY u.name';
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate summary stats
        $totalActual = 0;
        $totalFlatRate = 0;
        foreach ($rows as $row) {
            $totalActual += (float) $row['total_actual_minutes'];
            $totalFlatRate += (float) $row['total_flat_rate_minutes'];
        }

        $overallEfficiency = $totalActual > 0 ? round(($totalFlatRate / $totalActual) * 100, 2) : 0;

        return [
            'data' => $rows,
            'summary' => [
                'total_actual_minutes' => $totalActual,
                'total_flat_rate_minutes' => $totalFlatRate,
                'overall_efficiency_percentage' => $overallEfficiency,
            ],
        ];
    }

    private function log(int $actorId, string $event, int $taskId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'labor_task', (string) $taskId, $actorId, $context));
    }
}
