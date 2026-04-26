<?php

namespace App\Services\Reporting;

use App\Database\Connection;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Registry of report types that the unified reporting system can run.
 *
 * Each report has:
 *   - key         (stable identifier, used in saved_reports.report_key)
 *   - module      (which domain it belongs to, for the catalog UI)
 *   - name        (human label)
 *   - description
 *   - parameters  (parameter spec — name, type, label, required)
 *   - columns     (column descriptors for the result table)
 *   - drill_down  (optional drill_target descriptor — what entity each row links to)
 *   - runner      (closure that takes (Connection, params): {rows, total, columns?})
 *
 * The catalog is purposefully implemented as plain SQL inside this file
 * rather than reaching into existing report services. The reason: the
 * existing report services were written ad-hoc with bespoke signatures
 * (some take string dates, some DateTimeImmutable, some need User objects
 * for permission scoping, some take SettingsRepository). Wrapping them
 * to a uniform shape would require either retrofitting their signatures
 * (high churn risk to existing callers) or leaving a fragile adapter
 * layer. Instead, this catalog is an additive read-only layer with its
 * own carefully scoped queries — the existing report endpoints continue
 * to serve their own UIs unchanged. New cross-cutting features (saved
 * configs, schedules, drill-down, exports) ride on top of this catalog.
 *
 * Adding a new report = registering a new entry here. No migration,
 * no new endpoint.
 */
class ReportCatalogService
{
    /**
     * @var array<string, array{
     *   key: string,
     *   module: string,
     *   name: string,
     *   description: string,
     *   parameters: array<int, array{name: string, type: string, label: string, required?: bool, default?: mixed}>,
     *   columns: array<int, array{key: string, label: string, type?: string}>,
     *   drill_down?: array{target: string, key: string},
     *   runner: callable
     * }>
     */
    private array $catalog;

    public function __construct(private Connection $connection)
    {
        $this->catalog = $this->buildCatalog();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listReports(): array
    {
        $out = [];
        foreach ($this->catalog as $entry) {
            $out[] = $this->describe($entry);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function describeReport(string $key): ?array
    {
        if (!isset($this->catalog[$key])) {
            return null;
        }
        return $this->describe($this->catalog[$key]);
    }

    public function hasReport(string $key): bool
    {
        return isset($this->catalog[$key]);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{rows: array<int, array<string, mixed>>, columns: array<int, array<string, mixed>>, total: int, drill_down: ?array<string, mixed>}
     */
    public function run(string $key, array $parameters): array
    {
        if (!isset($this->catalog[$key])) {
            throw new InvalidArgumentException("Unknown report: {$key}");
        }
        $entry = $this->catalog[$key];
        $normalized = $this->normalizeParameters($entry, $parameters);

        try {
            $result = ($entry['runner'])($this->connection, $normalized);
        } catch (Throwable $e) {
            throw $e;
        }

        return [
            'rows' => $result['rows'] ?? [],
            'columns' => $result['columns'] ?? $entry['columns'],
            'total' => $result['total'] ?? count($result['rows'] ?? []),
            'drill_down' => $entry['drill_down'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function describe(array $entry): array
    {
        return [
            'key' => $entry['key'],
            'module' => $entry['module'],
            'name' => $entry['name'],
            'description' => $entry['description'],
            'parameters' => $entry['parameters'],
            'columns' => $entry['columns'],
            'drill_down' => $entry['drill_down'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $given
     * @return array<string, mixed>
     */
    private function normalizeParameters(array $entry, array $given): array
    {
        $out = [];
        foreach ($entry['parameters'] as $spec) {
            $name = $spec['name'];
            if (array_key_exists($name, $given) && $given[$name] !== null && $given[$name] !== '') {
                $out[$name] = $this->castParameter($spec['type'] ?? 'string', $given[$name]);
                continue;
            }
            if (array_key_exists('default', $spec)) {
                $out[$name] = $spec['default'];
                continue;
            }
            if (!empty($spec['required'])) {
                throw new InvalidArgumentException("Missing required parameter: {$name}");
            }
            $out[$name] = null;
        }
        return $out;
    }

    private function castParameter(string $type, mixed $value): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'date' => $this->normalizeDate((string) $value),
            'datetime' => $this->normalizeDateTime((string) $value),
            default => (string) $value,
        };
    }

    private function normalizeDate(string $value): string
    {
        $dt = new DateTimeImmutable($value);
        return $dt->format('Y-m-d');
    }

    private function normalizeDateTime(string $value): string
    {
        $dt = new DateTimeImmutable($value);
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildCatalog(): array
    {
        $entries = [];

        $entries['workorder.status_summary'] = [
            'key' => 'workorder.status_summary',
            'module' => 'workorder',
            'name' => 'Workorders by Status',
            'description' => 'Count of workorders grouped by status within a date range.',
            'parameters' => [
                ['name' => 'start_date', 'type' => 'date', 'label' => 'Start date', 'required' => true],
                ['name' => 'end_date', 'type' => 'date', 'label' => 'End date', 'required' => true],
            ],
            'columns' => [
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['key' => 'count', 'label' => 'Count', 'type' => 'int'],
                ['key' => 'total_value', 'label' => 'Total Value', 'type' => 'currency'],
            ],
            'drill_down' => ['target' => 'workorder', 'key' => 'status'],
            'runner' => function (Connection $conn, array $p): array {
                $sql = 'SELECT status, COUNT(*) AS count, COALESCE(SUM(total), 0) AS total_value '
                    . 'FROM workorders WHERE created_at >= :s AND created_at <= :e '
                    . 'GROUP BY status ORDER BY count DESC';
                $stmt = $conn->pdo()->prepare($sql);
                $stmt->execute(['s' => $p['start_date'] . ' 00:00:00', 'e' => $p['end_date'] . ' 23:59:59']);
                $rows = array_map(static fn ($r) => [
                    'status' => $r['status'],
                    'count' => (int) $r['count'],
                    'total_value' => (float) $r['total_value'],
                ], $stmt->fetchAll(PDO::FETCH_ASSOC));
                return ['rows' => $rows, 'total' => count($rows)];
            },
        ];

        $entries['workorder.technician_load'] = [
            'key' => 'workorder.technician_load',
            'module' => 'workorder',
            'name' => 'Technician Workload',
            'description' => 'Open workorder count and value per technician.',
            'parameters' => [
                ['name' => 'start_date', 'type' => 'date', 'label' => 'Start date', 'required' => true],
                ['name' => 'end_date', 'type' => 'date', 'label' => 'End date', 'required' => true],
            ],
            'columns' => [
                ['key' => 'technician_id', 'label' => 'Technician ID', 'type' => 'int'],
                ['key' => 'technician_name', 'label' => 'Technician', 'type' => 'string'],
                ['key' => 'open_count', 'label' => 'Open', 'type' => 'int'],
                ['key' => 'closed_count', 'label' => 'Closed', 'type' => 'int'],
                ['key' => 'total_value', 'label' => 'Value', 'type' => 'currency'],
            ],
            'drill_down' => ['target' => 'workorder', 'key' => 'technician_id'],
            'runner' => function (Connection $conn, array $p): array {
                $sql = 'SELECT w.technician_id AS technician_id, '
                    . "COALESCE(u.name, 'Unassigned') AS technician_name, "
                    . "SUM(CASE WHEN w.status NOT IN ('completed','closed','cancelled') THEN 1 ELSE 0 END) AS open_count, "
                    . "SUM(CASE WHEN w.status IN ('completed','closed') THEN 1 ELSE 0 END) AS closed_count, "
                    . 'COALESCE(SUM(w.total), 0) AS total_value '
                    . 'FROM workorders w LEFT JOIN users u ON u.id = w.technician_id '
                    . 'WHERE w.created_at >= :s AND w.created_at <= :e '
                    . 'GROUP BY w.technician_id, u.name '
                    . 'ORDER BY open_count DESC';
                $stmt = $conn->pdo()->prepare($sql);
                $stmt->execute(['s' => $p['start_date'] . ' 00:00:00', 'e' => $p['end_date'] . ' 23:59:59']);
                $rows = array_map(static fn ($r) => [
                    'technician_id' => $r['technician_id'] !== null ? (int) $r['technician_id'] : null,
                    'technician_name' => $r['technician_name'],
                    'open_count' => (int) $r['open_count'],
                    'closed_count' => (int) $r['closed_count'],
                    'total_value' => (float) $r['total_value'],
                ], $stmt->fetchAll(PDO::FETCH_ASSOC));
                return ['rows' => $rows, 'total' => count($rows)];
            },
        ];

        $entries['invoice.aging'] = [
            'key' => 'invoice.aging',
            'module' => 'invoice',
            'name' => 'Invoice Aging',
            'description' => 'Outstanding invoices bucketed by age (current, 30, 60, 90+).',
            'parameters' => [
                ['name' => 'as_of', 'type' => 'date', 'label' => 'As-of date', 'required' => false, 'default' => null],
            ],
            'columns' => [
                ['key' => 'bucket', 'label' => 'Bucket', 'type' => 'string'],
                ['key' => 'count', 'label' => 'Count', 'type' => 'int'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'currency'],
            ],
            'drill_down' => ['target' => 'invoice', 'key' => 'bucket'],
            'runner' => function (Connection $conn, array $p): array {
                $asOf = $p['as_of'] ?: (new DateTimeImmutable())->format('Y-m-d');
                $sql = "SELECT CASE "
                    . "WHEN DATEDIFF(:asof, created_at) <= 30 THEN '0-30' "
                    . "WHEN DATEDIFF(:asof, created_at) <= 60 THEN '31-60' "
                    . "WHEN DATEDIFF(:asof, created_at) <= 90 THEN '61-90' "
                    . "ELSE '90+' END AS bucket, "
                    . 'COUNT(*) AS count, COALESCE(SUM(total - paid_amount), 0) AS amount '
                    . "FROM invoices WHERE status NOT IN ('paid','void','cancelled') "
                    . 'GROUP BY bucket ORDER BY bucket ASC';
                $stmt = $conn->pdo()->prepare($sql);
                $stmt->execute(['asof' => $asOf]);
                $rows = array_map(static fn ($r) => [
                    'bucket' => $r['bucket'],
                    'count' => (int) $r['count'],
                    'amount' => (float) $r['amount'],
                ], $stmt->fetchAll(PDO::FETCH_ASSOC));
                return ['rows' => $rows, 'total' => count($rows)];
            },
        ];

        $entries['invoice.revenue_by_month'] = [
            'key' => 'invoice.revenue_by_month',
            'module' => 'invoice',
            'name' => 'Revenue by Month',
            'description' => 'Monthly invoice totals for paid invoices in a date range.',
            'parameters' => [
                ['name' => 'start_date', 'type' => 'date', 'label' => 'Start date', 'required' => true],
                ['name' => 'end_date', 'type' => 'date', 'label' => 'End date', 'required' => true],
            ],
            'columns' => [
                ['key' => 'month', 'label' => 'Month', 'type' => 'string'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'type' => 'int'],
                ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'currency'],
            ],
            'runner' => function (Connection $conn, array $p): array {
                $sql = "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, "
                    . 'COUNT(*) AS invoice_count, COALESCE(SUM(total), 0) AS revenue '
                    . "FROM invoices WHERE status = 'paid' "
                    . 'AND created_at >= :s AND created_at <= :e '
                    . 'GROUP BY month ORDER BY month ASC';
                $stmt = $conn->pdo()->prepare($sql);
                $stmt->execute(['s' => $p['start_date'] . ' 00:00:00', 'e' => $p['end_date'] . ' 23:59:59']);
                $rows = array_map(static fn ($r) => [
                    'month' => $r['month'],
                    'invoice_count' => (int) $r['invoice_count'],
                    'revenue' => (float) $r['revenue'],
                ], $stmt->fetchAll(PDO::FETCH_ASSOC));
                return ['rows' => $rows, 'total' => count($rows)];
            },
        ];

        $entries['customer.top_revenue'] = [
            'key' => 'customer.top_revenue',
            'module' => 'customer',
            'name' => 'Top Customers by Revenue',
            'description' => 'Customers ranked by total invoiced revenue in a date range.',
            'parameters' => [
                ['name' => 'start_date', 'type' => 'date', 'label' => 'Start date', 'required' => true],
                ['name' => 'end_date', 'type' => 'date', 'label' => 'End date', 'required' => true],
                ['name' => 'limit', 'type' => 'int', 'label' => 'Limit', 'required' => false, 'default' => 25],
            ],
            'columns' => [
                ['key' => 'customer_id', 'label' => 'Customer ID', 'type' => 'int'],
                ['key' => 'customer_name', 'label' => 'Customer', 'type' => 'string'],
                ['key' => 'invoice_count', 'label' => 'Invoices', 'type' => 'int'],
                ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'currency'],
            ],
            'drill_down' => ['target' => 'customer', 'key' => 'customer_id'],
            'runner' => function (Connection $conn, array $p): array {
                $limit = max(1, min((int) ($p['limit'] ?? 25), 500));
                $sql = 'SELECT i.customer_id, c.name AS customer_name, '
                    . 'COUNT(*) AS invoice_count, COALESCE(SUM(i.total), 0) AS revenue '
                    . 'FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id '
                    . "WHERE i.status = 'paid' AND i.created_at >= :s AND i.created_at <= :e "
                    . 'GROUP BY i.customer_id, c.name '
                    . 'ORDER BY revenue DESC LIMIT ' . $limit;
                $stmt = $conn->pdo()->prepare($sql);
                $stmt->execute(['s' => $p['start_date'] . ' 00:00:00', 'e' => $p['end_date'] . ' 23:59:59']);
                $rows = array_map(static fn ($r) => [
                    'customer_id' => (int) $r['customer_id'],
                    'customer_name' => $r['customer_name'],
                    'invoice_count' => (int) $r['invoice_count'],
                    'revenue' => (float) $r['revenue'],
                ], $stmt->fetchAll(PDO::FETCH_ASSOC));
                return ['rows' => $rows, 'total' => count($rows)];
            },
        ];

        $entries['inventory.low_stock'] = [
            'key' => 'inventory.low_stock',
            'module' => 'inventory',
            'name' => 'Low Stock Items',
            'description' => 'Inventory items at or below their reorder threshold.',
            'parameters' => [],
            'columns' => [
                ['key' => 'item_id', 'label' => 'Item ID', 'type' => 'int'],
                ['key' => 'sku', 'label' => 'SKU', 'type' => 'string'],
                ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
                ['key' => 'on_hand', 'label' => 'On Hand', 'type' => 'int'],
                ['key' => 'reorder_threshold', 'label' => 'Threshold', 'type' => 'int'],
            ],
            'drill_down' => ['target' => 'inventory_item', 'key' => 'item_id'],
            'runner' => function (Connection $conn, array $p): array {
                $sql = 'SELECT id AS item_id, sku, name, '
                    . 'COALESCE(quantity_on_hand, 0) AS on_hand, '
                    . 'COALESCE(reorder_threshold, 0) AS reorder_threshold '
                    . 'FROM inventory_items '
                    . 'WHERE reorder_threshold IS NOT NULL '
                    . 'AND COALESCE(quantity_on_hand, 0) <= COALESCE(reorder_threshold, 0) '
                    . 'ORDER BY on_hand ASC LIMIT 500';
                $stmt = $conn->pdo()->query($sql);
                $rows = array_map(static fn ($r) => [
                    'item_id' => (int) $r['item_id'],
                    'sku' => $r['sku'],
                    'name' => $r['name'],
                    'on_hand' => (int) $r['on_hand'],
                    'reorder_threshold' => (int) $r['reorder_threshold'],
                ], $stmt->fetchAll(PDO::FETCH_ASSOC));
                return ['rows' => $rows, 'total' => count($rows)];
            },
        ];

        $entries['payment.by_method'] = [
            'key' => 'payment.by_method',
            'module' => 'payment',
            'name' => 'Payments by Method',
            'description' => 'Aggregated payment counts and amounts by payment method.',
            'parameters' => [
                ['name' => 'start_date', 'type' => 'date', 'label' => 'Start date', 'required' => true],
                ['name' => 'end_date', 'type' => 'date', 'label' => 'End date', 'required' => true],
            ],
            'columns' => [
                ['key' => 'method', 'label' => 'Method', 'type' => 'string'],
                ['key' => 'count', 'label' => 'Count', 'type' => 'int'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'currency'],
            ],
            'runner' => function (Connection $conn, array $p): array {
                $sql = 'SELECT payment_method AS method, COUNT(*) AS count, '
                    . 'COALESCE(SUM(amount), 0) AS amount '
                    . 'FROM payments WHERE created_at >= :s AND created_at <= :e '
                    . 'GROUP BY payment_method ORDER BY amount DESC';
                $stmt = $conn->pdo()->prepare($sql);
                $stmt->execute(['s' => $p['start_date'] . ' 00:00:00', 'e' => $p['end_date'] . ' 23:59:59']);
                $rows = array_map(static fn ($r) => [
                    'method' => $r['method'] ?? 'unknown',
                    'count' => (int) $r['count'],
                    'amount' => (float) $r['amount'],
                ], $stmt->fetchAll(PDO::FETCH_ASSOC));
                return ['rows' => $rows, 'total' => count($rows)];
            },
        ];

        $entries['appointment.by_day'] = [
            'key' => 'appointment.by_day',
            'module' => 'appointment',
            'name' => 'Appointments by Day',
            'description' => 'Daily appointment counts in a date range.',
            'parameters' => [
                ['name' => 'start_date', 'type' => 'date', 'label' => 'Start date', 'required' => true],
                ['name' => 'end_date', 'type' => 'date', 'label' => 'End date', 'required' => true],
            ],
            'columns' => [
                ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
                ['key' => 'count', 'label' => 'Appointments', 'type' => 'int'],
            ],
            'runner' => function (Connection $conn, array $p): array {
                $sql = 'SELECT DATE(scheduled_at) AS day, COUNT(*) AS count '
                    . 'FROM appointments '
                    . 'WHERE scheduled_at >= :s AND scheduled_at <= :e '
                    . 'GROUP BY day ORDER BY day ASC';
                $stmt = $conn->pdo()->prepare($sql);
                $stmt->execute(['s' => $p['start_date'] . ' 00:00:00', 'e' => $p['end_date'] . ' 23:59:59']);
                $rows = array_map(static fn ($r) => [
                    'day' => $r['day'],
                    'count' => (int) $r['count'],
                ], $stmt->fetchAll(PDO::FETCH_ASSOC));
                return ['rows' => $rows, 'total' => count($rows)];
            },
        ];

        return $entries;
    }
}
