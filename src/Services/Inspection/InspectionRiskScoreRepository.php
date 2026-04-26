<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionRiskScore;
use PDO;

/**
 * Phase 8.4 of docs/expansion-plan.md — CRUD for inspection_risk_scores.
 *
 * upsert() does a find-then-insert-or-update dance rather than using
 * MySQL's INSERT ... ON DUPLICATE KEY UPDATE so the same code path
 * works under the SQLite in-memory test connection. The UNIQUE on
 * inspection_report_id still guarantees at most one row per report in
 * production; the repo-level find-first is a cross-DB compatibility
 * layer, not a correctness layer.
 */
class InspectionRiskScoreRepository
{
    private const COLUMNS = 'id, inspection_report_id, vehicle_id, customer_id, division_id, total_score, risk_level, failed_item_count, critical_count, high_count, medium_count, low_count, compliance_tagged_count, scored_at, scored_by_user_id, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{
     *   inspection_report_id: int,
     *   vehicle_id?: ?int,
     *   customer_id?: ?int,
     *   division_id?: ?int,
     *   total_score: float,
     *   risk_level: string,
     *   failed_item_count: int,
     *   critical_count: int,
     *   high_count: int,
     *   medium_count: int,
     *   low_count: int,
     *   compliance_tagged_count: int,
     *   scored_by_user_id?: ?int
     * } $data
     */
    public function upsert(array $data): int
    {
        $existing = $this->findByReportId((int) $data['inspection_report_id']);
        if ($existing !== null) {
            $this->update($existing->id, $data);
            return $existing->id;
        }
        return $this->insert($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insert(array $data): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO inspection_risk_scores
                (inspection_report_id, vehicle_id, customer_id, division_id,
                 total_score, risk_level,
                 failed_item_count, critical_count, high_count, medium_count, low_count,
                 compliance_tagged_count,
                 scored_at, scored_by_user_id, created_at, updated_at)
             VALUES
                (:report_id, :vehicle_id, :customer_id, :division_id,
                 :total_score, :risk_level,
                 :failed_item_count, :critical_count, :high_count, :medium_count, :low_count,
                 :compliance_tagged_count,
                 CURRENT_TIMESTAMP, :scored_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'report_id' => (int) $data['inspection_report_id'],
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'division_id' => $data['division_id'] ?? null,
            'total_score' => (float) $data['total_score'],
            'risk_level' => (string) $data['risk_level'],
            'failed_item_count' => (int) $data['failed_item_count'],
            'critical_count' => (int) $data['critical_count'],
            'high_count' => (int) $data['high_count'],
            'medium_count' => (int) $data['medium_count'],
            'low_count' => (int) $data['low_count'],
            'compliance_tagged_count' => (int) $data['compliance_tagged_count'],
            'scored_by' => $data['scored_by_user_id'] ?? null,
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function update(int $id, array $data): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inspection_risk_scores
             SET vehicle_id = :vehicle_id,
                 customer_id = :customer_id,
                 division_id = :division_id,
                 total_score = :total_score,
                 risk_level = :risk_level,
                 failed_item_count = :failed_item_count,
                 critical_count = :critical_count,
                 high_count = :high_count,
                 medium_count = :medium_count,
                 low_count = :low_count,
                 compliance_tagged_count = :compliance_tagged_count,
                 scored_at = CURRENT_TIMESTAMP,
                 scored_by_user_id = :scored_by,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'division_id' => $data['division_id'] ?? null,
            'total_score' => (float) $data['total_score'],
            'risk_level' => (string) $data['risk_level'],
            'failed_item_count' => (int) $data['failed_item_count'],
            'critical_count' => (int) $data['critical_count'],
            'high_count' => (int) $data['high_count'],
            'medium_count' => (int) $data['medium_count'],
            'low_count' => (int) $data['low_count'],
            'compliance_tagged_count' => (int) $data['compliance_tagged_count'],
            'scored_by' => $data['scored_by_user_id'] ?? null,
            'id' => $id,
        ]);
    }

    public function findByReportId(int $reportId): ?InspectionRiskScore
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM inspection_risk_scores
             WHERE inspection_report_id = :report_id LIMIT 1'
        );
        $stmt->execute(['report_id' => $reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Chronological list of a vehicle's scored reports. Optional date
     * window is inclusive on both ends; scored_at bounds are compared
     * against DATE strings (YYYY-MM-DD) so callers don't need to know
     * the server TZ.
     *
     * @return array<int, InspectionRiskScore>
     */
    public function listForVehicle(int $vehicleId, ?string $from = null, ?string $to = null, int $limit = 100): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM inspection_risk_scores WHERE vehicle_id = :vehicle_id';
        $params = ['vehicle_id' => $vehicleId];
        if ($from !== null) {
            $sql .= ' AND scored_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $sql .= ' AND scored_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }
        $sql .= ' ORDER BY scored_at ASC, id ASC LIMIT ' . max(1, min($limit, 1000));

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn(array $r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Division-scoped scored reports. NULL-division report (i.e. the
     * template had no division) is filtered out when a specific
     * division is requested — it's visible only in the "all" view.
     *
     * @return array<int, InspectionRiskScore>
     */
    public function listForDivision(int $divisionId, string $from, string $to, int $limit = 500): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM inspection_risk_scores
             WHERE division_id = :division_id
               AND scored_at >= :from
               AND scored_at <= :to
             ORDER BY scored_at ASC, id ASC
             LIMIT ' . max(1, min($limit, 5000))
        );
        $stmt->execute([
            'division_id' => $divisionId,
            'from' => $from . ' 00:00:00',
            'to' => $to . ' 23:59:59',
        ]);
        return array_map(fn(array $r) => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): InspectionRiskScore
    {
        return new InspectionRiskScore([
            'id' => (int) $row['id'],
            'inspection_report_id' => (int) $row['inspection_report_id'],
            'vehicle_id' => $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
            'customer_id' => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            'division_id' => $row['division_id'] !== null ? (int) $row['division_id'] : null,
            'total_score' => (float) $row['total_score'],
            'risk_level' => (string) $row['risk_level'],
            'failed_item_count' => (int) $row['failed_item_count'],
            'critical_count' => (int) $row['critical_count'],
            'high_count' => (int) $row['high_count'],
            'medium_count' => (int) $row['medium_count'],
            'low_count' => (int) $row['low_count'],
            'compliance_tagged_count' => (int) $row['compliance_tagged_count'],
            'scored_at' => $row['scored_at'] ?? null,
            'scored_by_user_id' => $row['scored_by_user_id'] !== null ? (int) $row['scored_by_user_id'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }
}
