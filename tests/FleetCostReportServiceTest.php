<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "FleetCostReportServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\User;
use App\Services\Fleet\FleetCostReportRepository;
use App\Services\Fleet\FleetCostReportService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 7.4 of docs/expansion-plan.md — fleet cost reports.
 * SQLite-in-memory covers cost-per-mile / cost-per-hour aggregation,
 * labor/parts split, date-range boundaries, divide-by-zero safety,
 * retired-unit inclusion, cross-company filtering, and permission
 * gating.
 */

// ---------------------------------------------------------------------------
// SQLite connection + schema
// ---------------------------------------------------------------------------

class CostReportInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function costReportSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE fleet_units (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            unit_number TEXT NOT NULL,
            unit_type TEXT NOT NULL DEFAULT "truck",
            status TEXT NOT NULL DEFAULT "active"
        )'
    );
    $pdo->exec(
        'CREATE TABLE fleet_unit_readings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fleet_unit_id INTEGER NOT NULL,
            reading_type TEXT NOT NULL,
            value REAL NOT NULL,
            recorded_at TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT "manual",
            workorder_id INTEGER NULL,
            notes TEXT NULL,
            recorded_by_user_id INTEGER NOT NULL,
            created_at TEXT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE workorders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            number TEXT NOT NULL,
            fleet_unit_id INTEGER NULL,
            completed_at TEXT NULL,
            grand_total REAL NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'CREATE TABLE workorder_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            workorder_id INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE workorder_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            workorder_job_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            line_total REAL NOT NULL DEFAULT 0
        )'
    );
    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class CostReportPermissiveGate extends AccessGate
{
    /** @var array<string, bool> */
    public array $denials = [];
    public function __construct()
    {
    }
    public function can(User $user, string $permission, mixed $resource = null): bool
    {
        return empty($this->denials[$permission]);
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (!empty($this->denials[$permission])) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }
}

// ---------------------------------------------------------------------------
// Seed helpers
// ---------------------------------------------------------------------------

function seedUnit(PDO $pdo, int $companyId, string $number, string $status = 'active'): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO fleet_units (company_id, unit_number, unit_type, status)
         VALUES (?, ?, "truck", ?)'
    );
    $stmt->execute([$companyId, $number, $status]);
    return (int) $pdo->lastInsertId();
}

function seedReading(PDO $pdo, int $unitId, string $type, float $value, string $at): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO fleet_unit_readings
             (fleet_unit_id, reading_type, value, recorded_at, source, recorded_by_user_id)
         VALUES (?, ?, ?, ?, "manual", 1)'
    );
    $stmt->execute([$unitId, $type, $value, $at]);
}

function seedWorkorder(
    PDO $pdo,
    int $unitId,
    string $completedAt,
    float $grandTotal,
    float $laborTotal = 0.0,
    float $partsTotal = 0.0,
    ?string $number = null,
): int {
    $number ??= 'WO-' . mt_rand(1000, 9999) . '-' . mt_rand(1000, 9999);
    $stmt = $pdo->prepare(
        'INSERT INTO workorders (number, fleet_unit_id, completed_at, grand_total)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$number, $unitId, $completedAt, $grandTotal]);
    $woId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO workorder_jobs (workorder_id) VALUES (?)')->execute([$woId]);
    $jobId = (int) $pdo->lastInsertId();
    if ($laborTotal > 0) {
        $pdo->prepare('INSERT INTO workorder_items (workorder_job_id, type, line_total) VALUES (?, "LABOR", ?)')
            ->execute([$jobId, $laborTotal]);
    }
    if ($partsTotal > 0) {
        $pdo->prepare('INSERT INTO workorder_items (workorder_job_id, type, line_total) VALUES (?, "PART", ?)')
            ->execute([$jobId, $partsTotal]);
    }
    return $woId;
}

/**
 * @return array{
 *   service: FleetCostReportService,
 *   pdo: PDO,
 *   reports: FleetCostReportRepository,
 *   gate: CostReportPermissiveGate,
 *   actor: User
 * }
 */
function makeCostReportFixture(): array
{
    $pdo = costReportSetUpDatabase();
    $conn = new CostReportInMemoryConnection($pdo);
    $gate = new CostReportPermissiveGate();
    $reports = new FleetCostReportRepository($conn);
    $service = new FleetCostReportService($reports, $gate);
    $actor = new User();
    $actor->id = 7;
    return [
        'service' => $service,
        'pdo' => $pdo,
        'reports' => $reports,
        'gate' => $gate,
        'actor' => $actor,
    ];
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

$failures = 0;
$cases = 0;

function runCase(string $name, callable $fn): void
{
    global $failures, $cases;
    $cases++;
    try {
        $fn();
        echo "  ok — {$name}\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "  FAIL — {$name}: " . $e->getMessage() . "\n";
    }
}

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function assertEquals(mixed $expected, mixed $actual, string $msg): void
{
    if ($expected != $actual) {
        throw new RuntimeException("{$msg}: expected " . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function rowForUnit(array $report, int $unitId): ?array
{
    foreach ($report['rows'] as $r) {
        if ($r['fleet_unit_id'] === $unitId) {
            return $r;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------

echo "FleetCostReportServiceTest\n";

runCase('costPerMile aggregates total + labor/parts split', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'T-1');
    seedReading($f['pdo'], $unitA, 'odometer', 10000, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $unitA, 'odometer', 12000, '2026-03-20 17:00:00');
    seedWorkorder($f['pdo'], $unitA, '2026-03-15 14:00:00', 1500.00, 800.00, 500.00);
    seedWorkorder($f['pdo'], $unitA, '2026-03-22 10:00:00', 500.00, 300.00, 100.00);

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    assertEquals('miles', $r['unit'], 'report unit label');
    assertEquals(1, count($r['rows']), 'one row');
    $row = $r['rows'][0];
    assertEquals(2000.0, $row['total_cost'], 'total_cost');
    assertEquals(1100.0, $row['labor_cost'], 'labor_cost');
    assertEquals(600.0, $row['parts_cost'], 'parts_cost');
    assertEquals(2000.0, $row['miles'], 'miles delta');
    assertEquals(1.0, $row['cost_per_mile'], 'cost_per_mile');
    assertEquals(2, $row['workorder_count'], 'workorder_count');
});

runCase('costPerHour uses engine_hours reading type', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'H-1');
    seedReading($f['pdo'], $unitA, 'engine_hours', 100.0, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $unitA, 'engine_hours', 250.0, '2026-03-25 17:00:00');
    seedWorkorder($f['pdo'], $unitA, '2026-03-20 10:00:00', 600.00, 400.00, 200.00);

    $r = $f['service']->costPerHour($f['actor'], 10, '2026-03-01', '2026-03-31');
    assertEquals('hours', $r['unit'], 'report unit label');
    $row = $r['rows'][0];
    assertEquals(150.0, $row['hours'], 'hours delta');
    assertEquals(4.0, $row['cost_per_hour'], 'cost_per_hour');
});

runCase('odometer readings ignored for cost-per-hour', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'X-1');
    seedReading($f['pdo'], $unitA, 'odometer', 5000, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $unitA, 'odometer', 9000, '2026-03-30 17:00:00');
    seedWorkorder($f['pdo'], $unitA, '2026-03-15 10:00:00', 800.00);

    $r = $f['service']->costPerHour($f['actor'], 10, '2026-03-01', '2026-03-31');
    assertEquals(1, count($r['rows']), 'unit still appears via cost');
    $row = $r['rows'][0];
    assertEquals(0.0, $row['hours'], 'hours delta=0 without engine_hours readings');
    assertEquals(null, $row['cost_per_hour'], 'cost_per_hour=null when hours=0');
});

runCase('single reading in window produces zero delta + null cost_per_unit', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'S-1');
    seedReading($f['pdo'], $unitA, 'odometer', 50000, '2026-03-15 12:00:00');
    seedWorkorder($f['pdo'], $unitA, '2026-03-15 14:00:00', 300.00);

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    $row = $r['rows'][0];
    assertEquals(0.0, $row['miles'], 'miles=0 with one reading');
    assertEquals(null, $row['cost_per_mile'], 'cost_per_mile=null avoids div-by-zero');
});

runCase('retired unit still appears in historical report', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'R-1', 'retired');
    seedReading($f['pdo'], $unitA, 'odometer', 100000, '2026-02-01 08:00:00');
    seedReading($f['pdo'], $unitA, 'odometer', 101000, '2026-02-28 17:00:00');
    seedWorkorder($f['pdo'], $unitA, '2026-02-15 10:00:00', 400.00);

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-02-01', '2026-02-28');
    assertEquals(1, count($r['rows']), 'retired unit present');
    assertEquals('retired', $r['rows'][0]['status'], 'status=retired echoed');
});

runCase('cross-company filter excludes other tenant units', function () {
    $f = makeCostReportFixture();
    $myUnit = seedUnit($f['pdo'], 10, 'M-1');
    $theirUnit = seedUnit($f['pdo'], 20, 'OTHER-1');
    seedReading($f['pdo'], $myUnit, 'odometer', 1000, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $myUnit, 'odometer', 2000, '2026-03-30 17:00:00');
    seedReading($f['pdo'], $theirUnit, 'odometer', 5000, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $theirUnit, 'odometer', 9999, '2026-03-30 17:00:00');
    seedWorkorder($f['pdo'], $myUnit, '2026-03-15 10:00:00', 100.00);
    seedWorkorder($f['pdo'], $theirUnit, '2026-03-15 10:00:00', 9000.00);

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    assertEquals(1, count($r['rows']), 'only my company unit');
    assertEquals($myUnit, $r['rows'][0]['fleet_unit_id'], 'right unit id');
    assertEquals(100.0, $r['rows'][0]['total_cost'], 'other company cost excluded');
});

runCase('date range boundaries are inclusive full-day', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'B-1');
    // Reading at 23:59 on the last day should be included.
    seedReading($f['pdo'], $unitA, 'odometer', 100, '2026-03-01 00:00:00');
    seedReading($f['pdo'], $unitA, 'odometer', 500, '2026-03-31 23:59:00');
    seedWorkorder($f['pdo'], $unitA, '2026-03-31 23:59:00', 200.00);
    // Outside the window — should NOT be included.
    seedWorkorder($f['pdo'], $unitA, '2026-04-01 00:00:01', 9999.00);

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    $row = $r['rows'][0];
    assertEquals(400.0, $row['miles'], 'last-minute reading included');
    assertEquals(200.0, $row['total_cost'], 'next-month cost excluded');
});

runCase('empty range returns empty rows + zero totals', function () {
    $f = makeCostReportFixture();
    seedUnit($f['pdo'], 10, 'E-1');

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    assertEquals(0, count($r['rows']), 'no rows');
    assertEquals(0.0, $r['totals']['total_cost'], 'zero total_cost');
    assertEquals(0.0, $r['totals']['miles'], 'zero miles');
    assertEquals(null, $r['totals']['cost_per_mile'], 'null cost_per_mile');
});

runCase('in-progress workorder (no completed_at) excluded', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'P-1');
    seedReading($f['pdo'], $unitA, 'odometer', 1000, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $unitA, 'odometer', 2000, '2026-03-30 17:00:00');
    // Open workorder has completed_at=null.
    $f['pdo']->prepare('INSERT INTO workorders (number, fleet_unit_id, completed_at, grand_total) VALUES (?, ?, NULL, 999)')
        ->execute(['WO-OPEN', $unitA]);
    seedWorkorder($f['pdo'], $unitA, '2026-03-15 10:00:00', 100.00);

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    assertEquals(100.0, $r['rows'][0]['total_cost'], 'only completed-workorder cost counted');
});

runCase('multiple units sorted by total_cost DESC', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'A-1');
    $unitB = seedUnit($f['pdo'], 10, 'B-1');
    seedReading($f['pdo'], $unitA, 'odometer', 0, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $unitA, 'odometer', 1000, '2026-03-30 17:00:00');
    seedReading($f['pdo'], $unitB, 'odometer', 0, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $unitB, 'odometer', 1000, '2026-03-30 17:00:00');
    seedWorkorder($f['pdo'], $unitA, '2026-03-15 10:00:00', 100.00);
    seedWorkorder($f['pdo'], $unitB, '2026-03-15 10:00:00', 5000.00);

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    assertEquals(2, count($r['rows']), 'two rows');
    assertEquals($unitB, $r['rows'][0]['fleet_unit_id'], 'expensive unit first');
    assertEquals($unitA, $r['rows'][1]['fleet_unit_id'], 'cheaper unit second');
});

runCase('totals aggregate across all units', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'T-1');
    $unitB = seedUnit($f['pdo'], 10, 'T-2');
    seedReading($f['pdo'], $unitA, 'odometer', 0, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $unitA, 'odometer', 1000, '2026-03-30 17:00:00');
    seedReading($f['pdo'], $unitB, 'odometer', 5000, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $unitB, 'odometer', 6000, '2026-03-30 17:00:00');
    seedWorkorder($f['pdo'], $unitA, '2026-03-15 10:00:00', 1000.00, 600.00, 300.00);
    seedWorkorder($f['pdo'], $unitB, '2026-03-15 10:00:00', 3000.00, 2000.00, 800.00);

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    assertEquals(4000.0, $r['totals']['total_cost'], 'sum total_cost');
    assertEquals(2600.0, $r['totals']['labor_cost'], 'sum labor_cost');
    assertEquals(1100.0, $r['totals']['parts_cost'], 'sum parts_cost');
    assertEquals(2000.0, $r['totals']['miles'], 'sum miles');
    assertEquals(2.0, $r['totals']['cost_per_mile'], 'fleet-wide cost_per_mile');
    assertEquals(2, $r['totals']['unit_count'], 'unit_count');
});

runCase('from > to rejected', function () {
    $f = makeCostReportFixture();
    try {
        $f['service']->costPerMile($f['actor'], 10, '2026-03-31', '2026-03-01');
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'from must be <= to'), 'right message');
    }
});

runCase('invalid date rejected', function () {
    $f = makeCostReportFixture();
    try {
        $f['service']->costPerMile($f['actor'], 10, 'not-a-date', '2026-03-31');
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'not a valid date'), 'right message');
    }
});

runCase('window over MAX_WINDOW_DAYS rejected', function () {
    $f = makeCostReportFixture();
    try {
        $f['service']->costPerMile($f['actor'], 10, '2010-01-01', '2026-01-01');
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'date window exceeds'), 'right message');
    }
});

runCase('zero/negative company_id rejected', function () {
    $f = makeCostReportFixture();
    try {
        $f['service']->costPerMile($f['actor'], 0, '2026-03-01', '2026-03-31');
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'company_id'), 'right message');
    }
});

runCase('fleet.view denial blocks report', function () {
    $f = makeCostReportFixture();
    $f['gate']->denials['fleet.view'] = true;
    try {
        $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
        throw new RuntimeException('expected deny');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'fleet.view'), 'right permission');
    }
});

runCase('workorder without fleet_unit_id excluded', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'F-1');
    seedReading($f['pdo'], $unitA, 'odometer', 0, '2026-03-01 08:00:00');
    seedReading($f['pdo'], $unitA, 'odometer', 1000, '2026-03-30 17:00:00');
    // Walk-in workorder not tied to a fleet unit.
    $f['pdo']->prepare('INSERT INTO workorders (number, fleet_unit_id, completed_at, grand_total) VALUES (?, NULL, ?, ?)')
        ->execute(['WO-WALKIN', '2026-03-15 10:00:00', 999.00]);
    seedWorkorder($f['pdo'], $unitA, '2026-03-15 10:00:00', 200.00);

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    assertEquals(200.0, $r['rows'][0]['total_cost'], 'walk-in WO excluded');
});

runCase('reading with zero delta keeps first+last reading metadata', function () {
    $f = makeCostReportFixture();
    $unitA = seedUnit($f['pdo'], 10, 'M-1');
    seedReading($f['pdo'], $unitA, 'odometer', 5000, '2026-03-05 08:00:00');
    seedReading($f['pdo'], $unitA, 'odometer', 5000, '2026-03-20 17:00:00'); // no movement
    seedWorkorder($f['pdo'], $unitA, '2026-03-10 10:00:00', 150.00);

    $r = $f['service']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    $row = $r['rows'][0];
    assertEquals(0.0, $row['miles'], 'delta=0');
    assertTrue($row['first_reading'] !== null, 'first_reading present');
    assertEquals(5000.0, $row['first_reading']['value'], 'first_reading value');
    assertEquals(5000.0, $row['last_reading']['value'], 'last_reading value');
});

// ---------------------------------------------------------------------------

echo "\n{$cases} cases, " . ($cases - $failures) . " passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
