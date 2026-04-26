<?php

namespace App\Services\Dashboard;

use App\Database\Connection;
use PDO;

/**
 * Branch/region (division) dashboard bootstrap — Phase 0.6 of
 * docs/expansion-plan.md. Rolls up core KPIs per division_id so each service
 * line can see its own book of business independently of the global shop
 * dashboard.
 *
 * Intentionally narrow for the bootstrap: open workorders, open invoices,
 * and paid-revenue last 30d. Richer metrics (utilization, NPS, SLA) land in
 * later phases as the per-division tables fill in.
 */
class BranchDashboardService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Overview across every active division. Returns one row per division
     * plus an 'unassigned' bucket for rows where division_id IS NULL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overview(): array
    {
        $divisions = $this->listDivisions();

        $out = [];
        foreach ($divisions as $division) {
            $out[] = $this->kpisFor($division);
        }

        // Unassigned bucket — until the backfill completes on every module,
        // some rows may still have NULL division_id.
        $out[] = $this->kpisFor([
            'id' => null,
            'code' => '_unassigned',
            'name' => 'Unassigned',
        ]);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function forDivision(int $divisionId): array
    {
        $division = $this->findDivision($divisionId);
        if ($division === null) {
            throw new \InvalidArgumentException("Division {$divisionId} not found");
        }

        return $this->kpisFor($division);
    }

    /**
     * @param array{id: ?int, code: string, name: string} $division
     * @return array<string, mixed>
     */
    private function kpisFor(array $division): array
    {
        $divisionId = $division['id'];

        return [
            'division' => [
                'id' => $divisionId,
                'code' => $division['code'],
                'name' => $division['name'],
            ],
            'open_workorders' => $this->openWorkorders($divisionId),
            'completed_workorders_7d' => $this->completedWorkorders7d($divisionId),
            'open_invoices' => $this->openInvoices($divisionId),
            'revenue_30d' => $this->revenue30d($divisionId),
        ];
    }

    private function openWorkorders(?int $divisionId): int
    {
        [$where, $params] = $this->divisionFilter($divisionId);
        $sql = "SELECT COUNT(*) FROM workorders
                WHERE status NOT IN ('completed','cancelled','closed')
                  {$where}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function completedWorkorders7d(?int $divisionId): int
    {
        [$where, $params] = $this->divisionFilter($divisionId);
        $sql = "SELECT COUNT(*) FROM workorders
                WHERE status = 'completed'
                  AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  {$where}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{count: int, balance: float}
     */
    private function openInvoices(?int $divisionId): array
    {
        [$where, $params] = $this->divisionFilter($divisionId);
        $sql = "SELECT COUNT(*) AS c, COALESCE(SUM(balance_due),0) AS bal
                FROM invoices
                WHERE status IN ('pending','sent','partial','overdue','unpaid')
                  {$where}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'bal' => 0];

        return [
            'count' => (int) $row['c'],
            'balance' => (float) $row['bal'],
        ];
    }

    private function revenue30d(?int $divisionId): float
    {
        [$where, $params] = $this->divisionFilter($divisionId);
        $sql = "SELECT COALESCE(SUM(amount_paid),0) AS revenue
                FROM invoices
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  {$where}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function divisionFilter(?int $divisionId): array
    {
        if ($divisionId === null) {
            return [' AND division_id IS NULL', []];
        }
        return [' AND division_id = :division_id', ['division_id' => $divisionId]];
    }

    /**
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private function listDivisions(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT id, code, name FROM divisions WHERE is_active = 1 ORDER BY sort_order, id'
        );
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        return array_map(
            static fn(array $r): array => [
                'id' => (int) $r['id'],
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
            ],
            $rows
        );
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private function findDivision(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, code, name FROM divisions WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        ];
    }
}
