<?php

namespace App\Services\Fleet;

use App\Database\Connection;
use PDO;

/**
 * Phase 7.4 of docs/expansion-plan.md — per-unit cost + meter aggregation.
 *
 * Exposes two read-only aggregates the FleetCostReportService joins in
 * memory to produce cost-per-mile / cost-per-hour reports. Kept in its
 * own repository (rather than inside FleetUnitRepository or
 * WorkorderRepository) because the queries bridge the workorder +
 * fleet_unit_readings tables and don't fit either side's CRUD shape.
 *
 * All queries filter by company_id up front so the service doesn't have
 * to re-scope — a cross-company leak here is an SQL bug, not a service
 * oversight.
 */
class FleetCostReportRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Aggregate completed-workorder cost per fleet unit within [from,to].
     *
     * completed_at is the filter dimension (not created_at) because cost
     * reports answer "what did we spend on this unit in Q3" — the
     * workorder closing month, not the month we opened it. Workorders
     * without completed_at (still in progress) are intentionally excluded
     * so the report reflects settled cost.
     *
     * labor_cost / parts_cost come from workorder_items.type matched
     * case-insensitively against 'LABOR' / 'PART' via UPPER() — the
     * workorder pipeline stores uppercase ('LABOR', 'PART') but the
     * estimate pipeline has historically used lowercase too, so UPPER()
     * is the safe comparison. Other item types (fee, service, etc.)
     * roll into total_cost via grand_total but don't count toward the
     * labor/parts split.
     *
     * @return array<int, array{fleet_unit_id: int, workorder_count: int, total_cost: float, labor_cost: float, parts_cost: float}>
     */
    public function aggregateCostsForCompany(int $companyId, string $from, string $to): array
    {
        $sql = 'SELECT
                    w.fleet_unit_id AS fleet_unit_id,
                    COUNT(DISTINCT w.id) AS workorder_count,
                    COALESCE(SUM(w.grand_total), 0) AS total_cost,
                    COALESCE((
                        SELECT SUM(wi.line_total)
                        FROM workorder_items wi
                        INNER JOIN workorder_jobs wj ON wj.id = wi.workorder_job_id
                        WHERE wj.workorder_id = w.id AND UPPER(wi.type) = :labor_type
                    ), 0) AS labor_cost,
                    COALESCE((
                        SELECT SUM(wi.line_total)
                        FROM workorder_items wi
                        INNER JOIN workorder_jobs wj ON wj.id = wi.workorder_job_id
                        WHERE wj.workorder_id = w.id AND UPPER(wi.type) = :parts_type
                    ), 0) AS parts_cost
                FROM workorders w
                INNER JOIN fleet_units fu ON fu.id = w.fleet_unit_id
                WHERE fu.company_id = :company_id
                    AND w.fleet_unit_id IS NOT NULL
                    AND w.completed_at IS NOT NULL
                    AND w.completed_at >= :from
                    AND w.completed_at <= :to
                GROUP BY w.id, w.fleet_unit_id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'company_id' => $companyId,
            'from' => $from,
            'to' => $to,
            'labor_type' => 'LABOR',
            'parts_type' => 'PART',
        ]);
        // Subquery correlation is per-workorder, so fold back to unit-level here.
        $byUnit = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $uid = (int) $r['fleet_unit_id'];
            if (!isset($byUnit[$uid])) {
                $byUnit[$uid] = [
                    'fleet_unit_id' => $uid,
                    'workorder_count' => 0,
                    'total_cost' => 0.0,
                    'labor_cost' => 0.0,
                    'parts_cost' => 0.0,
                ];
            }
            $byUnit[$uid]['workorder_count'] += (int) $r['workorder_count'];
            $byUnit[$uid]['total_cost'] += (float) $r['total_cost'];
            $byUnit[$uid]['labor_cost'] += (float) $r['labor_cost'];
            $byUnit[$uid]['parts_cost'] += (float) $r['parts_cost'];
        }
        return array_values($byUnit);
    }

    /**
     * Return the first + last reading for each unit within [from,to] for
     * the given reading_type. Delta = last_value - first_value gives the
     * miles/hours accumulated in the window. Units with 0 or 1 reading
     * in the window have zero delta and get a null cost-per-unit in the
     * service layer (rather than a divide-by-zero NaN).
     *
     * recorded_at is the sort/filter key (NOT created_at) so a reading
     * backfilled later for a date that already passed lands in the
     * correct report window.
     *
     * @return array<int, array{fleet_unit_id: int, first_value: float, first_at: string, last_value: float, last_at: string}>
     */
    public function readingDeltasForCompany(
        int $companyId,
        string $readingType,
        string $from,
        string $to
    ): array {
        $sql = 'SELECT
                    r.fleet_unit_id AS fleet_unit_id,
                    r.value AS value,
                    r.recorded_at AS recorded_at
                FROM fleet_unit_readings r
                INNER JOIN fleet_units fu ON fu.id = r.fleet_unit_id
                WHERE fu.company_id = :company_id
                    AND r.reading_type = :reading_type
                    AND r.recorded_at >= :from
                    AND r.recorded_at <= :to
                ORDER BY r.fleet_unit_id ASC, r.recorded_at ASC, r.id ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'company_id' => $companyId,
            'reading_type' => $readingType,
            'from' => $from,
            'to' => $to,
        ]);
        $byUnit = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $uid = (int) $row['fleet_unit_id'];
            $value = (float) $row['value'];
            $at = (string) $row['recorded_at'];
            if (!isset($byUnit[$uid])) {
                $byUnit[$uid] = [
                    'fleet_unit_id' => $uid,
                    'first_value' => $value,
                    'first_at' => $at,
                    'last_value' => $value,
                    'last_at' => $at,
                ];
            } else {
                // ORDER BY recorded_at ASC, so every subsequent row is
                // later or equal — update last_* unconditionally and let
                // the last same-timestamp row (disambiguated by id ASC)
                // win.
                $byUnit[$uid]['last_value'] = $value;
                $byUnit[$uid]['last_at'] = $at;
            }
        }
        return array_values($byUnit);
    }

    /**
     * Fetch lightweight unit identity rows (id + unit_number + unit_type
     * + status) for a set of unit ids. Used to join unit metadata onto
     * report rows without pulling the full FleetUnit model into the
     * service layer.
     *
     * @param array<int, int> $unitIds
     * @return array<int, array{id: int, unit_number: string, unit_type: string, status: string}>
     */
    public function fetchUnitIdentities(int $companyId, array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }
        $unitIds = array_values(array_unique(array_map('intval', $unitIds)));
        $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
        $sql = 'SELECT id, unit_number, unit_type, status FROM fleet_units
                WHERE company_id = ? AND id IN (' . $placeholders . ')';
        $params = array_merge([$companyId], $unitIds);
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'],
                'unit_number' => (string) $r['unit_number'],
                'unit_type' => (string) $r['unit_type'],
                'status' => (string) $r['status'],
            ];
        }
        return $out;
    }
}
