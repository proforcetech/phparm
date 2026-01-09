<?php

namespace App\Services\Reports;

use App\Database\Connection;
use App\Support\SettingsRepository;
use PDO;

class TechnicianMarginReportService
{
    private Connection $connection;
    private SettingsRepository $settings;

    public function __construct(Connection $connection, SettingsRepository $settings)
    {
        $this->connection = $connection;
        $this->settings = $settings;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, summary: array<string, mixed>, filters: array<string, mixed>}
     */
    public function report(string $startDate, string $endDate, ?int $branchId = null): array
    {
        $start = $startDate . ' 00:00:00';
        $end = $endDate . ' 23:59:59';
        $defaultLaborRate = (float) ($this->settings->get('pricing.labor_rate', 0) ?? 0.0);

        $billed = $this->fetchBilledLabor($start, $end, $branchId);
        $actual = $this->fetchActualLabor($start, $end, $branchId, $defaultLaborRate);

        $technicians = [];

        foreach ($billed as $row) {
            $techId = (int) $row['technician_id'];
            $technicians[$techId] = [
                'technician_id' => $techId,
                'technician_name' => $row['technician_name'] ?? null,
                'billed_labor' => (float) $row['billed_labor'],
                'actual_labor_cost' => 0.0,
                'actual_minutes' => 0.0,
            ];
        }

        foreach ($actual as $row) {
            $techId = (int) $row['technician_id'];
            if (!isset($technicians[$techId])) {
                $technicians[$techId] = [
                    'technician_id' => $techId,
                    'technician_name' => $row['technician_name'] ?? null,
                    'billed_labor' => 0.0,
                    'actual_labor_cost' => 0.0,
                    'actual_minutes' => 0.0,
                ];
            }

            $technicians[$techId]['technician_name'] = $technicians[$techId]['technician_name'] ?? $row['technician_name'] ?? null;
            $technicians[$techId]['actual_labor_cost'] = (float) $row['actual_cost'];
            $technicians[$techId]['actual_minutes'] = (float) $row['actual_minutes'];
        }

        $data = [];
        $totalBilled = 0.0;
        $totalActualCost = 0.0;
        $totalActualMinutes = 0.0;

        foreach ($technicians as $tech) {
            $margin = $tech['billed_labor'] - $tech['actual_labor_cost'];
            $marginPercentage = $tech['billed_labor'] > 0
                ? round(($margin / $tech['billed_labor']) * 100, 2)
                : 0.0;

            $data[] = [
                'technician_id' => $tech['technician_id'],
                'technician_name' => $tech['technician_name'],
                'billed_labor' => $tech['billed_labor'],
                'actual_labor_cost' => $tech['actual_labor_cost'],
                'actual_minutes' => $tech['actual_minutes'],
                'margin' => $margin,
                'margin_percentage' => $marginPercentage,
            ];

            $totalBilled += $tech['billed_labor'];
            $totalActualCost += $tech['actual_labor_cost'];
            $totalActualMinutes += $tech['actual_minutes'];
        }

        $overallMargin = $totalBilled - $totalActualCost;
        $overallMarginPercentage = $totalBilled > 0 ? round(($overallMargin / $totalBilled) * 100, 2) : 0.0;

        return [
            'data' => $data,
            'summary' => [
                'total_billed_labor' => $totalBilled,
                'total_actual_cost' => $totalActualCost,
                'total_actual_minutes' => $totalActualMinutes,
                'total_margin' => $overallMargin,
                'overall_margin_percentage' => $overallMarginPercentage,
                'default_labor_rate' => $defaultLaborRate,
            ],
            'filters' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'branch_id' => $branchId,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchBilledLabor(string $start, string $end, ?int $branchId): array
    {
        $sql = 'SELECT
                COALESCE(wj.assigned_technician_id, w.assigned_technician_id) AS technician_id,
                u.name AS technician_name,
                SUM(wi.line_total) AS billed_labor
            FROM workorder_items wi
            JOIN workorder_jobs wj ON wj.id = wi.workorder_job_id
            JOIN workorders w ON w.id = wj.workorder_id
            LEFT JOIN users u ON u.id = COALESCE(wj.assigned_technician_id, w.assigned_technician_id)
            WHERE UPPER(wi.type) = \'LABOR\'
                AND COALESCE(wj.completed_at, w.completed_at, wj.updated_at, w.updated_at, wj.created_at, w.created_at)
                    BETWEEN :start AND :end
                AND COALESCE(wj.assigned_technician_id, w.assigned_technician_id) IS NOT NULL';

        $params = [
            'start' => $start,
            'end' => $end,
        ];

        if ($branchId !== null) {
            $sql .= ' AND w.branch_id = :branch_id';
            $params['branch_id'] = $branchId;
        }

        $sql .= ' GROUP BY technician_id, technician_name ORDER BY technician_name';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchActualLabor(string $start, string $end, ?int $branchId, float $defaultLaborRate): array
    {
        $sql = 'SELECT
                te.technician_id,
                u.name AS technician_name,
                SUM(te.duration_minutes) AS actual_minutes,
                SUM((te.duration_minutes / 60) * COALESCE(lt.labor_rate, :default_rate)) AS actual_cost
            FROM time_entries te
            JOIN users u ON u.id = te.technician_id
            LEFT JOIN labor_tasks lt ON lt.id = te.task_id
            LEFT JOIN workorder_jobs wj ON wj.id = te.workorder_job_id
            LEFT JOIN workorders w ON w.id = wj.workorder_id
            WHERE te.ended_at IS NOT NULL
                AND te.status = \'approved\'
                AND te.started_at BETWEEN :start AND :end
                AND te.workorder_job_id IS NOT NULL';

        $params = [
            'start' => $start,
            'end' => $end,
            'default_rate' => $defaultLaborRate,
        ];

        if ($branchId !== null) {
            $sql .= ' AND w.branch_id = :branch_id';
            $params['branch_id'] = $branchId;
        }

        $sql .= ' GROUP BY te.technician_id, technician_name ORDER BY technician_name';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
