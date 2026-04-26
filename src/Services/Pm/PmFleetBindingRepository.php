<?php

namespace App\Services\Pm;

use App\Database\Connection;
use App\Models\PmFleetBinding;
use PDO;

/**
 * Phase 7.2 of docs/expansion-plan.md — bindings between pm_plans and
 * fleet units/unit-types. The service layer enforces scope_type-pin
 * invariants (unit scope must have fleet_unit_id, unit_type scope must
 * have unit_type), so the repository is a flat CRUD surface.
 */
class PmFleetBindingRepository
{
    private const COLUMNS = 'id, plan_id, company_id, scope_type,
        fleet_unit_id, unit_type, frequency_kind, frequency_config,
        lead_time_days, starts_at, is_active, created_by_user_id,
        created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function create(array $row): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO pm_fleet_bindings
                (plan_id, company_id, scope_type, fleet_unit_id, unit_type,
                 frequency_kind, frequency_config, lead_time_days, starts_at,
                 is_active, created_by_user_id, created_at, updated_at)
             VALUES
                (:pid, :co, :st, :fuid, :ut, :fk, :fc, :lt, :sa, :ia, :by,
                 CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'pid' => $row['plan_id'],
            'co' => $row['company_id'],
            'st' => $row['scope_type'],
            'fuid' => $row['fleet_unit_id'] ?? null,
            'ut' => $row['unit_type'] ?? null,
            'fk' => $row['frequency_kind'],
            'fc' => isset($row['frequency_config']) && is_array($row['frequency_config'])
                ? json_encode($row['frequency_config'])
                : ($row['frequency_config'] ?? null),
            'lt' => (int) ($row['lead_time_days'] ?? 0),
            'sa' => $row['starts_at'],
            'ia' => (int) ($row['is_active'] ?? 1),
            'by' => $row['created_by_user_id'] ?? null,
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id): ?PmFleetBinding
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM pm_fleet_bindings WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, PmFleetBinding>
     */
    public function listForCompany(int $companyId, bool $activeOnly = true): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM pm_fleet_bindings WHERE company_id = :co';
        $params = ['co' => $companyId];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY scope_type ASC, id ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Return bindings that apply to (unitId, unitType) in precedence
     * order: unit-specific first, then unit_type matches. The service
     * uses this to decide which schedules to auto-spawn for a unit.
     *
     * @return array<int, PmFleetBinding>
     */
    public function listApplicable(int $companyId, int $unitId, string $unitType): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM pm_fleet_bindings
             WHERE company_id = :co AND is_active = 1
               AND ((scope_type = :unit_scope AND fleet_unit_id = :uid)
                 OR (scope_type = :type_scope AND unit_type = :ut))
             ORDER BY (scope_type = :unit_scope) DESC, id ASC'
        );
        $stmt->execute([
            'co' => $companyId,
            'unit_scope' => PmFleetBinding::SCOPE_UNIT,
            'type_scope' => PmFleetBinding::SCOPE_UNIT_TYPE,
            'uid' => $unitId,
            'ut' => $unitType,
        ]);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function update(int $id, array $row): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE pm_fleet_bindings SET
                frequency_kind = :fk,
                frequency_config = :fc,
                lead_time_days = :lt,
                starts_at = :sa,
                is_active = :ia,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'fk' => $row['frequency_kind'],
            'fc' => isset($row['frequency_config']) && is_array($row['frequency_config'])
                ? json_encode($row['frequency_config'])
                : ($row['frequency_config'] ?? null),
            'lt' => (int) ($row['lead_time_days'] ?? 0),
            'sa' => $row['starts_at'],
            'ia' => (int) ($row['is_active'] ?? 1),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM pm_fleet_bindings WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PmFleetBinding
    {
        if (isset($row['frequency_config']) && is_string($row['frequency_config'])) {
            $decoded = json_decode($row['frequency_config'], true);
            $row['frequency_config'] = is_array($decoded) ? $decoded : null;
        }
        return new PmFleetBinding($row);
    }
}
