<?php

namespace App\Services\Pm;

use App\Database\Connection;
use App\Models\PmSchedule;
use PDO;
use RuntimeException;

/**
 * pm_schedules — per-target instances of a pm_plan (Phase 5.1 of
 * docs/expansion-plan.md). The generator cron (Phase 5.3) walks
 * listDueThrough() and spawns work items when next_due_at - lead_time_days
 * <= today.
 */
class PmScheduleRepository
{
    public const STATUSES = ['active', 'paused', 'archived'];
    public const FREQUENCY_KINDS = ['fixed_interval', 'calendar', 'meter', 'condition'];

    private const COLUMNS = 'id, plan_id, company_id, site_id, asset_id,
        fleet_unit_id, frequency_kind, frequency_config, starts_at, next_due_at,
        last_generated_at, lead_time_days, status, contract_id,
        contract_entitlement_id, notes, created_by_user_id,
        created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, PmSchedule>
     */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['company_id'])) {
            $where[] = 'company_id = :company_id';
            $params['company_id'] = (int) $filters['company_id'];
        }
        if (!empty($filters['site_id'])) {
            $where[] = 'site_id = :site_id';
            $params['site_id'] = (int) $filters['site_id'];
        }
        if (!empty($filters['asset_id'])) {
            $where[] = 'asset_id = :asset_id';
            $params['asset_id'] = (int) $filters['asset_id'];
        }
        if (!empty($filters['fleet_unit_id'])) {
            $where[] = 'fleet_unit_id = :fleet_unit_id';
            $params['fleet_unit_id'] = (int) $filters['fleet_unit_id'];
        }
        if (!empty($filters['plan_id'])) {
            $where[] = 'plan_id = :plan_id';
            $params['plan_id'] = (int) $filters['plan_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['contract_id'])) {
            $where[] = 'contract_id = :contract_id';
            $params['contract_id'] = (int) $filters['contract_id'];
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM pm_schedules
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY (next_due_at IS NULL), next_due_at ASC, id ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?PmSchedule
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM pm_schedules WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Return schedules whose (next_due_at - lead_time_days) has been reached
     * by `cutoff`. Used by the Phase 5.3 generator cron.
     *
     * @return array<int, PmSchedule>
     */
    public function listDueThrough(string $cutoff): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM pm_schedules
             WHERE status = :active
               AND next_due_at IS NOT NULL
               AND DATE_SUB(next_due_at, INTERVAL lead_time_days DAY) <= :cutoff
             ORDER BY next_due_at ASC, id ASC'
        );
        $stmt->execute(['active' => 'active', 'cutoff' => $cutoff]);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Return active schedules whose next_due_at is strictly before `asOfDate`
     * and whose lead_time_days has also elapsed. These are *overdue* — the
     * cron either hasn't run yet, or generation failed, or an event-driven
     * kind (meter/condition) is waiting on its trigger. Phase 5.4 compliance
     * report surfaces this list.
     *
     * @param array<string, mixed> $filters
     * @return array<int, PmSchedule>
     */
    public function listOverdue(string $asOfDate, array $filters = []): array
    {
        $where = [
            'status = :active',
            'next_due_at IS NOT NULL',
            'next_due_at < :as_of',
        ];
        $params = ['active' => 'active', 'as_of' => $asOfDate];
        if (!empty($filters['company_id'])) {
            $where[] = 'company_id = :company_id';
            $params['company_id'] = (int) $filters['company_id'];
        }
        if (!empty($filters['site_id'])) {
            $where[] = 'site_id = :site_id';
            $params['site_id'] = (int) $filters['site_id'];
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM pm_schedules
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY next_due_at ASC, id ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): PmSchedule
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO pm_schedules (
                plan_id, company_id, site_id, asset_id, fleet_unit_id,
                frequency_kind, frequency_config, starts_at, next_due_at,
                last_generated_at, lead_time_days, status, contract_id,
                contract_entitlement_id, notes, created_by_user_id
            ) VALUES (
                :plan_id, :company_id, :site_id, :asset_id, :fleet_unit_id,
                :frequency_kind, :frequency_config, :starts_at, :next_due_at,
                :last_generated_at, :lead_time_days, :status, :contract_id,
                :contract_entitlement_id, :notes, :created_by_user_id
            )'
        );
        $stmt->execute([
            'plan_id' => (int) $data['plan_id'],
            'company_id' => (int) $data['company_id'],
            'site_id' => $data['site_id'] ?? null,
            'asset_id' => $data['asset_id'] ?? null,
            'fleet_unit_id' => $data['fleet_unit_id'] ?? null,
            'frequency_kind' => $data['frequency_kind'] ?? 'fixed_interval',
            'frequency_config' => isset($data['frequency_config'])
                ? (is_string($data['frequency_config'])
                    ? $data['frequency_config']
                    : json_encode($data['frequency_config']))
                : null,
            'starts_at' => (string) $data['starts_at'],
            'next_due_at' => $data['next_due_at'] ?? $data['starts_at'],
            'last_generated_at' => $data['last_generated_at'] ?? null,
            'lead_time_days' => isset($data['lead_time_days'])
                ? max(0, (int) $data['lead_time_days']) : 0,
            'status' => $data['status'] ?? 'active',
            'contract_id' => $data['contract_id'] ?? null,
            'contract_entitlement_id' => $data['contract_entitlement_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('pm_schedules insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): PmSchedule
    {
        $writable = [
            'site_id', 'asset_id', 'fleet_unit_id', 'frequency_kind', 'starts_at',
            'next_due_at', 'last_generated_at', 'lead_time_days', 'status',
            'contract_id', 'contract_entitlement_id', 'notes',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('frequency_config', $data)) {
            $fields[] = 'frequency_config = :frequency_config';
            $params['frequency_config'] = $data['frequency_config'] === null
                ? null
                : (is_string($data['frequency_config'])
                    ? $data['frequency_config']
                    : json_encode($data['frequency_config']));
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("pm_schedule {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE pm_schedules SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("pm_schedule {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM pm_schedules WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PmSchedule
    {
        if (isset($row['frequency_config']) && is_string($row['frequency_config'])) {
            $decoded = json_decode($row['frequency_config'], true);
            $row['frequency_config'] = is_array($decoded) ? $decoded : null;
        }
        return new PmSchedule($row);
    }
}
