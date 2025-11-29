<?php

namespace App\Services\Dashboard;

use App\DTO\Dashboard\ChartSeries;
use App\DTO\Dashboard\KpiResponse;
use App\Database\Connection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

class DashboardService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function kpis(DateTimeInterface $start, DateTimeInterface $end, array $options = []): KpiResponse
    {
        $pdo = $this->connection->pdo();
        $response = new KpiResponse();
        $bindings = [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];

        $customerFilter = '';
        if (isset($options['customer_id'])) {
            $customerFilter = ' AND customer_id = :customer_id';
            $bindings['customer_id'] = $options['customer_id'];
        }

        $estimateStmt = $pdo->prepare(
            'SELECT status, COUNT(*) AS total FROM estimates WHERE created_at BETWEEN :start AND :end' . $customerFilter . ' GROUP BY status'
        );
        $estimateStmt->execute($bindings);
        foreach ($estimateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $response->estimateStatusCounts[$row['status']] = (int) $row['total'];
        }

        $invoiceStmt = $pdo->prepare(
            'SELECT SUM(total) AS total, AVG(total) AS average, SUM(amount_paid) AS paid, SUM(balance_due) AS outstanding '
            . 'FROM invoices WHERE issue_date BETWEEN :start AND :end' . $customerFilter
        );
        $invoiceStmt->execute($bindings);
        $invoiceRow = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $response->invoiceTotals = [
            'total' => (float) ($invoiceRow['total'] ?? 0),
            'average' => (float) ($invoiceRow['average'] ?? 0),
            'paid' => (float) ($invoiceRow['paid'] ?? 0),
            'outstanding' => (float) ($invoiceRow['outstanding'] ?? 0),
        ];

        $estimateTaxStmt = $pdo->prepare(
            'SELECT SUM(tax) AS total_tax FROM estimates WHERE created_at BETWEEN :start AND :end' . $customerFilter
        );
        $estimateTaxStmt->execute($bindings);
        $estimateTax = (float) ($estimateTaxStmt->fetchColumn() ?: 0);

        $invoiceTaxStmt = $pdo->prepare(
            'SELECT SUM(tax) AS total_tax FROM invoices WHERE issue_date BETWEEN :start AND :end' . $customerFilter
        );
        $invoiceTaxStmt->execute($bindings);
        $invoiceTax = (float) ($invoiceTaxStmt->fetchColumn() ?: 0);

        $response->taxTotals = [
            'estimates' => $estimateTax,
            'invoices' => $invoiceTax,
        ];

        $warrantyStmt = $pdo->prepare(
            'SELECT status, COUNT(*) AS total FROM warranty_claims WHERE created_at BETWEEN :start AND :end' . $customerFilter . ' GROUP BY status'
        );
        $warrantyStmt->execute($bindings);
        foreach ($warrantyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $response->warrantyCounts[$row['status']] = (int) $row['total'];
        }

        $appointmentStmt = $pdo->prepare(
            'SELECT status, COUNT(*) AS total FROM appointments WHERE start_time BETWEEN :start AND :end' . $customerFilter . ' GROUP BY status'
        );
        $appointmentStmt->execute($bindings);
        foreach ($appointmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $response->appointmentCounts[$row['status']] = (int) $row['total'];
        }

        $inventoryStmt = $pdo->query(
            'SELECT '
            . 'SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock, '
            . 'SUM(CASE WHEN stock_quantity <= low_stock_threshold THEN 1 ELSE 0 END) AS low_stock '
            . 'FROM inventory_items'
        );
        $inventoryRow = $inventoryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $response->inventoryAlerts = [
            'out_of_stock' => (int) ($inventoryRow['out_of_stock'] ?? 0),
            'low_stock' => (int) ($inventoryRow['low_stock'] ?? 0),
        ];

        return $response;
    }

    /**
     * Generate monthly totals for invoices and estimates within a date range.
     *
     * @return array<int, ChartSeries>
     */
    public function monthlyTrends(DateTimeInterface $start, DateTimeInterface $end, array $options = []): array
    {
        $period = new DatePeriod(
            new DateTimeImmutable($start->format('Y-m-01')),
            new DateInterval('P1M'),
            (new DateTimeImmutable($end->format('Y-m-01')))->add(new DateInterval('P1M'))
        );

        $categories = [];
        foreach ($period as $month) {
            $categories[] = $month->format('Y-m');
        }

        $invoices = $this->aggregateMonthly('invoices', 'total', 'issue_date', $start, $end, $options, $categories);
        $estimates = $this->aggregateMonthly('estimates', 'grand_total', 'created_at', $start, $end, $options, $categories);

        return [
            new ChartSeries('Invoices', $invoices, $categories),
            new ChartSeries('Estimates', $estimates, $categories),
        ];
    }

    /**
     * @param array<int, string> $categories
     * @return array<int, float>
     */
    private function aggregateMonthly(
        string $table,
        string $amountColumn,
        string $dateColumn,
        DateTimeInterface $start,
        DateTimeInterface $end,
        array $options,
        array $categories
    ): array {
        $pdo = $this->connection->pdo();
        $bindings = [
            'start' => $start->format('Y-m-01'),
            'end' => $end->format('Y-m-t'),
        ];

        $customerFilter = '';
        if (isset($options['customer_id'])) {
            $customerFilter = ' AND customer_id = :customer_id';
            $bindings['customer_id'] = $options['customer_id'];
        }

        $sql = sprintf(
            'SELECT DATE_FORMAT(%s, "%%Y-%%m") AS bucket, SUM(%s) AS total FROM %s '
            . 'WHERE %s BETWEEN :start AND :end%s GROUP BY bucket',
            $dateColumn,
            $amountColumn,
            $table,
            $dateColumn,
            $customerFilter
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $series = [];
        foreach ($categories as $month) {
            $series[] = isset($rows[$month]) ? (float) $rows[$month] : 0.0;
        }

        return $series;
    }
}
