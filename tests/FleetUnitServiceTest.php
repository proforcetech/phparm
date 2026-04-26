<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "FleetUnitServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FleetUnit;
use App\Models\FleetUnitAssignment;
use App\Models\FleetUnitReading;
use App\Models\Site;
use App\Models\User;
use App\Services\Crm\CompanyRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Customer\CustomerRepository;
use App\Services\Fleet\FleetUnitAssignmentRepository;
use App\Services\Fleet\FleetUnitReadingRepository;
use App\Services\Fleet\FleetUnitRepository;
use App\Services\Fleet\FleetUnitService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 7.1 of docs/expansion-plan.md — fleet maintenance. SQLite-in-memory
 * covers CRUD, UNIQUE conflicts, VIN validation, monotonic meter invariant
 * (with allow_decrease override), denormalized current_* cache updates,
 * transactional assignment open/close, cross-company reference rejection,
 * retire flow, concurrent-close race detection, and permission gating.
 */

// ---------------------------------------------------------------------------
// SQLite-backed Connection + schema
// ---------------------------------------------------------------------------

class FleetInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function fleetSetUpDatabase(): PDO
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
            updated_at TEXT NULL,
            UNIQUE (company_id, unit_number),
            UNIQUE (vin)
        )'
    );
    $pdo->exec(
        'CREATE TABLE fleet_unit_readings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fleet_unit_id INTEGER NOT NULL,
            reading_type TEXT NOT NULL,
            value REAL NOT NULL,
            recorded_at TEXT NOT NULL,
            source TEXT NOT NULL,
            workorder_id INTEGER NULL,
            notes TEXT NULL,
            recorded_by_user_id INTEGER NOT NULL,
            created_at TEXT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE fleet_unit_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fleet_unit_id INTEGER NOT NULL,
            assignment_type TEXT NOT NULL,
            assigned_user_id INTEGER NULL,
            assigned_site_id INTEGER NULL,
            customer_id INTEGER NULL,
            assigned_from TEXT NOT NULL,
            assigned_until TEXT NULL,
            notes TEXT NULL,
            created_by_user_id INTEGER NOT NULL,
            created_at TEXT NULL
        )'
    );
    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class FleetFakeAudit extends AuditLogger
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

class FleetFakeCompanies extends CompanyRepository
{
    /** @var array<int, Company> */
    public array $store = [];
    public function __construct()
    {
    }
    public function findById(int $id): ?Company
    {
        return $this->store[$id] ?? null;
    }
    public function seed(int $id, string $name = 'Acme Corp'): Company
    {
        $c = new Company();
        $c->id = $id;
        $c->name = $name;
        $this->store[$id] = $c;
        return $c;
    }
}

class FleetFakeSites extends SiteRepository
{
    /** @var array<int, Site> */
    public array $store = [];
    public function __construct()
    {
    }
    public function findById(int $id): ?Site
    {
        return $this->store[$id] ?? null;
    }
    public function seed(int $id, int $companyId, string $name = 'Main Depot'): Site
    {
        $s = new Site();
        $s->id = $id;
        $s->company_id = $companyId;
        $s->name = $name;
        $this->store[$id] = $s;
        return $s;
    }
}

class FleetFakeCustomers extends CustomerRepository
{
    /** @var array<int, Customer> */
    public array $store = [];
    public function __construct()
    {
    }
    public function find(int $id): ?Customer
    {
        return $this->store[$id] ?? null;
    }
    public function seed(int $id, ?int $companyId): Customer
    {
        $c = new Customer();
        $c->id = $id;
        $c->company_id = $companyId;
        $this->store[$id] = $c;
        return $c;
    }
}

class FleetPermissiveGate extends AccessGate
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
 * @return array{service: FleetUnitService, pdo: PDO, conn: FleetInMemoryConnection, units: FleetUnitRepository, readings: FleetUnitReadingRepository, assignments: FleetUnitAssignmentRepository, companies: FleetFakeCompanies, sites: FleetFakeSites, customers: FleetFakeCustomers, gate: FleetPermissiveGate, audit: FleetFakeAudit, actor: User, companyId: int, otherCompanyId: int}
 */
function makeFleetFixture(): array
{
    $pdo = fleetSetUpDatabase();
    $conn = new FleetInMemoryConnection($pdo);
    $audit = new FleetFakeAudit();
    $units = new FleetUnitRepository($conn);
    $readings = new FleetUnitReadingRepository($conn);
    $assignments = new FleetUnitAssignmentRepository($conn);
    $companies = new FleetFakeCompanies();
    $sites = new FleetFakeSites();
    $customers = new FleetFakeCustomers();
    $gate = new FleetPermissiveGate();

    $companies->seed(10, 'Acme Corp');
    $companies->seed(20, 'Other Corp');
    $sites->seed(100, 10, 'Acme Main');
    $sites->seed(200, 20, 'Other Main');

    $service = new FleetUnitService(
        $conn, $units, $readings, $assignments,
        $companies, $sites, $customers,
        $gate, $audit,
    );

    $actor = new User();
    $actor->id = 42;

    return [
        'service' => $service,
        'pdo' => $pdo,
        'conn' => $conn,
        'units' => $units,
        'readings' => $readings,
        'assignments' => $assignments,
        'companies' => $companies,
        'sites' => $sites,
        'customers' => $customers,
        'gate' => $gate,
        'audit' => $audit,
        'actor' => $actor,
        'companyId' => 10,
        'otherCompanyId' => 20,
    ];
}

function assertFleetThrows(callable $fn, string $cls, string $needle, string $label): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $cls)) {
            throw new RuntimeException(
                "[{$label}] expected {$cls}, got " . get_class($e) . ': ' . $e->getMessage()
            );
        }
        if ($needle !== '' && stripos($e->getMessage(), $needle) === false) {
            throw new RuntimeException(
                "[{$label}] message '{$e->getMessage()}' missing '{$needle}'"
            );
        }
        return;
    }
    throw new RuntimeException("[{$label}] expected {$cls} — none thrown");
}

function fleetAssert(bool $cond, string $label): void
{
    if (!$cond) {
        throw new RuntimeException("[{$label}] assertion failed");
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$only = $argv[1] ?? null;

function testFleet(string $name, callable $fn, ?string $only, int &$passed, int &$failed): void
{
    if ($only !== null && stripos($name, $only) === false) {
        return;
    }
    try {
        $fn();
        echo "  ✓ {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ✗ {$name}\n    " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "FleetUnitServiceTest\n";

// ── Unit CRUD ────────────────────────────────────────────────────────────

testFleet('createUnit stores unit with defaults', function (): void {
    $f = makeFleetFixture();
    $out = $f['service']->createUnit($f['actor'], $f['companyId'], [
        'unit_number' => 'T-101',
    ]);
    fleetAssert($out['id'] > 0, 'id positive');
    fleetAssert($out['unit_number'] === 'T-101', 'unit number');
    fleetAssert($out['unit_type'] === FleetUnit::TYPE_TRUCK, 'default truck');
    fleetAssert($out['meter_type'] === FleetUnit::METER_ODOMETER, 'default meter');
    fleetAssert($out['status'] === FleetUnit::STATUS_ACTIVE, 'default active');
    fleetAssert(count($f['audit']->entries) === 1, 'one audit');
    fleetAssert($f['audit']->entries[0]->event === 'fleet.unit.created', 'event');
}, $only, $passed, $failed);

testFleet('createUnit normalizes VIN to uppercase', function (): void {
    $f = makeFleetFixture();
    $out = $f['service']->createUnit($f['actor'], $f['companyId'], [
        'unit_number' => 'T-102',
        'vin' => '1hgcm82633a004352',
    ]);
    fleetAssert($out['vin'] === '1HGCM82633A004352', 'uppercased');
}, $only, $passed, $failed);

testFleet('createUnit rejects VIN with I/O/Q', function (): void {
    $f = makeFleetFixture();
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], $f['companyId'], [
            'unit_number' => 'T-103',
            'vin' => '1HGCMI2633A004352',
        ]),
        InvalidArgumentException::class,
        'vin',
        'I letter forbidden'
    );
}, $only, $passed, $failed);

testFleet('createUnit rejects over-long VIN', function (): void {
    $f = makeFleetFixture();
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], $f['companyId'], [
            'unit_number' => 'T-104',
            'vin' => str_repeat('A', 18),
        ]),
        InvalidArgumentException::class,
        'vin',
        'too long'
    );
}, $only, $passed, $failed);

testFleet('createUnit rejects bad year', function (): void {
    $f = makeFleetFixture();
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], $f['companyId'], [
            'unit_number' => 'T-105',
            'year' => 1800,
        ]),
        InvalidArgumentException::class,
        'year',
        'year range'
    );
}, $only, $passed, $failed);

testFleet('createUnit rejects bad enum values', function (): void {
    $f = makeFleetFixture();
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], $f['companyId'], [
            'unit_number' => 'T-106',
            'unit_type' => 'spaceship',
        ]),
        InvalidArgumentException::class,
        'unit_type',
        'bad type'
    );
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], $f['companyId'], [
            'unit_number' => 'T-107',
            'meter_type' => 'sundial',
        ]),
        InvalidArgumentException::class,
        'meter_type',
        'bad meter'
    );
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], $f['companyId'], [
            'unit_number' => 'T-108',
            'status' => 'on_fire',
        ]),
        InvalidArgumentException::class,
        'status',
        'bad status'
    );
}, $only, $passed, $failed);

testFleet('createUnit rejects duplicate unit_number within company', function (): void {
    $f = makeFleetFixture();
    $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'T-200']);
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'T-200']),
        InvalidArgumentException::class,
        'already in use',
        'unit_number dupe'
    );
}, $only, $passed, $failed);

testFleet('createUnit allows same unit_number in different companies', function (): void {
    $f = makeFleetFixture();
    $a = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'T-300']);
    $b = $f['service']->createUnit($f['actor'], $f['otherCompanyId'], ['unit_number' => 'T-300']);
    fleetAssert($a['id'] !== $b['id'], 'distinct ids');
    fleetAssert($a['company_id'] !== $b['company_id'], 'distinct companies');
}, $only, $passed, $failed);

testFleet('createUnit rejects duplicate VIN across companies', function (): void {
    $f = makeFleetFixture();
    $f['service']->createUnit($f['actor'], $f['companyId'], [
        'unit_number' => 'T-400', 'vin' => '1HGCM82633A999999',
    ]);
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], $f['otherCompanyId'], [
            'unit_number' => 'T-401', 'vin' => '1HGCM82633A999999',
        ]),
        InvalidArgumentException::class,
        'already in use',
        'vin dupe cross-company'
    );
}, $only, $passed, $failed);

testFleet('createUnit rejects cross-company home_site_id', function (): void {
    $f = makeFleetFixture();
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], $f['companyId'], [
            'unit_number' => 'T-500',
            'home_site_id' => 200, // belongs to company 20
        ]),
        InvalidArgumentException::class,
        'different company',
        'home_site_id leak'
    );
}, $only, $passed, $failed);

testFleet('createUnit rejects unknown company', function (): void {
    $f = makeFleetFixture();
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], 999, ['unit_number' => 'T-600']),
        InvalidArgumentException::class,
        'company',
        'unknown company'
    );
}, $only, $passed, $failed);

testFleet('updateUnit partial patch retains unchanged fields', function (): void {
    $f = makeFleetFixture();
    $created = $f['service']->createUnit($f['actor'], $f['companyId'], [
        'unit_number' => 'T-700',
        'make' => 'Ford',
        'model' => 'F-250',
        'year' => 2020,
    ]);
    $updated = $f['service']->updateUnit($f['actor'], $created['id'], [
        'model' => 'F-350',
    ]);
    fleetAssert($updated['make'] === 'Ford', 'make preserved');
    fleetAssert($updated['model'] === 'F-350', 'model updated');
    fleetAssert($updated['year'] === 2020, 'year preserved');
    fleetAssert($updated['unit_number'] === 'T-700', 'unit_number preserved');
}, $only, $passed, $failed);

testFleet('retireUnit flips status and closes current assignment', function (): void {
    $f = makeFleetFixture();
    $unit = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'T-800']);
    $f['service']->assignUnit($f['actor'], $unit['id'], [
        'assignment_type' => FleetUnitAssignment::TYPE_DRIVER,
        'assigned_user_id' => 99,
    ]);
    $f['service']->retireUnit($f['actor'], $unit['id']);

    $reloaded = $f['service']->getUnit($f['actor'], $unit['id']);
    fleetAssert($reloaded['unit']['status'] === FleetUnit::STATUS_RETIRED, 'retired status');
    fleetAssert($reloaded['current_assignment'] === null, 'assignment closed');

    $events = array_map(fn($e) => $e->event, $f['audit']->entries);
    fleetAssert(in_array('fleet.unit.retired', $events, true), 'retired event');
}, $only, $passed, $failed);

testFleet('retireUnit is idempotent', function (): void {
    $f = makeFleetFixture();
    $unit = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'T-801']);
    $f['service']->retireUnit($f['actor'], $unit['id']);
    $retireCount1 = count(array_filter($f['audit']->entries, fn($e) => $e->event === 'fleet.unit.retired'));
    $f['service']->retireUnit($f['actor'], $unit['id']);
    $retireCount2 = count(array_filter($f['audit']->entries, fn($e) => $e->event === 'fleet.unit.retired'));
    fleetAssert($retireCount1 === 1, 'first call audits');
    fleetAssert($retireCount2 === 1, 'second call does not re-audit');
}, $only, $passed, $failed);

testFleet('listForCompany filters and paginates', function (): void {
    $f = makeFleetFixture();
    for ($i = 1; $i <= 5; $i++) {
        $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => "A-{$i}"]);
    }
    $f['service']->createUnit($f['actor'], $f['otherCompanyId'], ['unit_number' => 'B-1']);

    $list = $f['service']->listForCompany($f['actor'], $f['companyId'], ['limit' => 3, 'offset' => 0]);
    fleetAssert($list['total'] === 5, 'total 5');
    fleetAssert(count($list['data']) === 3, 'page 3');

    $search = $f['service']->listForCompany($f['actor'], $f['companyId'], ['query' => 'A-2']);
    fleetAssert($search['total'] === 1, 'query 1 match');
}, $only, $passed, $failed);

testFleet('requireUnit rejects zero/negative ids', function (): void {
    $f = makeFleetFixture();
    assertFleetThrows(
        fn() => $f['service']->getUnit($f['actor'], 0),
        InvalidArgumentException::class,
        'required',
        'zero id'
    );
    assertFleetThrows(
        fn() => $f['service']->getUnit($f['actor'], 99999),
        InvalidArgumentException::class,
        'not found',
        'missing id'
    );
}, $only, $passed, $failed);

// ── Readings ─────────────────────────────────────────────────────────────

testFleet('recordReading inserts and bumps odometer cache', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'R-1']);
    $reading = $f['service']->recordReading($f['actor'], $u['id'], [
        'reading_type' => FleetUnitReading::TYPE_ODOMETER,
        'value' => 12345,
        'recorded_at' => '2025-01-15 10:00:00',
    ]);
    fleetAssert($reading['value'] == 12345, 'value stored');
    $reloaded = $f['service']->getUnit($f['actor'], $u['id'])['unit'];
    fleetAssert($reloaded['current_odometer'] === 12345, 'odometer cache');
    fleetAssert($reloaded['current_engine_hours'] === null, 'engine hours untouched');
    fleetAssert($reloaded['odometer_last_read_at'] === '2025-01-15 10:00:00', 'od read at');
}, $only, $passed, $failed);

testFleet('recordReading engine_hours bumps only engine cache', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], [
        'unit_number' => 'R-2', 'meter_type' => FleetUnit::METER_BOTH,
    ]);
    $f['service']->recordReading($f['actor'], $u['id'], [
        'reading_type' => FleetUnitReading::TYPE_ENGINE_HOURS,
        'value' => 1234.5,
        'recorded_at' => '2025-01-16 10:00:00',
    ]);
    $reloaded = $f['service']->getUnit($f['actor'], $u['id'])['unit'];
    fleetAssert((float) $reloaded['current_engine_hours'] === 1234.5, 'engine hours stored');
    fleetAssert($reloaded['current_odometer'] === null, 'odometer untouched');
    fleetAssert($reloaded['engine_hours_last_read_at'] === '2025-01-16 10:00:00', 'eh read at');
}, $only, $passed, $failed);

testFleet('recordReading rejects backwards odometer by default', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'R-3']);
    $f['service']->recordReading($f['actor'], $u['id'], [
        'reading_type' => FleetUnitReading::TYPE_ODOMETER,
        'value' => 50000,
        'recorded_at' => '2025-01-10 10:00:00',
    ]);
    assertFleetThrows(
        fn() => $f['service']->recordReading($f['actor'], $u['id'], [
            'reading_type' => FleetUnitReading::TYPE_ODOMETER,
            'value' => 49999,
            'recorded_at' => '2025-01-11 10:00:00',
        ]),
        InvalidArgumentException::class,
        'lower',
        'monotonic'
    );
}, $only, $passed, $failed);

testFleet('recordReading allow_decrease override accepts lower value', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'R-4']);
    $f['service']->recordReading($f['actor'], $u['id'], [
        'reading_type' => FleetUnitReading::TYPE_ODOMETER,
        'value' => 99999,
        'recorded_at' => '2025-01-10 10:00:00',
    ]);
    $out = $f['service']->recordReading($f['actor'], $u['id'], [
        'reading_type' => FleetUnitReading::TYPE_ODOMETER,
        'value' => 100,
        'recorded_at' => '2025-01-11 10:00:00',
        'allow_decrease' => true,
        'notes' => 'meter replaced',
    ]);
    fleetAssert($out['value'] == 100, 'decreased accepted');
    $audits = array_filter($f['audit']->entries, fn($e) => $e->event === 'fleet.reading.recorded');
    $last = end($audits);
    fleetAssert($last->context['allow_decrease'] === true, 'allow_decrease audited');
}, $only, $passed, $failed);

testFleet('recordReading rejects missing required fields', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'R-5']);
    assertFleetThrows(
        fn() => $f['service']->recordReading($f['actor'], $u['id'], [
            'reading_type' => FleetUnitReading::TYPE_ODOMETER,
            'recorded_at' => '2025-01-11 10:00:00',
        ]),
        InvalidArgumentException::class,
        'value',
        'missing value'
    );
    assertFleetThrows(
        fn() => $f['service']->recordReading($f['actor'], $u['id'], [
            'reading_type' => FleetUnitReading::TYPE_ODOMETER,
            'value' => 100,
        ]),
        InvalidArgumentException::class,
        'recorded_at',
        'missing recorded_at'
    );
    assertFleetThrows(
        fn() => $f['service']->recordReading($f['actor'], $u['id'], [
            'reading_type' => 'psi',
            'value' => 100,
            'recorded_at' => '2025-01-11 10:00:00',
        ]),
        InvalidArgumentException::class,
        'reading_type',
        'bad reading_type'
    );
}, $only, $passed, $failed);

testFleet('recordReading rejects negative value', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'R-6']);
    assertFleetThrows(
        fn() => $f['service']->recordReading($f['actor'], $u['id'], [
            'reading_type' => FleetUnitReading::TYPE_ODOMETER,
            'value' => -5,
            'recorded_at' => '2025-01-11 10:00:00',
        ]),
        InvalidArgumentException::class,
        'non-negative',
        'negative'
    );
}, $only, $passed, $failed);

testFleet('listReadings returns readings in descending order', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'R-7']);
    $f['service']->recordReading($f['actor'], $u['id'], [
        'reading_type' => FleetUnitReading::TYPE_ODOMETER,
        'value' => 100, 'recorded_at' => '2025-01-10 10:00:00',
    ]);
    $f['service']->recordReading($f['actor'], $u['id'], [
        'reading_type' => FleetUnitReading::TYPE_ODOMETER,
        'value' => 200, 'recorded_at' => '2025-01-11 10:00:00',
    ]);
    $readings = $f['service']->listReadings($f['actor'], $u['id'], null, 100);
    fleetAssert(count($readings) === 2, 'two readings');
    fleetAssert($readings[0]['value'] == 200, 'newest first');
}, $only, $passed, $failed);

// ── Assignments ──────────────────────────────────────────────────────────

testFleet('assignUnit opens first assignment', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'AS-1']);
    $a = $f['service']->assignUnit($f['actor'], $u['id'], [
        'assignment_type' => FleetUnitAssignment::TYPE_DRIVER,
        'assigned_user_id' => 77,
    ]);
    fleetAssert($a['assignment_type'] === FleetUnitAssignment::TYPE_DRIVER, 'driver type');
    fleetAssert($a['assigned_user_id'] === 77, 'user id');
    fleetAssert($a['is_current'] === true, 'is_current');
    fleetAssert($a['assigned_until'] === null, 'assigned_until null');
}, $only, $passed, $failed);

testFleet('assignUnit closes prior assignment atomically', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'AS-2']);
    $a1 = $f['service']->assignUnit($f['actor'], $u['id'], [
        'assignment_type' => FleetUnitAssignment::TYPE_DRIVER,
        'assigned_user_id' => 10,
        'assigned_from' => '2025-01-01 10:00:00',
    ]);
    $a2 = $f['service']->assignUnit($f['actor'], $u['id'], [
        'assignment_type' => FleetUnitAssignment::TYPE_DRIVER,
        'assigned_user_id' => 20,
        'assigned_from' => '2025-02-01 10:00:00',
    ]);

    $all = $f['service']->listAssignments($f['actor'], $u['id']);
    fleetAssert(count($all) === 2, 'two rows');
    fleetAssert($all[0]['is_current'] === true, 'latest is current');
    fleetAssert($all[1]['is_current'] === false, 'prior closed');
    fleetAssert($all[1]['assigned_until'] === '2025-02-01 10:00:00', 'closed at new start');
    fleetAssert($all[0]['id'] === $a2['id'], 'newest id ordering');
    fleetAssert($all[1]['id'] === $a1['id'], 'prior id ordering');
}, $only, $passed, $failed);

testFleet('assignUnit requires target field matching assignment_type', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'AS-3']);
    assertFleetThrows(
        fn() => $f['service']->assignUnit($f['actor'], $u['id'], [
            'assignment_type' => FleetUnitAssignment::TYPE_DRIVER,
            // no assigned_user_id
        ]),
        InvalidArgumentException::class,
        'assigned_user_id',
        'driver needs user'
    );
    assertFleetThrows(
        fn() => $f['service']->assignUnit($f['actor'], $u['id'], [
            'assignment_type' => FleetUnitAssignment::TYPE_SITE,
            // no assigned_site_id
        ]),
        InvalidArgumentException::class,
        'assigned_site_id',
        'site needs site'
    );
    assertFleetThrows(
        fn() => $f['service']->assignUnit($f['actor'], $u['id'], [
            'assignment_type' => FleetUnitAssignment::TYPE_CUSTOMER_RENTAL,
            // no customer_id
        ]),
        InvalidArgumentException::class,
        'customer_id',
        'rental needs customer'
    );
}, $only, $passed, $failed);

testFleet('assignUnit rejects cross-company site', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'AS-4']);
    assertFleetThrows(
        fn() => $f['service']->assignUnit($f['actor'], $u['id'], [
            'assignment_type' => FleetUnitAssignment::TYPE_SITE,
            'assigned_site_id' => 200, // belongs to company 20
        ]),
        InvalidArgumentException::class,
        'different company',
        'site leak'
    );
}, $only, $passed, $failed);

testFleet('assignUnit rejects cross-company customer', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'AS-5']);
    $f['customers']->seed(500, 20); // customer for other company

    assertFleetThrows(
        fn() => $f['service']->assignUnit($f['actor'], $u['id'], [
            'assignment_type' => FleetUnitAssignment::TYPE_CUSTOMER_RENTAL,
            'customer_id' => 500,
        ]),
        InvalidArgumentException::class,
        'different company',
        'customer leak'
    );
}, $only, $passed, $failed);

testFleet('assignUnit accepts same-company customer rental', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'AS-6']);
    $f['customers']->seed(600, $f['companyId']);
    $out = $f['service']->assignUnit($f['actor'], $u['id'], [
        'assignment_type' => FleetUnitAssignment::TYPE_CUSTOMER_RENTAL,
        'customer_id' => 600,
    ]);
    fleetAssert($out['customer_id'] === 600, 'customer attached');
}, $only, $passed, $failed);

testFleet('endAssignment stamps current row', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'AS-7']);
    $f['service']->assignUnit($f['actor'], $u['id'], [
        'assignment_type' => FleetUnitAssignment::TYPE_DRIVER,
        'assigned_user_id' => 1,
    ]);
    $f['service']->endAssignment($f['actor'], $u['id'], '2025-03-01 12:00:00');
    $current = $f['service']->getUnit($f['actor'], $u['id'])['current_assignment'];
    fleetAssert($current === null, 'no current');
    $all = $f['service']->listAssignments($f['actor'], $u['id']);
    fleetAssert($all[0]['assigned_until'] === '2025-03-01 12:00:00', 'stamped');
}, $only, $passed, $failed);

testFleet('endAssignment rejects when no active row', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'AS-8']);
    assertFleetThrows(
        fn() => $f['service']->endAssignment($f['actor'], $u['id'], null),
        InvalidArgumentException::class,
        'no active',
        'no assignment'
    );
}, $only, $passed, $failed);

// ── Permission gating ───────────────────────────────────────────────────

testFleet('fleet.manage denial blocks writes', function (): void {
    $f = makeFleetFixture();
    $f['gate']->denials['fleet.manage'] = true;
    assertFleetThrows(
        fn() => $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'P-1']),
        UnauthorizedException::class,
        'fleet.manage',
        'create blocked'
    );
}, $only, $passed, $failed);

testFleet('fleet.view denial blocks reads', function (): void {
    $f = makeFleetFixture();
    $u = $f['service']->createUnit($f['actor'], $f['companyId'], ['unit_number' => 'P-2']);
    $f['gate']->denials['fleet.view'] = true;
    assertFleetThrows(
        fn() => $f['service']->getUnit($f['actor'], $u['id']),
        UnauthorizedException::class,
        'fleet.view',
        'get blocked'
    );
    assertFleetThrows(
        fn() => $f['service']->listForCompany($f['actor'], $f['companyId'], []),
        UnauthorizedException::class,
        'fleet.view',
        'list blocked'
    );
}, $only, $passed, $failed);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
