<?php

namespace App\Services\Pm;

use App\Database\Connection;
use App\Models\PmPlan;
use PDO;
use RuntimeException;

/**
 * Preventative-maintenance plan catalog (Phase 5.1 of docs/expansion-plan.md).
 * A plan is a reusable template that one or more pm_schedules instantiate.
 */
class PmPlanRepository
{
    public const PRIORITIES = ['p1_critical', 'p2_high', 'p3_normal', 'p4_low'];

    private const COLUMNS = 'id, company_id, division_id, title, description,
        default_priority, estimated_duration_minutes, checklist_json,
        default_category_id, default_queue_id, default_assigned_user_id,
        is_active, created_by_user_id, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, PmPlan>
     */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['company_id'])) {
            $where[] = '(company_id = :company_id OR company_id IS NULL)';
            $params['company_id'] = (int) $filters['company_id'];
        }
        if (!empty($filters['division_id'])) {
            $where[] = '(division_id = :division_id OR division_id IS NULL)';
            $params['division_id'] = (int) $filters['division_id'];
        }
        if (isset($filters['is_active'])) {
            $where[] = 'is_active = :is_active';
            $params['is_active'] = (int) (bool) $filters['is_active'];
        }
        if (!empty($filters['query'])) {
            $where[] = '(title LIKE :q OR description LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM pm_plans
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY title ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?PmPlan
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM pm_plans WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): PmPlan
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO pm_plans (
                company_id, division_id, title, description, default_priority,
                estimated_duration_minutes, checklist_json, default_category_id,
                default_queue_id, default_assigned_user_id, is_active,
                created_by_user_id
            ) VALUES (
                :company_id, :division_id, :title, :description, :default_priority,
                :estimated_duration_minutes, :checklist_json, :default_category_id,
                :default_queue_id, :default_assigned_user_id, :is_active,
                :created_by_user_id
            )'
        );
        $stmt->execute([
            'company_id' => $data['company_id'] ?? null,
            'division_id' => $data['division_id'] ?? null,
            'title' => (string) $data['title'],
            'description' => $data['description'] ?? null,
            'default_priority' => $data['default_priority'] ?? 'p3_normal',
            'estimated_duration_minutes' => $data['estimated_duration_minutes'] ?? null,
            'checklist_json' => isset($data['checklist_json'])
                ? (is_string($data['checklist_json'])
                    ? $data['checklist_json']
                    : json_encode($data['checklist_json']))
                : null,
            'default_category_id' => $data['default_category_id'] ?? null,
            'default_queue_id' => $data['default_queue_id'] ?? null,
            'default_assigned_user_id' => $data['default_assigned_user_id'] ?? null,
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('pm_plans insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): PmPlan
    {
        $writable = [
            'company_id', 'division_id', 'title', 'description',
            'default_priority', 'estimated_duration_minutes',
            'default_category_id', 'default_queue_id',
            'default_assigned_user_id',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('checklist_json', $data)) {
            $fields[] = 'checklist_json = :checklist_json';
            $params['checklist_json'] = $data['checklist_json'] === null
                ? null
                : (is_string($data['checklist_json'])
                    ? $data['checklist_json']
                    : json_encode($data['checklist_json']));
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int) (bool) $data['is_active'];
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("pm_plan {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE pm_plans SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("pm_plan {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM pm_plans WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PmPlan
    {
        if (isset($row['checklist_json']) && is_string($row['checklist_json'])) {
            $decoded = json_decode($row['checklist_json'], true);
            $row['checklist_json'] = is_array($decoded) ? $decoded : null;
        }
        return new PmPlan($row);
    }
}
