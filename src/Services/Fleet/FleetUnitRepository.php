<?php

namespace App\Services\Fleet;

use App\Database\Connection;
use App\Models\FleetUnit;
use PDO;

/**
 * Phase 7.1 of docs/expansion-plan.md — data access for fleet_units.
 *
 * Reads filter by company_id at the SQL layer so the service can treat
 * the returned rows as "already scoped". Update is whole-row (callers
 * pass the full patch) so UNIQUE validation collapses into a single
 * INSERT/UPDATE attempt the service can catch.
 */
class FleetUnitRepository
{
    private const COLUMNS = 'id, company_id, unit_number, unit_type, vin, year, make, model, trim,
        license_plate, home_site_id, meter_type, current_odometer, current_engine_hours,
        odometer_last_read_at, engine_hours_last_read_at, status, notes,
        created_by_user_id, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function create(array $row): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO fleet_units
                (company_id, unit_number, unit_type, vin, year, make, model, trim,
                 license_plate, home_site_id, meter_type,
                 status, notes, created_by_user_id, created_at, updated_at)
             VALUES
                (:co, :un, :ut, :vin, :yr, :mk, :md, :tr,
                 :lp, :hs, :mt,
                 :st, :nt, :by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'co' => $row['company_id'],
            'un' => $row['unit_number'],
            'ut' => $row['unit_type'],
            'vin' => $row['vin'],
            'yr' => $row['year'],
            'mk' => $row['make'],
            'md' => $row['model'],
            'tr' => $row['trim'],
            'lp' => $row['license_plate'],
            'hs' => $row['home_site_id'],
            'mt' => $row['meter_type'],
            'st' => $row['status'],
            'nt' => $row['notes'],
            'by' => $row['created_by_user_id'],
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id): ?FleetUnit
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_units WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByVin(string $vin): ?FleetUnit
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_units WHERE vin = :vin LIMIT 1'
        );
        $stmt->execute(['vin' => $vin]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array{status?: string, home_site_id?: int, query?: string, limit?: int, offset?: int} $filters
     * @return array{data: array<int, FleetUnit>, total: int}
     */
    public function listForCompany(int $companyId, array $filters = []): array
    {
        $where = ['company_id = :co'];
        $params = ['co' => $companyId];

        if (!empty($filters['status'])) {
            $where[] = 'status = :st';
            $params['st'] = $filters['status'];
        }
        if (!empty($filters['home_site_id'])) {
            $where[] = 'home_site_id = :hs';
            $params['hs'] = (int) $filters['home_site_id'];
        }
        if (!empty($filters['query'])) {
            $where[] = '(unit_number LIKE :q OR vin LIKE :q OR license_plate LIKE :q OR make LIKE :q OR model LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $pdo = $this->connection->pdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM fleet_units WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listSql = 'SELECT ' . self::COLUMNS . " FROM fleet_units WHERE {$whereSql}
                    ORDER BY unit_number ASC LIMIT {$limit} OFFSET {$offset}";
        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'data' => array_map(fn(array $r) => $this->hydrate($r), $rows),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function update(int $id, array $row): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE fleet_units SET
                unit_number = :un, unit_type = :ut, vin = :vin,
                year = :yr, make = :mk, model = :md, trim = :tr,
                license_plate = :lp, home_site_id = :hs, meter_type = :mt,
                status = :st, notes = :nt,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'un' => $row['unit_number'],
            'ut' => $row['unit_type'],
            'vin' => $row['vin'],
            'yr' => $row['year'],
            'mk' => $row['make'],
            'md' => $row['model'],
            'tr' => $row['trim'],
            'lp' => $row['license_plate'],
            'hs' => $row['home_site_id'],
            'mt' => $row['meter_type'],
            'st' => $row['status'],
            'nt' => $row['notes'],
        ]);
    }

    /**
     * Targeted status setter (Phase 7.3). The downtime service flips
     * status to out_of_service / active without disturbing any other
     * field — a full-row update would force a re-validate of the unit's
     * identity columns (VIN, unit_number) on every status transition.
     */
    public function setStatus(int $id, string $status): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE fleet_units SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute(['st' => $status, 'id' => $id]);
    }

    /**
     * Update the denormalized current_* meter cache on fleet_units.
     * Called after a reading insert so the list-view query doesn't have
     * to correlate-subquery the readings table on every row.
     */
    public function updateMeterCache(
        int $id,
        ?int $currentOdometer,
        ?float $currentEngineHours,
        ?string $odometerReadAt,
        ?string $engineHoursReadAt,
    ): void {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE fleet_units SET
                current_odometer = COALESCE(:od, current_odometer),
                current_engine_hours = COALESCE(:eh, current_engine_hours),
                odometer_last_read_at = COALESCE(:odt, odometer_last_read_at),
                engine_hours_last_read_at = COALESCE(:eht, engine_hours_last_read_at),
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'od' => $currentOdometer,
            'eh' => $currentEngineHours,
            'odt' => $odometerReadAt,
            'eht' => $engineHoursReadAt,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FleetUnit
    {
        $u = new FleetUnit();
        $u->id = (int) $row['id'];
        $u->company_id = (int) $row['company_id'];
        $u->unit_number = (string) $row['unit_number'];
        $u->unit_type = (string) $row['unit_type'];
        $u->vin = $row['vin'] !== null ? (string) $row['vin'] : null;
        $u->year = $row['year'] !== null ? (int) $row['year'] : null;
        $u->make = $row['make'] !== null ? (string) $row['make'] : null;
        $u->model = $row['model'] !== null ? (string) $row['model'] : null;
        $u->trim = $row['trim'] !== null ? (string) $row['trim'] : null;
        $u->license_plate = $row['license_plate'] !== null ? (string) $row['license_plate'] : null;
        $u->home_site_id = $row['home_site_id'] !== null ? (int) $row['home_site_id'] : null;
        $u->meter_type = (string) $row['meter_type'];
        $u->current_odometer = $row['current_odometer'] !== null ? (int) $row['current_odometer'] : null;
        $u->current_engine_hours = $row['current_engine_hours'] !== null ? (float) $row['current_engine_hours'] : null;
        $u->odometer_last_read_at = $row['odometer_last_read_at'] ?? null;
        $u->engine_hours_last_read_at = $row['engine_hours_last_read_at'] ?? null;
        $u->status = (string) $row['status'];
        $u->notes = $row['notes'] !== null ? (string) $row['notes'] : null;
        $u->created_by_user_id = (int) $row['created_by_user_id'];
        $u->created_at = $row['created_at'] ?? null;
        $u->updated_at = $row['updated_at'] ?? null;
        return $u;
    }
}
