<?php

namespace App\Services\Fleet;

use App\Database\Connection;
use App\Models\FleetUnitDowntime;
use PDO;

/**
 * Phase 7.3 of docs/expansion-plan.md — downtime history.
 *
 * Every downtime row is an append-only entry; the "current" downtime is
 * defined by ended_at IS NULL. Service layer enforces the
 * at-most-one-open-window invariant by closing the prior open row in
 * the same transaction that opens a new one (mirrors the assignment
 * close-then-open pattern from Phase 7.1).
 */
class FleetUnitDowntimeRepository
{
    private const COLUMNS = 'id, fleet_unit_id, reason, started_at, ended_at, notes,
        started_by_user_id, ended_by_user_id, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function create(array $row): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO fleet_unit_downtime
                (fleet_unit_id, reason, started_at, ended_at, notes,
                 started_by_user_id, ended_by_user_id, created_at, updated_at)
             VALUES
                (:uid, :rs, :sa, :ea, :nt, :sb, :eb,
                 CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'uid' => $row['fleet_unit_id'],
            'rs' => $row['reason'],
            'sa' => $row['started_at'],
            'ea' => $row['ended_at'] ?? null,
            'nt' => $row['notes'] ?? null,
            'sb' => $row['started_by_user_id'],
            'eb' => $row['ended_by_user_id'] ?? null,
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id): ?FleetUnitDowntime
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_unit_downtime WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Return the open downtime window for a unit, or null when the unit
     * is fully in-service. The service layer uses this as both a state
     * check and a precondition for endDowntime.
     */
    public function findOpenForUnit(int $unitId): ?FleetUnitDowntime
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_unit_downtime
             WHERE fleet_unit_id = :uid AND ended_at IS NULL
             ORDER BY started_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $unitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function countOpenForUnit(int $unitId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM fleet_unit_downtime
             WHERE fleet_unit_id = :uid AND ended_at IS NULL'
        );
        $stmt->execute(['uid' => $unitId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, FleetUnitDowntime>
     */
    public function listForUnit(int $unitId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_unit_downtime
             WHERE fleet_unit_id = :uid
             ORDER BY started_at DESC, id DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['uid' => $unitId]);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Overwrite the notes column on a row. The service uses this when
     * a close-out includes additional notes that should be merged with
     * the original start-time notes.
     */
    public function appendNotes(int $id, ?string $notes): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE fleet_unit_downtime
             SET notes = :nt, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute(['nt' => $notes, 'id' => $id]);
    }

    /**
     * Stamp ended_at + ended_by_user_id on every currently-open window
     * for this unit. Returns the number of rows closed — the service
     * treats rowCount=0 as a concurrency race when it expected to close
     * exactly one row.
     */
    public function closeOpenForUnit(int $unitId, string $endedAt, int $endedByUserId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE fleet_unit_downtime
             SET ended_at = :ea, ended_by_user_id = :eb, updated_at = CURRENT_TIMESTAMP
             WHERE fleet_unit_id = :uid AND ended_at IS NULL'
        );
        $stmt->execute(['ea' => $endedAt, 'eb' => $endedByUserId, 'uid' => $unitId]);
        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FleetUnitDowntime
    {
        $d = new FleetUnitDowntime();
        $d->id = (int) $row['id'];
        $d->fleet_unit_id = (int) $row['fleet_unit_id'];
        $d->reason = (string) $row['reason'];
        $d->started_at = (string) $row['started_at'];
        $d->ended_at = $row['ended_at'] ?? null;
        $d->notes = $row['notes'] !== null ? (string) $row['notes'] : null;
        $d->started_by_user_id = (int) $row['started_by_user_id'];
        $d->ended_by_user_id = $row['ended_by_user_id'] !== null ? (int) $row['ended_by_user_id'] : null;
        $d->created_at = $row['created_at'] ?? null;
        $d->updated_at = $row['updated_at'] ?? null;
        return $d;
    }
}
