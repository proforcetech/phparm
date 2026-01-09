<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Reports\TechnicianMarginReportService;
use App\Support\SettingsRepository;

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

function setUpTechnicianMarginDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $schema = [
        'CREATE TABLE settings (
            `key` VARCHAR(120) PRIMARY KEY,
            `group` VARCHAR(120) NULL,
            type VARCHAR(20) NOT NULL,
            value TEXT NULL,
            description TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )',
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name VARCHAR(120) NOT NULL
        )',
        'CREATE TABLE workorders (
            id INTEGER PRIMARY KEY,
            branch_id INT NULL,
            assigned_technician_id INT NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )',
        'CREATE TABLE workorder_jobs (
            id INTEGER PRIMARY KEY,
            workorder_id INT NOT NULL,
            assigned_technician_id INT NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )',
        'CREATE TABLE workorder_items (
            id INTEGER PRIMARY KEY,
            workorder_job_id INT NOT NULL,
            type VARCHAR(40) NOT NULL,
            line_total DECIMAL(12,2) DEFAULT 0
        )',
        'CREATE TABLE labor_tasks (
            id INTEGER PRIMARY KEY,
            labor_rate DECIMAL(10,2) NULL
        )',
        'CREATE TABLE time_entries (
            id INTEGER PRIMARY KEY,
            technician_id INT NOT NULL,
            workorder_job_id INT NULL,
            task_id INT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            duration_minutes DECIMAL(10,2) NULL,
            status VARCHAR(20) NOT NULL
        )',
    ];

    foreach ($schema as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT INTO settings (`key`, `group`, type, value) VALUES ('pricing.labor_rate', 'pricing', 'float', '50')");
    $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Alex Tech'), (2, 'Jordan Tech')");
    $pdo->exec("INSERT INTO workorders (id, branch_id, assigned_technician_id, completed_at, created_at) VALUES
        (1, 10, 1, '2024-01-15 12:00:00', '2024-01-10 08:00:00'),
        (2, 10, 2, '2024-01-16 12:00:00', '2024-01-12 08:00:00')");
    $pdo->exec("INSERT INTO workorder_jobs (id, workorder_id, assigned_technician_id, completed_at, created_at) VALUES
        (1, 1, 1, '2024-01-15 12:00:00', '2024-01-10 09:00:00'),
        (2, 2, 2, '2024-01-16 12:00:00', '2024-01-12 09:00:00')");
    $pdo->exec("INSERT INTO workorder_items (id, workorder_job_id, type, line_total) VALUES
        (1, 1, 'LABOR', 200.00),
        (2, 1, 'PART', 50.00),
        (3, 2, 'LABOR', 150.00)");
    $pdo->exec("INSERT INTO labor_tasks (id, labor_rate) VALUES (1, 60.00)");
    $pdo->exec("INSERT INTO time_entries (id, technician_id, workorder_job_id, task_id, started_at, ended_at, duration_minutes, status) VALUES
        (1, 1, 1, 1, '2024-01-15 08:00:00', '2024-01-15 10:00:00', 120, 'approved'),
        (2, 2, 2, NULL, '2024-01-16 09:00:00', '2024-01-16 10:00:00', 60, 'approved'),
        (3, 1, 1, 1, '2023-12-15 09:00:00', '2023-12-15 10:00:00', 60, 'approved')");

    return $pdo;
}

$pdo = setUpTechnicianMarginDatabase();
$connection = new InMemoryConnection($pdo);
$settings = new SettingsRepository($connection);
$service = new TechnicianMarginReportService($connection, $settings);

$report = $service->report('2024-01-01', '2024-01-31', 10);
$rows = array_column($report['data'], null, 'technician_id');

$assertions = [
    'tech1 billed labor' => abs(($rows[1]['billed_labor'] ?? 0) - 200.0) < 0.01,
    'tech1 actual cost' => abs(($rows[1]['actual_labor_cost'] ?? 0) - 120.0) < 0.01,
    'tech1 margin' => abs(($rows[1]['margin'] ?? 0) - 80.0) < 0.01,
    'tech1 margin percentage' => abs(($rows[1]['margin_percentage'] ?? 0) - 40.0) < 0.01,
    'tech2 billed labor' => abs(($rows[2]['billed_labor'] ?? 0) - 150.0) < 0.01,
    'tech2 actual cost' => abs(($rows[2]['actual_labor_cost'] ?? 0) - 50.0) < 0.01,
    'tech2 margin' => abs(($rows[2]['margin'] ?? 0) - 100.0) < 0.01,
    'summary total billed' => abs(($report['summary']['total_billed_labor'] ?? 0) - 350.0) < 0.01,
    'summary total actual cost' => abs(($report['summary']['total_actual_cost'] ?? 0) - 170.0) < 0.01,
    'summary total margin' => abs(($report['summary']['total_margin'] ?? 0) - 180.0) < 0.01,
    'summary margin percentage' => abs(($report['summary']['overall_margin_percentage'] ?? 0) - 51.43) < 0.01,
];

$emptyReport = $service->report('2024-01-01', '2024-01-31', 99);
$assertions['branch filter returns empty'] = empty($emptyReport['data'])
    && ($emptyReport['summary']['total_billed_labor'] ?? 0) === 0.0;

$failures = array_filter($assertions, static fn (bool $passed): bool => $passed === false);

if ($failures) {
    foreach ($failures as $scenario => $_) {
        fwrite(STDERR, 'FAILED: ' . $scenario . PHP_EOL);
    }
    exit(1);
}

echo 'All technician margin report tests passed.' . PHP_EOL;
