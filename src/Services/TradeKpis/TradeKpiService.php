<?php

namespace App\Services\TradeKpis;

use App\Database\Connection;
use InvalidArgumentException;
use PDO;

/**
 * Trade-specific KPI dashboards — Phase 17 / S10 of
 * docs/woms-expansion-plan.md.
 *
 * The plan calls out specific metrics per trade:
 *   - equipment / fleet:    MTBF, MTTR (asset reliability)
 *   - it_support:           first-call-resolution %, ticket resolution time
 *   - commercial_cleaning:  route-completion rate (route_visits)
 *   - security / pos:       install-on-time % (workorders vs estimated_completion)
 *
 * Rather than exposing a separate endpoint per trade, this service computes
 * the full bundle for one service line in a date window. The React layer
 * emphasizes the trade-relevant tiles per service-line slug; the rest are
 * still useful and rendered in a secondary group.
 *
 * No new migration: this is pure aggregation against existing tables.
 */
class TradeKpiService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, array{id: int, slug: string, name: string}>
     */
    public function listServiceLines(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT id, slug, name FROM service_lines WHERE is_active = 1 ORDER BY sort_order, name'
        );
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'name' => (string) $r['name'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Compute the KPI bundle for one service line in a window.
     *
     * @return array{
     *   service_line: ?array<string, mixed>,
     *   period: array{from: string, to: string},
     *   reliability: array{mttr_hours: ?float, mtbf_days: ?float, sample_size: int},
     *   tickets: array{
     *      created: int, resolved: int, first_call_resolved: int,
     *      first_call_resolution_pct: ?float,
     *      avg_resolution_hours: ?float
     *   },
     *   workorders: array{
     *      total: int, completed: int, completed_on_time: int,
     *      install_on_time_pct: ?float,
     *      revenue: float
     *   },
     *   routes: array{
     *      planned: int, completed: int, missed: int, skipped: int,
     *      completion_pct: ?float
     *   }
     * }
     */
    public function bundle(int $serviceLineId, string $periodStart, string $periodEnd): array
    {
        $this->assertValidPeriod($periodStart, $periodEnd);

        $serviceLine = $this->fetchServiceLine($serviceLineId);
        if ($serviceLine === null) {
            throw new InvalidArgumentException("service_line {$serviceLineId} not found");
        }

        return [
            'service_line' => $serviceLine,
            'period' => ['from' => $periodStart, 'to' => $periodEnd],
            'reliability' => $this->reliabilityMetrics($serviceLineId, $periodStart, $periodEnd),
            'tickets' => $this->ticketMetrics($serviceLineId, $periodStart, $periodEnd),
            'workorders' => $this->workorderMetrics($serviceLineId, $periodStart, $periodEnd),
            'routes' => $this->routeMetrics($serviceLineId, $periodStart, $periodEnd),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchServiceLine(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, slug, name, description, icon FROM service_lines WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * MTTR  = avg(completed_at - started_at) across completed WOs in window
     *         that have a site_asset_id (so they represent asset repair).
     * MTBF  = avg gap between consecutive completed WOs per same site_asset
     *         in window. Aggregated across all assets in the service line.
     *
     * @return array{mttr_hours: ?float, mtbf_days: ?float, sample_size: int}
     */
    private function reliabilityMetrics(int $serviceLineId, string $periodStart, string $periodEnd): array
    {
        $pdo = $this->connection->pdo();

        $mttrStmt = $pdo->prepare(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) AS avg_minutes,
                    COUNT(*) AS cnt
               FROM workorders
              WHERE service_line_id = :sl
                AND site_asset_id IS NOT NULL
                AND status IN ("completed","closed")
                AND started_at IS NOT NULL
                AND completed_at IS NOT NULL
                AND completed_at BETWEEN :ps AND :pe'
        );
        $mttrStmt->execute([
            'sl' => $serviceLineId,
            'ps' => $periodStart . ' 00:00:00',
            'pe' => $periodEnd . ' 23:59:59',
        ]);
        $mttrRow = $mttrStmt->fetch(PDO::FETCH_ASSOC) ?: ['avg_minutes' => null, 'cnt' => 0];

        $mtbfStmt = $pdo->prepare(
            'SELECT AVG(gap_days) AS avg_gap, COUNT(*) AS sample
               FROM (
                   SELECT TIMESTAMPDIFF(
                       SECOND,
                       LAG(completed_at) OVER (PARTITION BY site_asset_id ORDER BY completed_at),
                       completed_at
                   ) / 86400.0 AS gap_days
                     FROM workorders
                    WHERE service_line_id = :sl
                      AND site_asset_id IS NOT NULL
                      AND status IN ("completed","closed")
                      AND completed_at IS NOT NULL
                      AND completed_at BETWEEN :ps AND :pe
               ) g
              WHERE g.gap_days IS NOT NULL AND g.gap_days > 0'
        );
        $mtbfStmt->execute([
            'sl' => $serviceLineId,
            'ps' => $periodStart . ' 00:00:00',
            'pe' => $periodEnd . ' 23:59:59',
        ]);
        $mtbfRow = $mtbfStmt->fetch(PDO::FETCH_ASSOC) ?: ['avg_gap' => null, 'sample' => 0];

        return [
            'mttr_hours' => $mttrRow['avg_minutes'] !== null
                ? round((float) $mttrRow['avg_minutes'] / 60.0, 2)
                : null,
            'mtbf_days' => $mtbfRow['avg_gap'] !== null
                ? round((float) $mtbfRow['avg_gap'], 2)
                : null,
            'sample_size' => (int) $mttrRow['cnt'],
        ];
    }

    /**
     * @return array{
     *   created: int, resolved: int, first_call_resolved: int,
     *   first_call_resolution_pct: ?float, avg_resolution_hours: ?float
     * }
     */
    private function ticketMetrics(int $serviceLineId, string $periodStart, string $periodEnd): array
    {
        $pdo = $this->connection->pdo();

        $createdStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM tickets
              WHERE service_line_id = :sl
                AND created_at BETWEEN :ps AND :pe'
        );
        $createdStmt->execute(['sl' => $serviceLineId, 'ps' => $periodStart . ' 00:00:00', 'pe' => $periodEnd . ' 23:59:59']);
        $created = (int) $createdStmt->fetchColumn();

        $resolvedStmt = $pdo->prepare(
            'SELECT COUNT(*),
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) AS avg_minutes
               FROM tickets
              WHERE service_line_id = :sl
                AND resolved_at IS NOT NULL
                AND resolved_at BETWEEN :ps AND :pe'
        );
        $resolvedStmt->execute(['sl' => $serviceLineId, 'ps' => $periodStart . ' 00:00:00', 'pe' => $periodEnd . ' 23:59:59']);
        $resolvedRow = $resolvedStmt->fetch(PDO::FETCH_NUM) ?: [0, null];
        $resolved = (int) $resolvedRow[0];
        $avgMinutes = $resolvedRow[1] !== null ? (float) $resolvedRow[1] : null;

        $firstCallStmt = $pdo->prepare(
            'SELECT COUNT(*)
               FROM tickets t
              WHERE t.service_line_id = :sl
                AND t.resolved_at IS NOT NULL
                AND t.resolved_at BETWEEN :ps AND :pe
                AND NOT EXISTS (
                    SELECT 1 FROM ticket_workorder_links l WHERE l.ticket_id = t.id
                )'
        );
        $firstCallStmt->execute(['sl' => $serviceLineId, 'ps' => $periodStart . ' 00:00:00', 'pe' => $periodEnd . ' 23:59:59']);
        $firstCall = (int) $firstCallStmt->fetchColumn();

        return [
            'created' => $created,
            'resolved' => $resolved,
            'first_call_resolved' => $firstCall,
            'first_call_resolution_pct' => $resolved > 0
                ? round(($firstCall / $resolved) * 100, 1)
                : null,
            'avg_resolution_hours' => $avgMinutes !== null ? round($avgMinutes / 60, 2) : null,
        ];
    }

    /**
     * Workorder volume + install-on-time % (completed_at <= estimated_completion).
     *
     * @return array{total: int, completed: int, completed_on_time: int, install_on_time_pct: ?float, revenue: float}
     */
    private function workorderMetrics(int $serviceLineId, string $periodStart, string $periodEnd): array
    {
        $pdo = $this->connection->pdo();

        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ("completed","closed") THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status IN ("completed","closed")
                          AND estimated_completion IS NOT NULL
                          AND completed_at IS NOT NULL
                          AND DATE(completed_at) <= estimated_completion
                         THEN 1 ELSE 0 END) AS on_time,
                SUM(CASE WHEN status IN ("completed","closed")
                          AND estimated_completion IS NOT NULL
                          AND completed_at IS NOT NULL
                         THEN 1 ELSE 0 END) AS scoreable,
                COALESCE(SUM(CASE WHEN status IN ("completed","closed") THEN grand_total ELSE 0 END),0) AS revenue
              FROM workorders
             WHERE service_line_id = :sl
               AND created_at BETWEEN :ps AND :pe'
        );
        $stmt->execute(['sl' => $serviceLineId, 'ps' => $periodStart . ' 00:00:00', 'pe' => $periodEnd . ' 23:59:59']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total' => 0, 'completed' => 0, 'on_time' => 0, 'scoreable' => 0, 'revenue' => 0,
        ];

        $scoreable = (int) $row['scoreable'];
        $onTime = (int) $row['on_time'];

        return [
            'total' => (int) $row['total'],
            'completed' => (int) $row['completed'],
            'completed_on_time' => $onTime,
            'install_on_time_pct' => $scoreable > 0 ? round(($onTime / $scoreable) * 100, 1) : null,
            'revenue' => (float) $row['revenue'],
        ];
    }

    /**
     * @return array{planned: int, completed: int, missed: int, skipped: int, completion_pct: ?float}
     */
    private function routeMetrics(int $serviceLineId, string $periodStart, string $periodEnd): array
    {
        $pdo = $this->connection->pdo();

        $stmt = $pdo->prepare(
            'SELECT
                SUM(CASE WHEN v.status = "planned"   THEN 1 ELSE 0 END) AS planned,
                SUM(CASE WHEN v.status = "completed" THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN v.status = "missed"    THEN 1 ELSE 0 END) AS missed,
                SUM(CASE WHEN v.status = "skipped"   THEN 1 ELSE 0 END) AS skipped,
                COUNT(*) AS total
              FROM route_visits v
              JOIN service_routes r ON r.id = v.service_route_id
             WHERE r.service_line_id = :sl
               AND v.scheduled_for BETWEEN :ps AND :pe'
        );
        $stmt->execute(['sl' => $serviceLineId, 'ps' => $periodStart . ' 00:00:00', 'pe' => $periodEnd . ' 23:59:59']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'planned' => 0, 'completed' => 0, 'missed' => 0, 'skipped' => 0, 'total' => 0,
        ];

        $total = (int) $row['total'];
        $completed = (int) $row['completed'];

        return [
            'planned' => (int) $row['planned'],
            'completed' => $completed,
            'missed' => (int) $row['missed'],
            'skipped' => (int) $row['skipped'],
            'completion_pct' => $total > 0 ? round(($completed / $total) * 100, 1) : null,
        ];
    }

    private function assertValidPeriod(string $periodStart, string $periodEnd): void
    {
        $ts1 = strtotime($periodStart);
        $ts2 = strtotime($periodEnd);
        if (!$ts1 || !$ts2) {
            throw new InvalidArgumentException('period start and end must be valid dates');
        }
        if ($ts1 > $ts2) {
            throw new InvalidArgumentException('period start must be <= end');
        }
    }
}
