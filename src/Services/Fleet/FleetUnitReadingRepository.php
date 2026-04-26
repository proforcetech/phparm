<?php

namespace App\Services\Fleet;

use App\Database\Connection;
use App\Models\FleetUnitReading;
use PDO;

/**
 * Phase 7.1 of docs/expansion-plan.md — append-only meter history.
 *
 * No update/delete methods — monotonic invariants (odometer never goes
 * backwards) are maintained by the service before insert. Removing a
 * reading requires a superseding correction record, which matches how
 * telematics systems reconcile bad reads.
 */
class FleetUnitReadingRepository
{
    private const COLUMNS = 'id, fleet_unit_id, reading_type, value, recorded_at, source,
        workorder_id, notes, recorded_by_user_id, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function create(array $row): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO fleet_unit_readings
                (fleet_unit_id, reading_type, value, recorded_at, source,
                 workorder_id, notes, recorded_by_user_id, created_at)
             VALUES
                (:uid, :rt, :val, :rec, :src, :wo, :nt, :by, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'uid' => $row['fleet_unit_id'],
            'rt' => $row['reading_type'],
            'val' => $row['value'],
            'rec' => $row['recorded_at'],
            'src' => $row['source'],
            'wo' => $row['workorder_id'],
            'nt' => $row['notes'],
            'by' => $row['recorded_by_user_id'],
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id): ?FleetUnitReading
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_unit_readings WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findLatestForUnit(int $unitId, string $readingType): ?FleetUnitReading
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_unit_readings
             WHERE fleet_unit_id = :uid AND reading_type = :rt
             ORDER BY recorded_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $unitId, 'rt' => $readingType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, FleetUnitReading>
     */
    public function listForUnit(int $unitId, ?string $readingType = null, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT ' . self::COLUMNS . ' FROM fleet_unit_readings WHERE fleet_unit_id = :uid';
        $params = ['uid' => $unitId];
        if ($readingType !== null) {
            $sql .= ' AND reading_type = :rt';
            $params['rt'] = $readingType;
        }
        $sql .= " ORDER BY recorded_at DESC, id DESC LIMIT {$limit}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FleetUnitReading
    {
        $r = new FleetUnitReading();
        $r->id = (int) $row['id'];
        $r->fleet_unit_id = (int) $row['fleet_unit_id'];
        $r->reading_type = (string) $row['reading_type'];
        $r->value = (float) $row['value'];
        $r->recorded_at = (string) $row['recorded_at'];
        $r->source = (string) $row['source'];
        $r->workorder_id = $row['workorder_id'] !== null ? (int) $row['workorder_id'] : null;
        $r->notes = $row['notes'] !== null ? (string) $row['notes'] : null;
        $r->recorded_by_user_id = (int) $row['recorded_by_user_id'];
        $r->created_at = $row['created_at'] ?? null;
        return $r;
    }
}
