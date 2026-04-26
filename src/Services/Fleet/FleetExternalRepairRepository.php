<?php

namespace App\Services\Fleet;

use App\Database\Connection;
use App\Models\FleetExternalRepair;
use PDO;

/**
 * Phase 7.5 of docs/expansion-plan.md — external vendor repair records.
 *
 * CRUD + listing surface. update() is whole-patch (service merges with
 * existing then passes the full column set), matching the Phase 7.1
 * FleetUnitRepository convention so UNIQUE/check-constraint violations
 * surface as single PDOException attempts rather than a spray of
 * column-by-column retries.
 */
class FleetExternalRepairRepository
{
    private const COLUMNS = 'id, fleet_unit_id, vendor_name, vendor_invoice_number, category,
        service_date, description, labor_cost, parts_cost, other_cost, total_cost,
        odometer_at_service, engine_hours_at_service, notes, attachment_path,
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
            'INSERT INTO fleet_external_repairs
                (fleet_unit_id, vendor_name, vendor_invoice_number, category,
                 service_date, description, labor_cost, parts_cost, other_cost,
                 total_cost, odometer_at_service, engine_hours_at_service,
                 notes, attachment_path, created_by_user_id,
                 created_at, updated_at)
             VALUES
                (:uid, :vn, :vi, :cat,
                 :sd, :desc, :lc, :pc, :oc,
                 :tc, :od, :eh,
                 :nt, :ap, :by,
                 CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'uid' => $row['fleet_unit_id'],
            'vn' => $row['vendor_name'],
            'vi' => $row['vendor_invoice_number'],
            'cat' => $row['category'],
            'sd' => $row['service_date'],
            'desc' => $row['description'],
            'lc' => $row['labor_cost'],
            'pc' => $row['parts_cost'],
            'oc' => $row['other_cost'],
            'tc' => $row['total_cost'],
            'od' => $row['odometer_at_service'],
            'eh' => $row['engine_hours_at_service'],
            'nt' => $row['notes'],
            'ap' => $row['attachment_path'],
            'by' => $row['created_by_user_id'],
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id): ?FleetExternalRepair
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_external_repairs WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, FleetExternalRepair>
     */
    public function listForUnit(int $unitId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fleet_external_repairs
             WHERE fleet_unit_id = :uid
             ORDER BY service_date DESC, id DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['uid' => $unitId]);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * List repairs across an entire company, optionally filtered by
     * vendor name + category + date range. Used by the reporting
     * surface + the vendor-spend rollup.
     *
     * @param array{vendor?: ?string, category?: ?string, from?: ?string, to?: ?string, limit?: ?int} $filters
     * @return array<int, FleetExternalRepair>
     */
    public function listForCompany(int $companyId, array $filters = []): array
    {
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));
        $sql = 'SELECT r.id, r.fleet_unit_id, r.vendor_name, r.vendor_invoice_number, r.category,
                    r.service_date, r.description, r.labor_cost, r.parts_cost, r.other_cost,
                    r.total_cost, r.odometer_at_service, r.engine_hours_at_service,
                    r.notes, r.attachment_path, r.created_by_user_id, r.created_at, r.updated_at
                FROM fleet_external_repairs r
                INNER JOIN fleet_units fu ON fu.id = r.fleet_unit_id
                WHERE fu.company_id = :cid';
        $params = ['cid' => $companyId];
        if (!empty($filters['vendor'])) {
            $sql .= ' AND r.vendor_name LIKE :vendor';
            $params['vendor'] = '%' . $filters['vendor'] . '%';
        }
        if (!empty($filters['category'])) {
            $sql .= ' AND r.category = :category';
            $params['category'] = $filters['category'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND r.service_date >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND r.service_date <= :to';
            $params['to'] = $filters['to'];
        }
        $sql .= ' ORDER BY r.service_date DESC, r.id DESC LIMIT ' . $limit;
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
    public function update(int $id, array $row): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE fleet_external_repairs SET
                vendor_name = :vn,
                vendor_invoice_number = :vi,
                category = :cat,
                service_date = :sd,
                description = :desc,
                labor_cost = :lc,
                parts_cost = :pc,
                other_cost = :oc,
                total_cost = :tc,
                odometer_at_service = :od,
                engine_hours_at_service = :eh,
                notes = :nt,
                attachment_path = :ap,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'vn' => $row['vendor_name'],
            'vi' => $row['vendor_invoice_number'],
            'cat' => $row['category'],
            'sd' => $row['service_date'],
            'desc' => $row['description'],
            'lc' => $row['labor_cost'],
            'pc' => $row['parts_cost'],
            'oc' => $row['other_cost'],
            'tc' => $row['total_cost'],
            'od' => $row['odometer_at_service'],
            'eh' => $row['engine_hours_at_service'],
            'nt' => $row['notes'],
            'ap' => $row['attachment_path'],
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM fleet_external_repairs WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Aggregate external repair cost per fleet unit within [from,to] for
     * Phase 7.4 cost reports includeExternal=true path. Parallels
     * FleetCostReportRepository::aggregateCostsForCompany shape so the
     * service can union the two sources cleanly.
     *
     * @return array<int, array{fleet_unit_id: int, repair_count: int, total_cost: float, labor_cost: float, parts_cost: float, other_cost: float}>
     */
    public function aggregateCostsForCompany(int $companyId, string $from, string $to): array
    {
        $sql = 'SELECT
                    r.fleet_unit_id AS fleet_unit_id,
                    COUNT(r.id) AS repair_count,
                    COALESCE(SUM(r.total_cost), 0) AS total_cost,
                    COALESCE(SUM(r.labor_cost), 0) AS labor_cost,
                    COALESCE(SUM(r.parts_cost), 0) AS parts_cost,
                    COALESCE(SUM(r.other_cost), 0) AS other_cost
                FROM fleet_external_repairs r
                INNER JOIN fleet_units fu ON fu.id = r.fleet_unit_id
                WHERE fu.company_id = :cid
                    AND r.service_date >= :from
                    AND r.service_date <= :to
                GROUP BY r.fleet_unit_id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'cid' => $companyId,
            'from' => $from,
            'to' => $to,
        ]);
        return array_map(function (array $r) {
            return [
                'fleet_unit_id' => (int) $r['fleet_unit_id'],
                'repair_count' => (int) $r['repair_count'],
                'total_cost' => (float) $r['total_cost'],
                'labor_cost' => (float) $r['labor_cost'],
                'parts_cost' => (float) $r['parts_cost'],
                'other_cost' => (float) $r['other_cost'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): FleetExternalRepair
    {
        $r = new FleetExternalRepair();
        $r->id = (int) $row['id'];
        $r->fleet_unit_id = (int) $row['fleet_unit_id'];
        $r->vendor_name = (string) $row['vendor_name'];
        $r->vendor_invoice_number = $row['vendor_invoice_number'] !== null
            ? (string) $row['vendor_invoice_number'] : null;
        $r->category = (string) $row['category'];
        $r->service_date = (string) $row['service_date'];
        $r->description = (string) $row['description'];
        $r->labor_cost = (float) $row['labor_cost'];
        $r->parts_cost = (float) $row['parts_cost'];
        $r->other_cost = (float) $row['other_cost'];
        $r->total_cost = (float) $row['total_cost'];
        $r->odometer_at_service = $row['odometer_at_service'] !== null
            ? (int) $row['odometer_at_service'] : null;
        $r->engine_hours_at_service = $row['engine_hours_at_service'] !== null
            ? (float) $row['engine_hours_at_service'] : null;
        $r->notes = $row['notes'] !== null ? (string) $row['notes'] : null;
        $r->attachment_path = $row['attachment_path'] !== null
            ? (string) $row['attachment_path'] : null;
        $r->created_by_user_id = (int) $row['created_by_user_id'];
        $r->created_at = $row['created_at'] ?? null;
        $r->updated_at = $row['updated_at'] ?? null;
        return $r;
    }
}
