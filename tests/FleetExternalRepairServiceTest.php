<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "FleetExternalRepairServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\FleetExternalRepair;
use App\Models\User;
use App\Services\Fleet\FleetCostReportRepository;
use App\Services\Fleet\FleetCostReportService;
use App\Services\Fleet\FleetExternalRepairRepository;
use App\Services\Fleet\FleetExternalRepairService;
use App\Services\Fleet\FleetUnitReadingRepository;
use App\Services\Fleet\FleetUnitRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 7.5 of docs/expansion-plan.md — external vendor repair logs.
 * SQLite-in-memory covers CRUD lifecycle, validation, reading
 * auto-record behavior (monotonic guard + skip-on-older), cost-report
 * integration when include_external=true, cross-company filtering,
 * and permission gating.
 */

// ---------------------------------------------------------------------------
// SQLite connection + schema
// ---------------------------------------------------------------------------

class ExtRepairInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function extRepairSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE fleet_units (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            unit_number TEXT NOT NULL,
            unit_type TEXT NOT NULL DEFAULT "truck",
            vin TEXT NULL,
            year INTEGER NULL,
            make TEXT NULL,
            model TEXT NULL,
            trim TEXT NULL,
            license_plate TEXT NULL,
            home_site_id INTEGER NULL,
            meter_type TEXT NOT NULL DEFAULT "odometer",
            current_odometer INTEGER NULL,
            current_engine_hours REAL NULL,
            odometer_last_read_at TEXT NULL,
            engine_hours_last_read_at TEXT NULL,
            status TEXT NOT NULL DEFAULT "active",
            notes TEXT NULL,
            created_by_user_id INTEGER NOT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
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
        'CREATE TABLE fleet_external_repairs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fleet_unit_id INTEGER NOT NULL,
            vendor_name TEXT NOT NULL,
            vendor_invoice_number TEXT NULL,
            category TEXT NOT NULL DEFAULT "repair",
            service_date TEXT NOT NULL,
            description TEXT NOT NULL,
            labor_cost REAL NOT NULL DEFAULT 0,
            parts_cost REAL NOT NULL DEFAULT 0,
            other_cost REAL NOT NULL DEFAULT 0,
            total_cost REAL NOT NULL DEFAULT 0,
            odometer_at_service INTEGER NULL,
            engine_hours_at_service REAL NULL,
            notes TEXT NULL,
            attachment_path TEXT NULL,
            created_by_user_id INTEGER NOT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )'
    );
    // Cost-report integration test hits these tables too.
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

class ExtRepairFakeAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public function __construct()
    {
    }
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class ExtRepairPermissiveGate extends AccessGate
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
// Fixture
// ---------------------------------------------------------------------------

/**
 * @return array{
 *   service: FleetExternalRepairService,
 *   reportService: FleetCostReportService,
 *   pdo: PDO,
 *   units: FleetUnitRepository,
 *   repairs: FleetExternalRepairRepository,
 *   readings: FleetUnitReadingRepository,
 *   gate: ExtRepairPermissiveGate,
 *   audit: ExtRepairFakeAudit,
 *   actor: User,
 *   unitId: int,
 *   otherCompanyUnitId: int
 * }
 */
function makeExtRepairFixture(): array
{
    $pdo = extRepairSetUpDatabase();
    $conn = new ExtRepairInMemoryConnection($pdo);
    $audit = new ExtRepairFakeAudit();
    $gate = new ExtRepairPermissiveGate();

    $units = new FleetUnitRepository($conn);
    $readings = new FleetUnitReadingRepository($conn);
    $repairs = new FleetExternalRepairRepository($conn);
    $service = new FleetExternalRepairService($conn, $units, $repairs, $readings, $gate, $audit);

    $costReportRepo = new FleetCostReportRepository($conn);
    $reportService = new FleetCostReportService($costReportRepo, $gate, $repairs);

    $pdo->exec(
        "INSERT INTO fleet_units (company_id, unit_number, status, created_by_user_id)
         VALUES (10, 'T-1', 'active', 1), (20, 'OTHER-1', 'active', 1)"
    );
    $unitId = (int) $pdo->query("SELECT id FROM fleet_units WHERE unit_number = 'T-1'")->fetchColumn();
    $otherUnitId = (int) $pdo->query("SELECT id FROM fleet_units WHERE unit_number = 'OTHER-1'")->fetchColumn();

    $actor = new User();
    $actor->id = 11;

    return [
        'service' => $service,
        'reportService' => $reportService,
        'pdo' => $pdo,
        'units' => $units,
        'repairs' => $repairs,
        'readings' => $readings,
        'gate' => $gate,
        'audit' => $audit,
        'actor' => $actor,
        'unitId' => $unitId,
        'otherCompanyUnitId' => $otherUnitId,
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

// ---------------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------------

echo "FleetExternalRepairServiceTest\n";

runCase('createRepair happy path + defaults + audit', function () {
    $f = makeExtRepairFixture();
    $out = $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'Acme Diesel',
        'vendor_invoice_number' => 'INV-2026-001',
        'service_date' => '2026-03-15',
        'description' => 'Transmission rebuild',
        'labor_cost' => 1200.00,
        'parts_cost' => 3400.00,
        'other_cost' => 100.00,
        'total_cost' => 4700.00,
    ]);
    assertEquals('Acme Diesel', $out['vendor_name'], 'vendor echoed');
    assertEquals('repair', $out['category'], 'category defaults to repair');
    assertEquals(4700.00, $out['total_cost'], 'total_cost stored');
    assertEquals('2026-03-15', $out['service_date'], 'service_date stored');
    assertTrue(count($f['audit']->entries) === 1, 'audit emitted');
    assertEquals('fleet.external_repair.created', $f['audit']->entries[0]->action, 'audit action');
});

runCase('total_cost auto-computed from labor+parts+other when omitted', function () {
    $f = makeExtRepairFixture();
    $out = $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'Quick Lube',
        'service_date' => '2026-03-01',
        'description' => 'Oil change + filter',
        'labor_cost' => 40.00,
        'parts_cost' => 55.00,
        'other_cost' => 5.00,
    ]);
    assertEquals(100.00, $out['total_cost'], 'total_cost = 40+55+5');
});

runCase('total_cost mismatch with split rejected', function () {
    $f = makeExtRepairFixture();
    try {
        $f['service']->createRepair($f['actor'], $f['unitId'], [
            'vendor_name' => 'Sketchy Shop',
            'service_date' => '2026-03-01',
            'description' => 'xxx',
            'labor_cost' => 100.0,
            'parts_cost' => 0.0,
            'other_cost' => 0.0,
            'total_cost' => 999.99,
        ]);
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'total_cost must equal'), 'right message');
    }
});

runCase('unknown category rejected', function () {
    $f = makeExtRepairFixture();
    try {
        $f['service']->createRepair($f['actor'], $f['unitId'], [
            'vendor_name' => 'V',
            'service_date' => '2026-03-01',
            'description' => 'd',
            'category' => 'banana',
            'labor_cost' => 10,
            'parts_cost' => 0,
            'other_cost' => 0,
            'total_cost' => 10,
        ]);
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'category must be one of'), 'right message');
    }
});

runCase('missing required fields rejected', function () {
    $f = makeExtRepairFixture();
    foreach (['vendor_name', 'description', 'service_date'] as $field) {
        $body = [
            'vendor_name' => 'X', 'description' => 'd', 'service_date' => '2026-03-01',
            'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
        ];
        unset($body[$field]);
        try {
            $f['service']->createRepair($f['actor'], $f['unitId'], $body);
            throw new RuntimeException("expected reject for missing {$field}");
        } catch (InvalidArgumentException $e) {
            assertTrue(str_contains($e->getMessage(), $field), "mentions {$field}");
        }
    }
});

runCase('negative labor/parts/other cost rejected', function () {
    $f = makeExtRepairFixture();
    try {
        $f['service']->createRepair($f['actor'], $f['unitId'], [
            'vendor_name' => 'V', 'service_date' => '2026-03-01', 'description' => 'd',
            'labor_cost' => -10, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => -10,
        ]);
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'labor_cost must be >= 0'), 'right message');
    }
});

runCase('odometer_at_service auto-records a reading when newer', function () {
    $f = makeExtRepairFixture();
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'V', 'service_date' => '2026-03-10', 'description' => 'd',
        'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
        'odometer_at_service' => 85000,
    ]);
    $readings = $f['readings']->listForUnit($f['unitId'], 'odometer');
    assertEquals(1, count($readings), 'reading auto-created');
    assertEquals(85000.0, $readings[0]->value, 'reading value');
    assertEquals('import', $readings[0]->source, 'source=import');
    $unit = $f['units']->findById($f['unitId']);
    assertEquals(85000, $unit->current_odometer, 'meter cache bumped');
});

runCase('older odometer skipped (monotonic guard)', function () {
    $f = makeExtRepairFixture();
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'V', 'service_date' => '2026-03-10', 'description' => 'd',
        'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
        'odometer_at_service' => 90000,
    ]);
    // Late-arriving invoice with older reading — should skip silently.
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'V2', 'service_date' => '2026-02-01', 'description' => 'd',
        'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
        'odometer_at_service' => 80000,
    ]);
    $readings = $f['readings']->listForUnit($f['unitId'], 'odometer');
    assertEquals(1, count($readings), 'only first reading recorded');
    assertEquals(90000.0, $readings[0]->value, 'newest value preserved');
});

runCase('engine_hours_at_service auto-records separate reading', function () {
    $f = makeExtRepairFixture();
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'V', 'service_date' => '2026-03-10', 'description' => 'd',
        'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
        'odometer_at_service' => 10000,
        'engine_hours_at_service' => 550.5,
    ]);
    $odom = $f['readings']->listForUnit($f['unitId'], 'odometer');
    $hours = $f['readings']->listForUnit($f['unitId'], 'engine_hours');
    assertEquals(1, count($odom), 'odometer reading');
    assertEquals(1, count($hours), 'engine_hours reading');
    assertEquals(550.5, $hours[0]->value, 'hours value');
});

runCase('updateRepair partial patch preserves unchanged fields', function () {
    $f = makeExtRepairFixture();
    $created = $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'Acme', 'service_date' => '2026-03-01', 'description' => 'd',
        'labor_cost' => 100, 'parts_cost' => 200, 'other_cost' => 0, 'total_cost' => 300,
    ]);
    $updated = $f['service']->updateRepair($f['actor'], $created['id'], [
        'description' => 'Updated description',
    ]);
    assertEquals('Updated description', $updated['description'], 'description patched');
    assertEquals('Acme', $updated['vendor_name'], 'vendor preserved');
    assertEquals(300.00, $updated['total_cost'], 'total preserved');
});

runCase('updateRepair unknown id rejected', function () {
    $f = makeExtRepairFixture();
    try {
        $f['service']->updateRepair($f['actor'], 9999, ['description' => 'x']);
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), '9999 not found'), 'right message');
    }
});

runCase('deleteRepair removes row + audits + is idempotent', function () {
    $f = makeExtRepairFixture();
    $created = $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'V', 'service_date' => '2026-03-01', 'description' => 'd',
        'labor_cost' => 50, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 50,
    ]);
    $f['service']->deleteRepair($f['actor'], $created['id']);
    assertTrue($f['repairs']->findById($created['id']) === null, 'row deleted');
    // Second call is a no-op (idempotent).
    $f['service']->deleteRepair($f['actor'], $created['id']);
    assertEquals(2, count($f['audit']->entries), 'create + delete audits (repeat delete no-op)');
});

runCase('listForUnit newest-first by service_date', function () {
    $f = makeExtRepairFixture();
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'Old', 'service_date' => '2026-01-15', 'description' => 'd',
        'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
    ]);
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'New', 'service_date' => '2026-03-20', 'description' => 'd',
        'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
    ]);
    $list = $f['service']->listForUnit($f['actor'], $f['unitId']);
    assertEquals(2, count($list), 'two entries');
    assertEquals('New', $list[0]['vendor_name'], 'newest first');
    assertEquals('Old', $list[1]['vendor_name'], 'oldest last');
});

runCase('listForCompany filters by vendor + category + date range', function () {
    $f = makeExtRepairFixture();
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'Acme Diesel', 'service_date' => '2026-03-01', 'description' => 'd',
        'category' => 'repair',
        'labor_cost' => 100, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 100,
    ]);
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'Tire Town', 'service_date' => '2026-04-01', 'description' => 'd',
        'category' => 'tires',
        'labor_cost' => 50, 'parts_cost' => 800, 'other_cost' => 0, 'total_cost' => 850,
    ]);
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'Acme Diesel', 'service_date' => '2026-05-01', 'description' => 'd',
        'category' => 'maintenance',
        'labor_cost' => 50, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 50,
    ]);
    $filtered = $f['service']->listForCompany($f['actor'], 10, ['vendor' => 'Acme']);
    assertEquals(2, count($filtered), 'two Acme entries');
    $cat = $f['service']->listForCompany($f['actor'], 10, ['category' => 'tires']);
    assertEquals(1, count($cat), 'one tires entry');
    $dateFilter = $f['service']->listForCompany($f['actor'], 10, [
        'from' => '2026-04-01', 'to' => '2026-04-30',
    ]);
    assertEquals(1, count($dateFilter), 'only April entry in range');
});

runCase('cross-company leak blocked by listForCompany', function () {
    $f = makeExtRepairFixture();
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'Mine', 'service_date' => '2026-03-01', 'description' => 'd',
        'labor_cost' => 100, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 100,
    ]);
    $f['service']->createRepair($f['actor'], $f['otherCompanyUnitId'], [
        'vendor_name' => 'Theirs', 'service_date' => '2026-03-01', 'description' => 'd',
        'labor_cost' => 500, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 500,
    ]);
    $list = $f['service']->listForCompany($f['actor'], 10);
    assertEquals(1, count($list), 'only my company');
    assertEquals('Mine', $list[0]['vendor_name'], 'right vendor');
});

runCase('unknown unit id rejected on create', function () {
    $f = makeExtRepairFixture();
    try {
        $f['service']->createRepair($f['actor'], 99999, [
            'vendor_name' => 'V', 'service_date' => '2026-03-01', 'description' => 'd',
            'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
        ]);
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'not found'), 'right message');
    }
});

runCase('fleet.manage denial blocks writes', function () {
    $f = makeExtRepairFixture();
    $f['gate']->denials['fleet.manage'] = true;
    try {
        $f['service']->createRepair($f['actor'], $f['unitId'], [
            'vendor_name' => 'V', 'service_date' => '2026-03-01', 'description' => 'd',
            'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
        ]);
        throw new RuntimeException('expected deny');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'fleet.manage'), 'right permission');
    }
});

runCase('fleet.view denial blocks reads', function () {
    $f = makeExtRepairFixture();
    $f['gate']->denials['fleet.view'] = true;
    try {
        $f['service']->listForUnit($f['actor'], $f['unitId']);
        throw new RuntimeException('expected deny');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'fleet.view'), 'right permission');
    }
});

runCase('cost report include_external unions external cost into totals', function () {
    $f = makeExtRepairFixture();
    // Internal workorder via raw inserts.
    $f['pdo']->prepare('INSERT INTO workorders (number, fleet_unit_id, completed_at, grand_total) VALUES (?, ?, ?, ?)')
        ->execute(['WO-1', $f['unitId'], '2026-03-15 10:00:00', 500.00]);
    $f['pdo']->exec('INSERT INTO workorder_jobs (workorder_id) VALUES (1)');
    $f['pdo']->exec('INSERT INTO workorder_items (workorder_job_id, type, line_total) VALUES (1, "LABOR", 300)');
    $f['pdo']->exec('INSERT INTO workorder_items (workorder_job_id, type, line_total) VALUES (1, "PART", 200)');
    // External repair.
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'Acme', 'service_date' => '2026-03-20', 'description' => 'd',
        'labor_cost' => 100, 'parts_cost' => 200, 'other_cost' => 50, 'total_cost' => 350,
    ]);
    // Readings for miles delta.
    $f['pdo']->prepare('INSERT INTO fleet_unit_readings
        (fleet_unit_id, reading_type, value, recorded_at, source, recorded_by_user_id)
        VALUES (?, "odometer", ?, ?, "manual", 1)')->execute([$f['unitId'], 1000, '2026-03-01 08:00:00']);
    $f['pdo']->prepare('INSERT INTO fleet_unit_readings
        (fleet_unit_id, reading_type, value, recorded_at, source, recorded_by_user_id)
        VALUES (?, "odometer", ?, ?, "manual", 1)')->execute([$f['unitId'], 2000, '2026-03-30 17:00:00']);

    // Without include_external.
    $report = $f['reportService']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31');
    assertEquals(500.00, $report['rows'][0]['total_cost'], 'internal-only total');

    // With include_external.
    $report2 = $f['reportService']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31', true);
    assertEquals(850.00, $report2['rows'][0]['total_cost'], 'internal + external');
    assertEquals(500.00, $report2['rows'][0]['internal_cost'], 'internal split');
    assertEquals(350.00, $report2['rows'][0]['external_cost'], 'external split');
    assertEquals(50.00, $report2['rows'][0]['external_other_cost'], 'other_cost surfaced');
    assertEquals(1, $report2['rows'][0]['external_repair_count'], 'repair_count');
});

runCase('cost report include_external surfaces unit with only external cost', function () {
    $f = makeExtRepairFixture();
    // No workorders. Only an external repair + readings.
    $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'Ext Only', 'service_date' => '2026-03-15', 'description' => 'd',
        'labor_cost' => 400, 'parts_cost' => 100, 'other_cost' => 0, 'total_cost' => 500,
    ]);
    $f['pdo']->prepare('INSERT INTO fleet_unit_readings
        (fleet_unit_id, reading_type, value, recorded_at, source, recorded_by_user_id)
        VALUES (?, "odometer", ?, ?, "manual", 1)')->execute([$f['unitId'], 0, '2026-03-01 08:00:00']);
    $f['pdo']->prepare('INSERT INTO fleet_unit_readings
        (fleet_unit_id, reading_type, value, recorded_at, source, recorded_by_user_id)
        VALUES (?, "odometer", ?, ?, "manual", 1)')->execute([$f['unitId'], 500, '2026-03-30 17:00:00']);

    $report = $f['reportService']->costPerMile($f['actor'], 10, '2026-03-01', '2026-03-31', true);
    assertEquals(1, count($report['rows']), 'unit appears via external cost alone');
    assertEquals(500.00, $report['rows'][0]['total_cost'], 'external-only total');
    assertEquals(1.0, $report['rows'][0]['cost_per_mile'], 'cpm = 500/500');
});

runCase('service_date invalid date rejected', function () {
    $f = makeExtRepairFixture();
    try {
        $f['service']->createRepair($f['actor'], $f['unitId'], [
            'vendor_name' => 'V', 'service_date' => 'xyz', 'description' => 'd',
            'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
        ]);
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'not a valid date'), 'right message');
    }
});

runCase('vendor_name over 120 chars rejected', function () {
    $f = makeExtRepairFixture();
    try {
        $f['service']->createRepair($f['actor'], $f['unitId'], [
            'vendor_name' => str_repeat('x', 121),
            'service_date' => '2026-03-01', 'description' => 'd',
            'labor_cost' => 0, 'parts_cost' => 0, 'other_cost' => 0, 'total_cost' => 0,
        ]);
        throw new RuntimeException('expected reject');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'vendor_name exceeds'), 'right message');
    }
});

runCase('getRepair returns serialized row with all fields', function () {
    $f = makeExtRepairFixture();
    $c = $f['service']->createRepair($f['actor'], $f['unitId'], [
        'vendor_name' => 'G', 'service_date' => '2026-03-01', 'description' => 'd',
        'labor_cost' => 10, 'parts_cost' => 20, 'other_cost' => 0, 'total_cost' => 30,
        'vendor_invoice_number' => 'IV-9',
        'odometer_at_service' => 50000,
    ]);
    $fetched = $f['service']->getRepair($f['actor'], $c['id']);
    assertEquals('G', $fetched['vendor_name'], 'vendor');
    assertEquals('IV-9', $fetched['vendor_invoice_number'], 'invoice number');
    assertEquals(50000, $fetched['odometer_at_service'], 'odometer');
});

// ---------------------------------------------------------------------------

echo "\n{$cases} cases, " . ($cases - $failures) . " passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
