<?php

namespace App\Services\Dashboard;

use App\DTO\Dashboard\ChartSeries;
use App\DTO\Dashboard\KpiResponse;
use App\Database\Connection;
use App\Support\SettingsRepository;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PDO;

class DashboardService
{
    private Connection $connection;
    private DateRangePresetResolver $presetResolver;
    private ?SettingsRepository $settings;

    /**
     * @var array<string, array{expires_at: int, value: mixed}>
     */
    private array $cache = [];

    public function __construct(
        Connection $connection,
        ?DateRangePresetResolver $presetResolver = null,
        ?SettingsRepository $settings = null
    ) {
        $this->connection = $connection;
        $this->presetResolver = $presetResolver ?? new DateRangePresetResolver();
        $this->settings = $settings;
    }

    public function kpis(DateTimeInterface $start, DateTimeInterface $end, array $options = []): KpiResponse
    {
        $timezone = $options['timezone'] ?? 'UTC';
        $cacheTtl = (int) ($options['cache_ttl'] ?? 300);
        $role = $this->normalizeRole($options['role'] ?? 'admin');
        $this->enforceRoleScope($role, $options);
        $cacheKey = $this->makeCacheKey('kpis', $start, $end, $options);

        $response = $this->remember($cacheKey, $cacheTtl, function () use ($start, $end, $options, $timezone) {
            [$startUtc, $endUtc] = $this->normalizeRange($start, $end, $timezone);

            $pdo = $this->connection->pdo();
            $response = new KpiResponse();
            $baseBindings = [
                'start' => $startUtc->format('Y-m-d H:i:s'),
                'end' => $endUtc->format('Y-m-d H:i:s'),
            ];

            $customerFilter = '';
            if (isset($options['customer_id'])) {
                $customerFilter = ' AND customer_id = :customer_id';
                $baseBindings['customer_id'] = $options['customer_id'];
            }

            $technicianId = isset($options['technician_id']) ? (int) $options['technician_id'] : null;
            $technicianFilter = $technicianId !== null ? ' AND technician_id = :technician_id' : '';
            $invoiceJoin = $technicianId !== null ? ' LEFT JOIN estimates e ON e.id = i.estimate_id' : '';

            $estimateStmt = $pdo->prepare(
                'SELECT status, COUNT(*) AS total FROM estimates WHERE created_at BETWEEN :start AND :end'
                . $customerFilter . $technicianFilter . ' GROUP BY status'
            );
            $estimateStmt->execute($this->withTechnician($baseBindings, $technicianId));
            foreach ($estimateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $response->estimateStatusCounts[$row['status']] = (int) $row['total'];
            }

            $invoiceStmt = $pdo->prepare(
                'SELECT SUM(i.total) AS total, AVG(i.total) AS average, SUM(i.amount_paid) AS paid, SUM(i.balance_due) AS outstanding '
                . 'FROM invoices i' . $invoiceJoin . ' WHERE i.issue_date BETWEEN :start AND :end'
                . str_replace('customer_id', 'i.customer_id', $customerFilter)
                . ($invoiceJoin !== '' ? ' AND e.technician_id = :technician_id' : '')
            );
            $invoiceBindings = $invoiceJoin !== '' ? $this->withTechnician($baseBindings, $technicianId) : $baseBindings;
            $invoiceStmt->execute($invoiceBindings);
            $invoiceRow = $invoiceStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $response->invoiceTotals = [
                'total' => (float) ($invoiceRow['total'] ?? 0),
                'average' => (float) ($invoiceRow['average'] ?? 0),
                'paid' => (float) ($invoiceRow['paid'] ?? 0),
                'outstanding' => (float) ($invoiceRow['outstanding'] ?? 0),
            ];

            $estimateTaxStmt = $pdo->prepare(
                'SELECT SUM(tax) AS total_tax FROM estimates WHERE created_at BETWEEN :start AND :end'
                . $customerFilter . $technicianFilter
            );
            $estimateTaxStmt->execute($this->withTechnician($baseBindings, $technicianId));
            $estimateTax = (float) ($estimateTaxStmt->fetchColumn() ?: 0);

            $invoiceTaxStmt = $pdo->prepare(
                'SELECT SUM(i.tax) AS total_tax FROM invoices i' . $invoiceJoin . ' WHERE i.issue_date BETWEEN :start AND :end'
                . str_replace('customer_id', 'i.customer_id', $customerFilter)
                . ($invoiceJoin !== '' ? ' AND e.technician_id = :technician_id' : '')
            );
            $invoiceTaxStmt->execute($invoiceBindings);
            $invoiceTax = (float) ($invoiceTaxStmt->fetchColumn() ?: 0);

            $response->taxTotals = [
                'estimates' => $estimateTax,
                'invoices' => $invoiceTax,
            ];

            $warrantyStmt = $pdo->prepare(
                'SELECT status, COUNT(*) AS total FROM warranty_claims WHERE created_at BETWEEN :start AND :end' . $customerFilter . ' GROUP BY status'
            );
            $warrantyStmt->execute($baseBindings);
            foreach ($warrantyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $response->warrantyCounts[$row['status']] = (int) $row['total'];
            }

            $appointmentStmt = $pdo->prepare(
                'SELECT status, COUNT(*) AS total FROM appointments WHERE start_time BETWEEN :start AND :end'
                . $customerFilter . $technicianFilter . ' GROUP BY status'
            );
            $appointmentStmt->execute($this->withTechnician($baseBindings, $technicianId));
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

            $todayStart = new DateTimeImmutable('today', new DateTimeZone($timezone));
            $todayEnd = $todayStart->setTime(23, 59, 59);
            $todayBindings = [
                'start' => $todayStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'end' => $todayEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ];

            $pendingInvoiceStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM invoices i' . $invoiceJoin . ' WHERE i.status IN ("pending", "sent", "partial")'
                . ($invoiceJoin !== '' ? ' AND e.technician_id = :technician_id' : '')
            );
            $pendingBindings = $invoiceJoin !== '' ? $this->withTechnician([], $technicianId) : [];
            $pendingInvoiceStmt->execute($pendingBindings);
            $pendingInvoices = (int) ($pendingInvoiceStmt->fetchColumn() ?: 0);

            $appointmentsTodayStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM appointments WHERE start_time BETWEEN :start AND :end'
                . ($technicianId !== null ? ' AND technician_id = :technician_id' : '')
            );
            $appointmentsTodayStmt->execute($this->withTechnician($todayBindings, $technicianId));
            $appointmentsToday = (int) ($appointmentsTodayStmt->fetchColumn() ?: 0);

            $response->summary = [
                'revenue' => $response->invoiceTotals['total'] ?? 0.0,
                'pending_invoices' => $pendingInvoices,
                'appointments_today' => $appointmentsToday,
            ];

            return $response;
        });

        return $this->applyTileVisibility($response, $role);
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
        $role = $this->normalizeRole($options['role'] ?? 'admin');
        $this->enforceRoleScope($role, $options);
        $tileSettings = $this->loadTileSettings();
        if (!$this->isTileEnabled('charts', $role, $tileSettings)) {
            return [];
        }

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

            $invoices = $this->aggregateMonthly(
                'invoices i',
                'i.total',
                'i.issue_date',
                $start,
                $end,
                $options,
                $categories,
                $timezone,
                'e.technician_id',
                'LEFT JOIN estimates e ON e.id = i.estimate_id'
            );
            $estimates = $this->aggregateMonthly(
                'estimates',
                'grand_total',
                'created_at',
                $start,
                $end,
                $options,
                $categories,
                $timezone,
                'technician_id'
            );

            return [
                new ChartSeries('Invoices', $invoices, $categories),
                new ChartSeries('Estimates', $estimates, $categories),
            ];
        });
    }

    /**
     * @return ChartSeries
     */
    public function serviceTypeBreakdown(DateTimeInterface $start, DateTimeInterface $end, array $options = []): ChartSeries
    {
        $timezone = $options['timezone'] ?? 'UTC';
        $cacheTtl = (int) ($options['cache_ttl'] ?? 300);
        $role = $this->normalizeRole($options['role'] ?? 'admin');
        $this->enforceRoleScope($role, $options);
        $tileSettings = $this->loadTileSettings();
        if (!$this->isTileEnabled('charts', $role, $tileSettings)) {
            return new ChartSeries('Service Types', [], []);
        }

        $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 10;
        $cacheKey = $this->makeCacheKey('service_type_breakdown', $start, $end, $options);

        return $this->remember($cacheKey, $cacheTtl, function () use ($start, $end, $options, $timezone, $limit) {
            $pdo = $this->connection->pdo();
            [$startUtc, $endUtc] = $this->normalizeRange($start, $end, $timezone);
            $bindings = [
                'start' => $startUtc->format('Y-m-d H:i:s'),
                'end' => $endUtc->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ];

            $customerFilter = '';
            if (isset($options['customer_id'])) {
                $customerFilter = ' AND e.customer_id = :customer_id';
                $bindings['customer_id'] = $options['customer_id'];
            }

            if (isset($options['technician_id'])) {
                $bindings['technician_id'] = (int) $options['technician_id'];
                $customerFilter .= ' AND e.technician_id = :technician_id';
            }

            $sql = 'SELECT st.name AS label, COALESCE(SUM(ej.total), 0) AS total '
                . 'FROM estimate_jobs ej '
                . 'JOIN estimates e ON e.id = ej.estimate_id '
                . 'JOIN service_types st ON st.id = ej.service_type_id '
                . 'WHERE e.created_at BETWEEN :start AND :end' . $customerFilter . ' '
                . 'GROUP BY st.name '
                . 'ORDER BY total DESC '
                . 'LIMIT :limit';

            $stmt = $pdo->prepare($sql);
            foreach ($bindings as $key => $value) {
                $paramType = $key === 'limit' ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue(':' . $key, $value, $paramType);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $categories = [];
            $data = [];
            foreach ($rows as $row) {
                $categories[] = (string) $row['label'];
                $data[] = (float) $row['total'];
            }

            return new ChartSeries('Service Type Totals', $data, $categories);
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
        string $timezone,
        string $technicianColumn = '',
        string $joinClause = ''
    ): array {
        $pdo = $this->connection->pdo();
        [$startUtc, $endUtc] = $this->normalizeRange($start, $end, $timezone, true);
        $bindings = [
            'start' => $startUtc->format('Y-m-01'),
            'end' => $endUtc->format('Y-m-t'),
        ];

        $tableParts = preg_split('/\s+/', trim($table)) ?: [];
        $tableAlias = count($tableParts) > 1 ? $tableParts[count($tableParts) - 1] : null;
        $customerColumn = ($tableAlias !== null ? $tableAlias . '.' : '') . 'customer_id';

        $customerFilter = '';
        if (isset($options['customer_id'])) {
            $customerFilter = ' AND ' . $customerColumn . ' = :customer_id';
            $bindings['customer_id'] = $options['customer_id'];
        }

        $technicianFilter = '';
        if ($technicianColumn !== '' && isset($options['technician_id'])) {
            $technicianFilter = ' AND ' . $technicianColumn . ' = :technician_id';
            $bindings['technician_id'] = (int) $options['technician_id'];
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: '';
        $dateBucket = $driver === 'sqlite'
            ? sprintf("strftime('%%Y-%%m', %s)", $dateColumn)
            : sprintf('DATE_FORMAT(CONVERT_TZ(%s, "UTC", :tz), "%%Y-%%m")', $dateColumn);

        $sql = sprintf(
            'SELECT %s AS bucket, SUM(%s) AS total FROM %s %s'
            . 'WHERE %s BETWEEN :start AND :end%s%s GROUP BY bucket',
            $dateBucket,
            $amountColumn,
            $table,
            $joinClause !== '' ? $joinClause . ' ' : '',
            $dateColumn,
            $customerFilter,
            $technicianFilter
        );

        $stmt = $pdo->prepare($sql);
        if ($driver !== 'sqlite') {
            $stmt->bindValue(':tz', $timezone);
        }
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

    /**
     * @param array<string, mixed> $bindings
     * @return array<string, mixed>
     */
    private function withTechnician(array $bindings, ?int $technicianId): array
    {
        if ($technicianId !== null) {
            $bindings['technician_id'] = $technicianId;
        }

        return $bindings;
    }

    public function resolvePreset(string $preset, string $timezone = 'UTC', ?DateTimeInterface $now = null): array
    {
        return $this->presetResolver->resolve($preset, $timezone, $now);
    }

    public function invalidateForEvent(string $event, array $context = []): void
    {
        $event = strtolower($event);
        $cachePrefixes = match (true) {
            str_starts_with($event, 'estimate.'),
            str_starts_with($event, 'invoice.') => ['kpis', 'monthly_trends'],
            str_starts_with($event, 'payment.') => ['kpis', 'monthly_trends'],
            str_starts_with($event, 'inventory.') => ['kpis'],
            str_starts_with($event, 'appointment.') => ['kpis'],
            default => ['kpis', 'monthly_trends'],
        };

        if (isset($context['customer_id'])) {
            $this->clearCacheForCustomer((int) $context['customer_id']);
        }

        foreach ($cachePrefixes as $prefix) {
            $this->clearCache($prefix);
        }
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

    private function normalizeRole(string $role): string
    {
        return strtolower(trim($role));
    }

    private function enforceRoleScope(string $role, array $options): void
    {
        if ($role === 'customer' && !isset($options['customer_id'])) {
            throw new InvalidArgumentException('Customer scoped dashboard requests require customer_id.');
        }

        if ($role === 'technician' && !isset($options['technician_id'])) {
            throw new InvalidArgumentException('Technician scoped dashboard requests require technician_id.');
        }
    }

    private function applyTileVisibility(KpiResponse $response, string $role): KpiResponse
    {
        $tileSettings = $this->loadTileSettings();

        if (!$this->isTileEnabled('estimates', $role, $tileSettings)) {
            $response->estimateStatusCounts = [];
        }

        if (!$this->isTileEnabled('invoices', $role, $tileSettings)) {
            $response->invoiceTotals = [];
        }

        if (!$this->isTileEnabled('tax', $role, $tileSettings)) {
            $response->taxTotals = [];
        }

        if (!$this->isTileEnabled('warranty', $role, $tileSettings)) {
            $response->warrantyCounts = [];
        }

        if (!$this->isTileEnabled('appointments', $role, $tileSettings)) {
            $response->appointmentCounts = [];
        }

        if (!$this->isTileEnabled('inventory', $role, $tileSettings)) {
            $response->inventoryAlerts = [];
        }

        return $response;
    }

    private function loadTileSettings(): array
    {
        $defaults = [
            'estimates' => true,
            'invoices' => true,
            'tax' => true,
            'warranty' => true,
            'appointments' => true,
            'inventory' => true,
            'charts' => true,
        ];

        if ($this->settings === null) {
            return $defaults;
        }

        $configured = $this->settings->get('dashboard.tiles', []);
        if (!is_array($configured)) {
            return $defaults;
        }

        foreach ($configured as $tile => $enabled) {
            if (isset($defaults[$tile])) {
                $defaults[$tile] = (bool) $enabled;
            }
        }

        return $defaults;
    }

    private function isTileEnabled(string $tile, string $role, array $tileSettings): bool
    {
        $roleAllowedTiles = $this->tilesForRole($role);

        return in_array($tile, $roleAllowedTiles, true) && ($tileSettings[$tile] ?? true);
    }

    /**
     * @return array<int, string>
     */
    private function tilesForRole(string $role): array
    {
        return match ($role) {
            'customer' => ['estimates', 'invoices', 'appointments', 'warranty', 'charts'],
            'technician' => ['appointments', 'estimates', 'invoices', 'warranty', 'charts'],
            default => ['estimates', 'invoices', 'tax', 'warranty', 'appointments', 'inventory', 'charts'],
        };
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

    private function clearCache(?string $prefix = null): void
    {
        foreach (array_keys($this->cache) as $key) {
            if ($prefix === null || str_starts_with($key, $prefix . ':')) {
                unset($this->cache[$key]);
            }
        }
    }

    private function clearCacheForCustomer(int $customerId): void
    {
        foreach (array_keys($this->cache) as $key) {
            if (str_contains($key, '"customer_id":' . $customerId)) {
                unset($this->cache[$key]);
            }
        }
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
