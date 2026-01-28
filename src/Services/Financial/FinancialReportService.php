<?php

namespace App\Services\Financial;

use App\Database\Connection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class FinancialReportService
{
    private const ACCOUNT_TYPES = ['asset', 'liability', 'income', 'expense', 'equity'];
    private const LEGACY_TYPE_MAP = [
        'purchase' => 'expense',
    ];

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $startDate, string $endDate, ?string $category = null, ?string $vendor = null): array
    {
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate) ?: null;
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate) ?: null;

        if ($start === null || $end === null) {
            throw new InvalidArgumentException('Invalid date range');
        }

        $summary = $this->summary($start, $end, $category, $vendor);

        return [
            'range' => [$start->format('Y-m-d'), $end->format('Y-m-d')],
            'summary' => $summary,
            'net' => $summary['income'] - $summary['expense'],
            'monthly' => $this->monthlyBreakdown($start, $end, $category, $vendor),
        ];
    }

    /**
     * @return array<string, float>
     */
    public function summary(DateTimeImmutable $start, DateTimeImmutable $end, ?string $category = null, ?string $vendor = null): array
    {
        $sql = 'SELECT COALESCE(fc.type, fe.type) AS category_type, SUM(fe.amount) AS total '
            . 'FROM financial_entries fe '
            . 'LEFT JOIN financial_categories fc ON fc.name = fe.category '
            . 'WHERE fe.entry_date BETWEEN :start AND :end';
        $params = [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];

        if ($category) {
            $sql .= ' AND fe.category = :category';
            $params['category'] = $category;
        }

        if ($vendor) {
            $sql .= ' AND fe.vendor = :vendor';
            $params['vendor'] = $vendor;
        }

        $sql .= ' GROUP BY category_type';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        $summary = array_fill_keys(self::ACCOUNT_TYPES, 0.0);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $normalized = $this->normalizeType((string) $row['category_type']);
            if ($normalized === null) {
                continue;
            }
            $summary[$normalized] += (float) $row['total'];
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monthlyBreakdown(DateTimeImmutable $start, DateTimeImmutable $end, ?string $category = null, ?string $vendor = null): array
    {
        $period = new DatePeriod($start->modify('first day of this month'), new DateInterval('P1M'), $end->modify('first day of next month'));
        $results = [];

        foreach ($period as $month) {
            $monthStart = $month->modify('first day of this month');
            $monthEnd = $month->modify('last day of this month');
            $summary = $this->summary($monthStart, $monthEnd, $category, $vendor);
            $results[] = [
                'month' => $monthStart->format('Y-m'),
                'summary' => $summary,
                'net' => $summary['income'] - $summary['expense'],
            ];
        }

        return $results;
    }

    public function export(string $startDate, string $endDate, string $format = 'csv', ?string $category = null, ?string $vendor = null): string
    {
        $report = $this->generate($startDate, $endDate, $category, $vendor);

        if ($format !== 'csv') {
            throw new InvalidArgumentException('Unsupported export format');
        }

        $rows = [];
        $rows[] = ['Month', 'Income', 'Expenses', 'Assets', 'Liabilities', 'Equity', 'Net'];
        foreach ($report['monthly'] as $row) {
            $rows[] = [
                $row['month'],
                number_format($row['summary']['income'], 2, '.', ''),
                number_format($row['summary']['expense'], 2, '.', ''),
                number_format($row['summary']['asset'], 2, '.', ''),
                number_format($row['summary']['liability'], 2, '.', ''),
                number_format($row['summary']['equity'], 2, '.', ''),
                number_format($row['net'], 2, '.', ''),
            ];
        }

        return $this->toCsv($rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryMetrics(string $startDate, string $endDate): array
    {
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate) ?: null;
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate) ?: null;

        if ($start === null || $end === null) {
            throw new InvalidArgumentException('Invalid date range');
        }

        $startRange = $start->setTime(0, 0, 0);
        $endRange = $end->setTime(23, 59, 59);

        $pdo = $this->connection->pdo();

        $taxStmt = $pdo->prepare('SELECT SUM(tax) FROM invoices WHERE issue_date BETWEEN :start AND :end');
        $taxStmt->execute([
            'start' => $startRange->format('Y-m-d'),
            'end' => $endRange->format('Y-m-d'),
        ]);
        $taxCollected = (float) ($taxStmt->fetchColumn() ?: 0);

        $billableStmt = $pdo->prepare(
            'SELECT SUM(duration_minutes) FROM time_entries WHERE started_at BETWEEN :start AND :end AND status != "rejected"'
        );
        $billableStmt->execute([
            'start' => $startRange->format('Y-m-d H:i:s'),
            'end' => $endRange->format('Y-m-d H:i:s'),
        ]);
        $billableMinutes = (float) ($billableStmt->fetchColumn() ?: 0);

        $paidStmt = $pdo->prepare(
            'SELECT SUM(duration_minutes) FROM time_entries WHERE started_at BETWEEN :start AND :end AND status = "approved"'
        );
        $paidStmt->execute([
            'start' => $startRange->format('Y-m-d H:i:s'),
            'end' => $endRange->format('Y-m-d H:i:s'),
        ]);
        $paidMinutes = (float) ($paidStmt->fetchColumn() ?: 0);

        // Calculate PTO hours from approved leave requests
        // Each day of leave is assumed to be 8 hours
        $ptoStmt = $pdo->prepare(
            'SELECT SUM(DATEDIFF(LEAST(lr.end_date, :end_date), GREATEST(lr.start_date, :start_date)) + 1) * 8 '
            . 'FROM leave_requests lr '
            . 'WHERE lr.status = :status '
            . 'AND lr.type = :leave_type '
            . 'AND lr.start_date <= :end '
            . 'AND lr.end_date >= :start'
        );
        $ptoStmt->execute([
            'status' => 'approved',
            'leave_type' => 'pto',
            'start' => $startRange->format('Y-m-d'),
            'end' => $endRange->format('Y-m-d'),
            'start_date' => $startRange->format('Y-m-d'),
            'end_date' => $endRange->format('Y-m-d'),
        ]);
        $ptoHours = (float) ($ptoStmt->fetchColumn() ?: 0);

        $warrantyStmt = $pdo->prepare(
            'SELECT status, COUNT(*) AS total FROM warranty_claims WHERE created_at BETWEEN :start AND :end GROUP BY status'
        );
        $warrantyStmt->execute([
            'start' => $startRange->format('Y-m-d H:i:s'),
            'end' => $endRange->format('Y-m-d H:i:s'),
        ]);
        $warrantyCounts = [];
        $warrantyTotal = 0;
        foreach ($warrantyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $count = (int) ($row['total'] ?? 0);
            $warrantyCounts[$row['status']] = $count;
            $warrantyTotal += $count;
        }

        $inventoryRow = $pdo->query(
            'SELECT '
            . 'SUM(stock_quantity) AS on_hand, '
            . 'SUM(cost * stock_quantity) AS total_cost, '
            . 'SUM(sale_price * stock_quantity) AS total_value '
            . 'FROM inventory_items'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $inventory = [
            'on_hand' => (int) ($inventoryRow['on_hand'] ?? 0),
            'total_cost' => (float) ($inventoryRow['total_cost'] ?? 0),
            'total_value' => (float) ($inventoryRow['total_value'] ?? 0),
        ];

        $serviceStmt = $pdo->prepare(
            'SELECT st.id, COALESCE(st.name, "Unassigned") AS name, COUNT(i.id) AS invoice_count, '
            . 'COALESCE(SUM(i.total), 0) AS revenue '
            . 'FROM invoices i '
            . 'LEFT JOIN service_types st ON st.id = i.service_type_id '
            . 'WHERE i.issue_date BETWEEN :start AND :end '
            . 'GROUP BY st.id, st.name '
            . 'ORDER BY revenue DESC '
            . 'LIMIT :limit'
        );
        $serviceStmt->bindValue(':start', $startRange->format('Y-m-d'));
        $serviceStmt->bindValue(':end', $endRange->format('Y-m-d'));
        $serviceStmt->bindValue(':limit', 10, PDO::PARAM_INT);
        $serviceStmt->execute();
        $serviceTypeStats = array_map(static function (array $row) {
            return [
                'id' => $row['id'] !== null ? (int) $row['id'] : null,
                'name' => $row['name'],
                'count' => (int) ($row['invoice_count'] ?? 0),
                'revenue' => (float) ($row['revenue'] ?? 0),
            ];
        }, $serviceStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $yearStart = $end->setDate((int) $end->format('Y'), 1, 1)->setTime(0, 0, 0);
        $ytdMonthly = $this->monthlyBreakdown($yearStart, $endRange, null, null);
        $ytdTrends = array_map(static function (array $row) {
            return [
                'month' => $row['month'],
                'asset' => (float) ($row['summary']['asset'] ?? 0),
                'liability' => (float) ($row['summary']['liability'] ?? 0),
                'income' => (float) ($row['summary']['income'] ?? 0),
                'expense' => (float) ($row['summary']['expense'] ?? 0),
                'equity' => (float) ($row['summary']['equity'] ?? 0),
                'net' => (float) ($row['net'] ?? 0),
            ];
        }, $ytdMonthly);

        return [
            'range' => [$startRange->format('Y-m-d'), $endRange->format('Y-m-d')],
            'ytd_trends' => $ytdTrends,
            'tax_collected' => $taxCollected,
            'billable_hours' => round($billableMinutes / 60, 2),
            'paid_hours' => round(($paidMinutes / 60) + $ptoHours, 2),
            'worked_hours' => round($paidMinutes / 60, 2),
            'pto_hours' => round($ptoHours, 2),
            'warranty_claims' => [
                'total' => $warrantyTotal,
                'by_status' => $warrantyCounts,
            ],
            'inventory' => $inventory,
            'service_type_stats' => $serviceTypeStats,
        ];
    }

    private function normalizeType(string $type): ?string
    {
        $normalized = strtolower(trim($type));
        if (isset(self::LEGACY_TYPE_MAP[$normalized])) {
            $normalized = self::LEGACY_TYPE_MAP[$normalized];
        }

        return in_array($normalized, self::ACCOUNT_TYPES, true) ? $normalized : null;
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function toCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $csv;
    }
}
