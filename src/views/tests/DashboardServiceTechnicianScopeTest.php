<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Dashboard\DashboardService;

class InMemoryConnection extends Connection
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

function setUpDashboardDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $schema = [
        'CREATE TABLE estimates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            status VARCHAR(40) NOT NULL,
            technician_id INT NULL,
            tax DECIMAL(12,2) DEFAULT 0,
            grand_total DECIMAL(12,2) DEFAULT 0,
            created_at DATETIME NULL
        )',
        'CREATE TABLE estimate_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            estimate_id INT NOT NULL,
            service_type_id INT NOT NULL,
            total DECIMAL(12,2) DEFAULT 0
        )',
        'CREATE TABLE service_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(120) NOT NULL
        )',
        'CREATE TABLE invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            estimate_id INT NULL,
            customer_id INT NOT NULL,
            status VARCHAR(40) NOT NULL,
            issue_date DATE NOT NULL,
            total DECIMAL(12,2) DEFAULT 0,
            amount_paid DECIMAL(12,2) DEFAULT 0,
            balance_due DECIMAL(12,2) DEFAULT 0,
            tax DECIMAL(12,2) DEFAULT 0
        )',
        'CREATE TABLE appointments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            technician_id INT NULL,
            status VARCHAR(40) NOT NULL,
            start_time DATETIME NOT NULL,
            end_time DATETIME NOT NULL
        )',
        'CREATE TABLE warranty_claims (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INT NOT NULL,
            status VARCHAR(40) NOT NULL,
            created_at DATETIME NULL
        )',
        'CREATE TABLE inventory_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stock_quantity INT DEFAULT 0,
            low_stock_threshold INT DEFAULT 0
        )',
    ];

    foreach ($schema as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT INTO service_types (id, name) VALUES (1, 'HVAC'), (2, 'Electrical')");
    $pdo->exec("INSERT INTO estimates (id, customer_id, vehicle_id, status, technician_id, tax, grand_total, created_at) VALUES
        (1, 10, 20, 'approved', 101, 15.00, 300.00, '2023-01-10 12:00:00'),
        (2, 11, 21, 'draft', 202, 7.00, 150.00, '2023-01-12 12:00:00')");
    $pdo->exec("INSERT INTO estimate_jobs (estimate_id, service_type_id, total) VALUES
        (1, 1, 300.00),
        (2, 2, 150.00)");
    $pdo->exec("INSERT INTO invoices (estimate_id, customer_id, status, issue_date, total, amount_paid, balance_due, tax) VALUES
        (1, 10, 'pending', '2023-01-15 00:00:00', 400.00, 100.00, 300.00, 20.00),
        (2, 11, 'paid', '2023-01-20 00:00:00', 200.00, 200.00, 0.00, 10.00)");
    $pdo->exec("INSERT INTO appointments (customer_id, vehicle_id, technician_id, status, start_time, end_time) VALUES
        (10, 20, 101, 'scheduled', '2023-01-18 09:00:00', '2023-01-18 10:00:00'),
        (11, 21, 202, 'scheduled', '2023-01-19 11:00:00', '2023-01-19 12:00:00')");

    return $pdo;
}

$pdo = setUpDashboardDatabase();
$service = new DashboardService(new InMemoryConnection($pdo));

$start = new DateTimeImmutable('2023-01-01');
$end = new DateTimeImmutable('2023-02-28');
$options = ['role' => 'technician', 'technician_id' => 101, 'timezone' => 'UTC'];

$kpis = $service->kpis($start, $end, $options);
$trends = $service->monthlyTrends($start, $end, $options);
$serviceTypes = $service->serviceTypeBreakdown($start, $end, $options)->toArray();

$assertions = [
    'estimate counts scoped' => ($kpis->estimateStatusCounts['approved'] ?? 0) === 1
        && count($kpis->estimateStatusCounts) === 1,
    'invoice totals scoped' => abs(($kpis->invoiceTotals['total'] ?? 0) - 400.0) < 0.001,
    'pending invoices scoped' => ($kpis->summary['pending_invoices'] ?? 0) === 1,
    'appointments scoped' => ($kpis->appointmentCounts['scheduled'] ?? 0) === 1,
    'invoice trend scoped' => isset($trends[0]) && $trends[0]->toArray()['data'][0] === 400.0
        && $trends[0]->toArray()['data'][1] === 0.0,
    'service type scoped' => $serviceTypes['categories'] === ['HVAC']
        && $serviceTypes['data'] === [300.0],
];

try {
    $service->kpis($start, $end, ['role' => 'technician']);
    $assertions['technician role requires id'] = false;
} catch (InvalidArgumentException $exception) {
    $assertions['technician role requires id'] = true;
}

$failures = array_filter($assertions, static fn (bool $passed): bool => $passed === false);

if ($failures) {
    foreach ($failures as $scenario => $_) {
        fwrite(STDERR, 'FAILED: ' . $scenario . PHP_EOL);
    }
    exit(1);
}

echo 'All dashboard technician scope tests passed.' . PHP_EOL;
