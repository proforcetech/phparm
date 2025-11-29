<?php

namespace App\Services\Dashboard;

use App\DTO\Dashboard\ChartSeries;
use App\DTO\Dashboard\KpiResponse;
use App\Database\Connection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;

class DashboardService
{
    private Connection $connection;
    private DateRangePresetResolver $presetResolver;

    /**
     * @var array<string, array{expires_at: int, value: mixed}>
     */
    private array $cache = [];

    public function __construct(Connection $connection, ?DateRangePresetResolver $presetResolver = null)
    {
        $this->connection = $connection;
        $this->presetResolver = $presetResolver ?? new DateRangePresetResolver();
    }

    public function kpis(DateTimeInterface $start, DateTimeInterface $end, array $options = []): KpiResponse
    {
        $timezone = $options['timezone'] ?? 'UTC';
        $cacheTtl = (int) ($options['cache_ttl'] ?? 300);
        $cacheKey = $this->makeCacheKey('kpis', $start, $end, $options);

        return $this->remember($cacheKey, $cacheTtl, function () use ($start, $end, $options, $timezone) {
            [$startUtc, $endUtc] = $this->normalizeRange($start, $end, $timezone);

            $pdo = $this->connection->pdo();
            $response = new KpiResponse();
            $bindings = [
                'start' => $startUtc->format('Y-m-d H:i:s'),
                'end' => $endUtc->format('Y-m-d H:i:s'),
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
        });
    }

    /**
     * Generate monthly totals for invoices and estimates within a date range.
     *
     * @return array<int, ChartSeries>
     */
    public function monthlyTrends(DateTimeInterface $start, DateTimeInterface $end, array $options = []): array
    {
        $timezone = $options['timezone'] ?? 'UTC';
        $cacheTtl = (int) ($options['cache_ttl'] ?? 300);
        $cacheKey = $this->makeCacheKey('monthly_trends', $start, $end, $options);

        return $this->remember($cacheKey, $cacheTtl, function () use ($start, $end, $options, $timezone) {
            $period = new DatePeriod(
                new DateTimeImmutable($start->format('Y-m-01'), new DateTimeZone($timezone)),
                new DateInterval('P1M'),
                (new DateTimeImmutable($end->format('Y-m-01'), new DateTimeZone($timezone)))->add(new DateInterval('P1M'))
            );

            $categories = [];
            foreach ($period as $month) {
                $categories[] = $month->format('Y-m');
            }

            $invoices = $this->aggregateMonthly('invoices', 'total', 'issue_date', $start, $end, $options, $categories, $timezone);
            $estimates = $this->aggregateMonthly('estimates', 'grand_total', 'created_at', $start, $end, $options, $categories, $timezone);

            return [
                new ChartSeries('Invoices', $invoices, $categories),
                new ChartSeries('Estimates', $estimates, $categories),
            ];
        });
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
        array $categories,
        string $timezone
    ): array {
        $pdo = $this->connection->pdo();
        [$startUtc, $endUtc] = $this->normalizeRange($start, $end, $timezone, true);
        $bindings = [
            'start' => $startUtc->format('Y-m-01'),
            'end' => $endUtc->format('Y-m-t'),
        ];

        $customerFilter = '';
        if (isset($options['customer_id'])) {
            $customerFilter = ' AND customer_id = :customer_id';
            $bindings['customer_id'] = $options['customer_id'];
        }

        $sql = sprintf(
            'SELECT DATE_FORMAT(CONVERT_TZ(%s, "UTC", :tz), "%%Y-%%m") AS bucket, SUM(%s) AS total FROM %s '
            . 'WHERE %s BETWEEN :start AND :end%s GROUP BY bucket',
            $dateColumn,
            $amountColumn,
            $table,
            $dateColumn,
            $customerFilter
        );

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':tz', $timezone);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $series = [];
        foreach ($categories as $month) {
            $series[] = isset($rows[$month]) ? (float) $rows[$month] : 0.0;
        }

        return $series;
    }

    public function resolvePreset(string $preset, string $timezone = 'UTC', ?DateTimeInterface $now = null): array
    {
        return $this->presetResolver->resolve($preset, $timezone, $now);
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function normalizeRange(DateTimeInterface $start, DateTimeInterface $end, string $timezone, bool $monthBoundary = false): array
    {
        $tz = new DateTimeZone($timezone);
        $startTz = DateTimeImmutable::createFromInterface($start)->setTimezone($tz);
        $endTz = DateTimeImmutable::createFromInterface($end)->setTimezone($tz);

        if ($monthBoundary) {
            $startTz = new DateTimeImmutable($startTz->format('Y-m-01'), $tz);
            $endTz = new DateTimeImmutable($endTz->format('Y-m-t 23:59:59'), $tz);
        }

        return [
            $startTz->setTimezone(new DateTimeZone('UTC')),
            $endTz->setTimezone(new DateTimeZone('UTC')),
        ];
    }

    private function makeCacheKey(string $prefix, DateTimeInterface $start, DateTimeInterface $end, array $options): string
    {
        unset($options['cache_ttl']);

        return $prefix . ':' . md5(json_encode([
            'start' => $start->format(DateTimeInterface::ATOM),
            'end' => $end->format(DateTimeInterface::ATOM),
            'options' => $options,
        ]));
    }

    private function remember(string $key, int $ttl, callable $callback)
    {
        $now = time();
        if (isset($this->cache[$key]) && $this->cache[$key]['expires_at'] >= $now) {
            return $this->cache[$key]['value'];
        }

        $value = $callback();
        $this->cache[$key] = [
            'expires_at' => $now + $ttl,
            'value' => $value,
        ];

        return $value;
    }
}
