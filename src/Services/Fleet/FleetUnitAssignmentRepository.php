<?php

namespace App\Services\Fleet;

use App\Database\Connection;
use App\Models\FleetUnitAssignment;
use PDO;

/**
 * Phase 7.1 of docs/expansion-plan.md — assignment history.
 *
 * "Current" assignment is the row with assigned_until IS NULL. Service
 * layer enforces at most one active assignment per unit by closing the
 * prior current (stamp assigned_until) in the same transaction that
 * opens the new one.
 */
class FleetUnitAssignmentRepository
{
    private const COLUMNS = 'id, fleet_unit_id, assignment_type, assigned_user_id,
        assigned_site_id, customer_id, assigned_from, assigned_until, notes,
        created_by_user_id, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function create(array $row): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO fleet_unit_assignments
                (fleet_unit_id, assignment_type,
                 assigned_user_id, assigned_site_id, customer_id,
                 assigned_from, assigned_until, notes,
                 created_by_user_id, created_at)
             VALUES
                (:uid, :at, :usr, :sid, :cid, :afr, :aut, :nt, :by, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'uid' => $row['fleet_unit_id'],
            'at' => $row['assignment_type'],
            'usr' => $row['assigned_user_id'],
            'sid' => $row['assigned_site_id'],
            'cid' => $row['customer_id'],
            'afr' => $row['assigned_from'],
            'aut' => $row['assigned_until'],
            'nt' => $row['notes'],
            'by' => $row['created_by_user_id'],
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id): ?FleetUnitAssignment
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_unit_assignments WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findCurrentForUnit(int $unitId): ?FleetUnitAssignment
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_unit_assignments
             WHERE fleet_unit_id = :uid AND assigned_until IS NULL
             ORDER BY assigned_from DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, FleetUnitAssignment>
     */
    public function listForUnit(int $unitId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_unit_assignments
             WHERE fleet_unit_id = :uid
             ORDER BY assigned_from DESC, id DESC'
        );
        $stmt->execute(['uid' => $unitId]);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Stamp assigned_until on the current active assignment. Returns
     * false if the row was already closed — service layer treats that
     * as a concurrency race and rejects loudly.
     */
    public function closeCurrent(int $unitId, string $closedAt): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE fleet_unit_assignments
             SET assigned_until = :au
             WHERE fleet_unit_id = :uid AND assigned_until IS NULL'
        );
        $stmt->execute(['au' => $closedAt, 'uid' => $unitId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FleetUnitAssignment
    {
        $a = new FleetUnitAssignment();
        $a->id = (int) $row['id'];
        $a->fleet_unit_id = (int) $row['fleet_unit_id'];
        $a->assignment_type = (string) $row['assignment_type'];
        $a->assigned_user_id = $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null;
        $a->assigned_site_id = $row['assigned_site_id'] !== null ? (int) $row['assigned_site_id'] : null;
        $a->customer_id = $row['customer_id'] !== null ? (int) $row['customer_id'] : null;
        $a->assigned_from = (string) $row['assigned_from'];
        $a->assigned_until = $row['assigned_until'] ?? null;
        $a->notes = $row['notes'] !== null ? (string) $row['notes'] : null;
        $a->created_by_user_id = (int) $row['created_by_user_id'];
        $a->created_at = $row['created_at'] ?? null;
        return $a;
    }
}
