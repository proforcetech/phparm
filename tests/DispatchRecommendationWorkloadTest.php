<?php

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Dispatch\DispatchRecommendationService;

class FakeConnection extends Connection
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

function setUpInMemoryDatabase(DateTimeImmutable $referenceTime): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->sqliteCreateFunction('CURDATE', static fn () => $referenceTime->format('Y-m-d'));

    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(120) NOT NULL
    )');

    $pdo->exec('CREATE TABLE driver_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INT NOT NULL,
        availability_status VARCHAR(40) NOT NULL,
        certifications TEXT NULL,
        base_latitude DECIMAL(10,6) NULL,
        base_longitude DECIMAL(10,6) NULL
    )');

    $pdo->exec('CREATE TABLE truck_equipment (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        driver_profile_id INT NOT NULL,
        equipment_class VARCHAR(60) NOT NULL,
        capacity DECIMAL(10,2) NULL
    )');

    $pdo->exec('CREATE TABLE driver_shifts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        driver_profile_id INT NOT NULL,
        shift_start DATETIME NOT NULL,
        shift_end DATETIME NOT NULL,
        minutes_worked INT NOT NULL DEFAULT 0
    )');

    $pdo->exec('CREATE TABLE driver_performance_metrics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        driver_profile_id INT NOT NULL,
        jobs_completed INT NOT NULL DEFAULT 0,
        jobs_accepted INT NOT NULL DEFAULT 0,
        jobs_declined INT NOT NULL DEFAULT 0,
        avg_customer_rating DECIMAL(3,2) NULL,
        on_time_arrivals INT NOT NULL DEFAULT 0,
        late_arrivals INT NOT NULL DEFAULT 0,
        metric_date DATE NOT NULL
    )');

    $pdo->exec('CREATE TABLE driver_certifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        driver_profile_id INT NOT NULL,
        certification_code VARCHAR(60) NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT "active",
        expiry_date DATE NULL
    )');

    $pdo->exec('CREATE TABLE workorders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        number VARCHAR(50) NOT NULL,
        status VARCHAR(40) NOT NULL,
        assigned_technician_id INT NULL
    )');

    $pdo->exec('CREATE TABLE workorder_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INT NOT NULL,
        title VARCHAR(160) NOT NULL,
        status VARCHAR(40) NOT NULL,
        started_at DATETIME NULL,
        total DECIMAL(12,2) DEFAULT 0
    )');

    $pdo->exec('CREATE TABLE workorder_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_job_id INT NOT NULL,
        description VARCHAR(255) NOT NULL
    )');

    return $pdo;
}

$referenceTime = new DateTimeImmutable('now');
$pdo = setUpInMemoryDatabase($referenceTime);
$connection = new FakeConnection($pdo);

$pdo->prepare('INSERT INTO users (name, email) VALUES (:name, :email)')
    ->execute(['name' => 'Driver Busy', 'email' => 'busy@example.com']);
$pdo->prepare('INSERT INTO users (name, email) VALUES (:name, :email)')
    ->execute(['name' => 'Driver Idle', 'email' => 'idle@example.com']);

$pdo->exec('INSERT INTO driver_profiles (user_id, availability_status, base_latitude, base_longitude)
    VALUES (1, "available", 40.0000, -75.0000)');
$pdo->exec('INSERT INTO driver_profiles (user_id, availability_status, base_latitude, base_longitude)
    VALUES (2, "available", 40.0005, -75.0005)');

$pdo->exec('INSERT INTO truck_equipment (driver_profile_id, equipment_class, capacity) VALUES (1, "flatbed", 5000)');
$pdo->exec('INSERT INTO truck_equipment (driver_profile_id, equipment_class, capacity) VALUES (2, "flatbed", 5000)');

$shiftStart = $referenceTime->sub(new DateInterval('PT1H'))->format('Y-m-d H:i:s');
$shiftEnd = $referenceTime->add(new DateInterval('PT7H'))->format('Y-m-d H:i:s');
$pdo->prepare('INSERT INTO driver_shifts (driver_profile_id, shift_start, shift_end, minutes_worked)
    VALUES (1, :start, :end, 0)')
    ->execute(['start' => $shiftStart, 'end' => $shiftEnd]);
$pdo->prepare('INSERT INTO driver_shifts (driver_profile_id, shift_start, shift_end, minutes_worked)
    VALUES (2, :start, :end, 0)')
    ->execute(['start' => $shiftStart, 'end' => $shiftEnd]);

$metricDate = $referenceTime->format('Y-m-d');
$pdo->prepare('INSERT INTO driver_performance_metrics
    (driver_profile_id, jobs_completed, jobs_accepted, jobs_declined, avg_customer_rating, on_time_arrivals, late_arrivals, metric_date)
    VALUES (1, 2, 4, 1, 4.8, 4, 0, :metric_date)')
    ->execute(['metric_date' => $metricDate]);
$pdo->prepare('INSERT INTO driver_performance_metrics
    (driver_profile_id, jobs_completed, jobs_accepted, jobs_declined, avg_customer_rating, on_time_arrivals, late_arrivals, metric_date)
    VALUES (2, 1, 3, 0, 4.6, 3, 0, :metric_date)')
    ->execute(['metric_date' => $metricDate]);

$pdo->exec('INSERT INTO workorders (number, status, assigned_technician_id) VALUES ("WO-1001", "in_progress", 1)');
$startedAt = $referenceTime->sub(new DateInterval('PT90M'))->format('Y-m-d H:i:s');
$pdo->prepare('INSERT INTO workorder_jobs (workorder_id, title, status, started_at, total)
    VALUES (1, "Complex Recovery", "in_progress", :started_at, 1200)')
    ->execute(['started_at' => $startedAt]);

for ($i = 0; $i < 10; $i++) {
    $pdo->prepare('INSERT INTO workorder_items (workorder_job_id, description) VALUES (1, :desc)')
        ->execute(['desc' => 'Item ' . $i]);
}

$service = new DispatchRecommendationService($connection, null, false, true);

$results = $service->suggest([
    'pickup_latitude' => 40.0002,
    'pickup_longitude' => -75.0002,
    'dropoff_latitude' => 40.0100,
    'dropoff_longitude' => -75.0100,
    'required_equipment_class' => 'flatbed',
    'required_capacity' => 1000,
    'estimated_duration_hours' => 1,
    'scheduled_start' => $referenceTime->format('Y-m-d H:i:s'),
], 2);

$suggestions = $results['data'] ?? [];
$resultsList = [];

$resultsList[] = [
    'scenario' => 'idle driver ranks above busy driver with similar distance',
    'passed' => isset($suggestions[0]) && (int) $suggestions[0]['driver_profile_id'] === 2,
];

$busySuggestion = $suggestions[1] ?? null;
$resultsList[] = [
    'scenario' => 'busy driver workload metrics are reported',
    'passed' => $busySuggestion !== null
        && $busySuggestion['workload'] !== null
        && $busySuggestion['workload']['minutes_in_progress'] >= 85,
];

$idleSuggestion = $suggestions[0] ?? null;
$resultsList[] = [
    'scenario' => 'idle driver has no workload metrics',
    'passed' => $idleSuggestion !== null && $idleSuggestion['workload'] === null,
];

$failures = array_filter($resultsList, static fn (array $row) => $row['passed'] === false);
if ($failures) {
    foreach ($resultsList as $result) {
        echo sprintf("[%s] %s\n", $result['passed'] ? 'PASS' : 'FAIL', $result['scenario']);
    }
    exit(1);
}

foreach ($resultsList as $result) {
    echo sprintf("[PASS] %s\n", $result['scenario']);
}
