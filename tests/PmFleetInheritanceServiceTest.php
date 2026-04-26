<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "PmFleetInheritanceServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\FleetUnit;
use App\Models\FleetUnitReading;
use App\Models\PmFleetBinding;
use App\Models\User;
use App\Services\Fleet\FleetUnitRepository;
use App\Services\Pm\PmFleetBindingRepository;
use App\Services\Pm\PmFleetInheritanceService;
use App\Services\Pm\PmFrequencyService;
use App\Services\Pm\PmPlanRepository;
use App\Services\Pm\PmScheduleRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 7.2 of docs/expansion-plan.md — PM fleet inheritance. SQLite-in-memory
 * covers: binding CRUD + validation, cross-company rejection, scope-coherence
 * rules, ensureSchedulesForUnit unit-specific precedence + idempotency,
 * retired-unit no-op, onReadingRecorded miles-triggered advance, onReadingRecorded
 * engine-hours advance, unit-mismatch no-op, and permission gating.
 */

// ---------------------------------------------------------------------------
// SQLite schema + connection
// ---------------------------------------------------------------------------

class PmFleetInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function pmFleetSetUpDatabase(): PDO
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
        'CREATE TABLE pm_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NULL,
            division_id INTEGER NULL,
            title TEXT NOT NULL,
            description TEXT NULL,
            default_priority TEXT NOT NULL DEFAULT "p3_normal",
            estimated_duration_minutes INTEGER NULL,
            checklist_json TEXT NULL,
            default_category_id INTEGER NULL,
            default_queue_id INTEGER NULL,
            default_assigned_user_id INTEGER NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by_user_id INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE pm_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            plan_id INTEGER NOT NULL,
            company_id INTEGER NOT NULL,
            site_id INTEGER NULL,
            asset_id INTEGER NULL,
            fleet_unit_id INTEGER NULL,
            frequency_kind TEXT NOT NULL DEFAULT "fixed_interval",
            frequency_config TEXT NULL,
            starts_at TEXT NOT NULL,
            next_due_at TEXT NULL,
            last_generated_at TEXT NULL,
            lead_time_days INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT "active",
            contract_id INTEGER NULL,
            contract_entitlement_id INTEGER NULL,
            notes TEXT NULL,
            created_by_user_id INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE pm_fleet_bindings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            plan_id INTEGER NOT NULL,
            company_id INTEGER NOT NULL,
            scope_type TEXT NOT NULL,
            fleet_unit_id INTEGER NULL,
            unit_type TEXT NULL,
            frequency_kind TEXT NOT NULL DEFAULT "meter",
            frequency_config TEXT NULL,
            lead_time_days INTEGER NOT NULL DEFAULT 0,
            starts_at TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_by_user_id INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )'
    );
    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class PmFleetFakeAudit extends AuditLogger
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

class PmFleetPermissiveGate extends AccessGate
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
 *   service: PmFleetInheritanceService,
 *   pdo: PDO,
 *   units: FleetUnitRepository,
 *   bindings: PmFleetBindingRepository,
 *   schedules: PmScheduleRepository,
 *   plans: PmPlanRepository,
 *   gate: PmFleetPermissiveGate,
 *   audit: PmFleetFakeAudit,
 *   actor: User,
 *   companyId: int,
 *   otherCompanyId: int
 * }
 */
function makePmFleetFixture(): array
{
    $pdo = pmFleetSetUpDatabase();
    $conn = new PmFleetInMemoryConnection($pdo);
    $audit = new PmFleetFakeAudit();
    $gate = new PmFleetPermissiveGate();

    $units = new FleetUnitRepository($conn);
    $bindings = new PmFleetBindingRepository($conn);
    $schedules = new PmScheduleRepository($conn);
    $plans = new PmPlanRepository($conn);
    $freq = new PmFrequencyService();

    $service = new PmFleetInheritanceService(
        $conn, $bindings, $schedules, $plans, $units, $freq, $gate, $audit,
    );

    $actor = new User();
    $actor->id = 42;

    return [
        'service' => $service,
        'pdo' => $pdo,
        'units' => $units,
        'bindings' => $bindings,
        'schedules' => $schedules,
        'plans' => $plans,
        'gate' => $gate,
        'audit' => $audit,
        'actor' => $actor,
        'companyId' => 10,
        'otherCompanyId' => 20,
    ];
}

function seedPlan(PDO $pdo, int $companyId, string $title): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO pm_plans (company_id, title) VALUES (:co, :t)'
    );
    $stmt->execute(['co' => $companyId, 't' => $title]);
    return (int) $pdo->lastInsertId();
}

function seedUnit(
    PDO $pdo,
    int $companyId,
    string $unitNumber,
    string $unitType = 'truck',
    string $status = 'active'
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO fleet_units (company_id, unit_number, unit_type, status, created_by_user_id)
         VALUES (:co, :un, :ut, :st, 1)'
    );
    $stmt->execute([
        'co' => $companyId,
        'un' => $unitNumber,
        'ut' => $unitType,
        'st' => $status,
    ]);
    return (int) $pdo->lastInsertId();
}

function pmFleetAssert(bool $cond, string $label): void
{
    if (!$cond) {
        throw new RuntimeException("[{$label}] assertion failed");
    }
}

function pmFleetAssertThrows(callable $fn, string $cls, string $needle, string $label): void
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

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$only = $argv[1] ?? null;

function testPmFleet(string $name, callable $fn, ?string $only, int &$passed, int &$failed): void
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

echo "PmFleetInheritanceServiceTest\n";

// ── Binding CRUD & validation ───────────────────────────────────────────

testPmFleet('createBinding unit_type stores with meter config', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Truck Oil Change');
    $out = $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles', 'baseline_reading' => 0],
        'starts_at' => '2026-01-01',
    ]);
    pmFleetAssert($out['scope_type'] === 'unit_type', 'scope_type set');
    pmFleetAssert($out['unit_type'] === 'truck', 'unit_type pin');
    pmFleetAssert($out['fleet_unit_id'] === null, 'no fleet_unit_id on type scope');
    pmFleetAssert($out['frequency_config']['interval_units'] == 5000, 'interval kept');
}, $only, $passed, $failed);

testPmFleet('createBinding unit-scope requires fleet_unit_id', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Unit-only plan');
    pmFleetAssertThrows(
        fn() => $f['service']->createBinding($f['actor'], [
            'plan_id' => $planId,
            'company_id' => 10,
            'scope_type' => PmFleetBinding::SCOPE_UNIT,
            // no fleet_unit_id
            'frequency_kind' => 'meter',
            'frequency_config' => ['interval_units' => 10000, 'unit' => 'miles'],
            'starts_at' => '2026-01-01',
        ]),
        InvalidArgumentException::class,
        'fleet_unit_id is required',
        'unit-scope missing unit id',
    );
}, $only, $passed, $failed);

testPmFleet('createBinding unit_type-scope requires unit_type', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Type-only plan');
    pmFleetAssertThrows(
        fn() => $f['service']->createBinding($f['actor'], [
            'plan_id' => $planId,
            'company_id' => 10,
            'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
            // no unit_type
            'frequency_kind' => 'meter',
            'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles'],
            'starts_at' => '2026-01-01',
        ]),
        InvalidArgumentException::class,
        'unit_type is required',
        'type-scope missing type',
    );
}, $only, $passed, $failed);

testPmFleet('createBinding rejects cross-company fleet_unit', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Acme plan');
    $otherUnitId = seedUnit($f['pdo'], 20, 'U-OTHER');
    pmFleetAssertThrows(
        fn() => $f['service']->createBinding($f['actor'], [
            'plan_id' => $planId,
            'company_id' => 10,
            'scope_type' => PmFleetBinding::SCOPE_UNIT,
            'fleet_unit_id' => $otherUnitId,
            'frequency_kind' => 'meter',
            'frequency_config' => ['interval_units' => 3000, 'unit' => 'miles'],
            'starts_at' => '2026-01-01',
        ]),
        InvalidArgumentException::class,
        'different company',
        'cross-company fleet_unit',
    );
}, $only, $passed, $failed);

testPmFleet('createBinding rejects cross-company plan', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 20, 'Other co plan');
    pmFleetAssertThrows(
        fn() => $f['service']->createBinding($f['actor'], [
            'plan_id' => $planId,
            'company_id' => 10,
            'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
            'unit_type' => 'van',
            'frequency_kind' => 'meter',
            'frequency_config' => ['interval_units' => 3000, 'unit' => 'miles'],
            'starts_at' => '2026-01-01',
        ]),
        InvalidArgumentException::class,
        'plan belongs to a different company',
        'cross-company plan',
    );
}, $only, $passed, $failed);

testPmFleet('createBinding rejects meter config without interval_units', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Bad meter');
    pmFleetAssertThrows(
        fn() => $f['service']->createBinding($f['actor'], [
            'plan_id' => $planId,
            'company_id' => 10,
            'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
            'unit_type' => 'truck',
            'frequency_kind' => 'meter',
            'frequency_config' => ['unit' => 'miles'], // missing interval_units
            'starts_at' => '2026-01-01',
        ]),
        InvalidArgumentException::class,
        'interval_units',
        'meter missing interval',
    );
}, $only, $passed, $failed);

testPmFleet('createBinding rejects meter config without unit', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'No unit');
    pmFleetAssertThrows(
        fn() => $f['service']->createBinding($f['actor'], [
            'plan_id' => $planId,
            'company_id' => 10,
            'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
            'unit_type' => 'truck',
            'frequency_kind' => 'meter',
            'frequency_config' => ['interval_units' => 5000],
            'starts_at' => '2026-01-01',
        ]),
        InvalidArgumentException::class,
        'unit in (miles',
        'meter missing unit',
    );
}, $only, $passed, $failed);

testPmFleet('createBinding denies without pm.manage', function (): void {
    $f = makePmFleetFixture();
    $f['gate']->denials['pm.manage'] = true;
    $planId = seedPlan($f['pdo'], 10, 'Gated plan');
    pmFleetAssertThrows(
        fn() => $f['service']->createBinding($f['actor'], [
            'plan_id' => $planId,
            'company_id' => 10,
            'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
            'unit_type' => 'truck',
            'frequency_kind' => 'meter',
            'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles'],
            'starts_at' => '2026-01-01',
        ]),
        UnauthorizedException::class,
        'pm.manage',
        'gate denies create',
    );
}, $only, $passed, $failed);

testPmFleet('updateBinding changes frequency but keeps scope immutable', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Mutable plan');
    $created = $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles'],
        'starts_at' => '2026-01-01',
    ]);

    // Try to flip the scope_type — should silently stay the same.
    $updated = $f['service']->updateBinding($f['actor'], $created['id'], [
        'frequency_config' => ['interval_units' => 7500, 'unit' => 'miles'],
        'lead_time_days' => 5,
        'is_active' => 0,
        // scope_type / unit_type / fleet_unit_id are not writable
    ]);
    pmFleetAssert($updated['scope_type'] === 'unit_type', 'scope_type pinned');
    pmFleetAssert($updated['unit_type'] === 'truck', 'unit_type pinned');
    pmFleetAssert($updated['frequency_config']['interval_units'] == 7500, 'interval updated');
    pmFleetAssert($updated['lead_time_days'] === 5, 'lead time updated');
    pmFleetAssert($updated['is_active'] === 0, 'deactivated');
}, $only, $passed, $failed);

testPmFleet('deleteBinding removes the row and audits', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Doomed plan');
    $created = $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'van',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles'],
        'starts_at' => '2026-01-01',
    ]);
    $f['service']->deleteBinding($f['actor'], $created['id']);
    pmFleetAssert(
        $f['bindings']->findById($created['id']) === null,
        'binding gone after delete',
    );
    $events = array_map(fn($e) => $e->event, $f['audit']->entries);
    pmFleetAssert(in_array('pm.fleet_binding.deleted', $events, true), 'delete audited');
}, $only, $passed, $failed);

testPmFleet('listBindingsForCompany returns both scope types', function (): void {
    $f = makePmFleetFixture();
    $plan1 = seedPlan($f['pdo'], 10, 'Quarterly safety');
    $plan2 = seedPlan($f['pdo'], 10, 'Brake service');
    $unitId = seedUnit($f['pdo'], 10, 'T-17', 'truck');

    $f['service']->createBinding($f['actor'], [
        'plan_id' => $plan1,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles'],
        'starts_at' => '2026-01-01',
    ]);
    $f['service']->createBinding($f['actor'], [
        'plan_id' => $plan2,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT,
        'fleet_unit_id' => $unitId,
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 10000, 'unit' => 'miles'],
        'starts_at' => '2026-01-01',
    ]);

    $list = $f['service']->listBindingsForCompany($f['actor'], 10);
    pmFleetAssert(count($list) === 2, 'two bindings listed');
}, $only, $passed, $failed);

// ── Hook: ensureSchedulesForUnit ────────────────────────────────────────

testPmFleet('ensureSchedulesForUnit materializes unit_type binding', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Every truck oil change');
    $unitId = seedUnit($f['pdo'], 10, 'T-1', 'truck');

    $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles', 'baseline_reading' => 0],
        'starts_at' => '2026-01-01',
    ]);

    $unit = $f['units']->findById($unitId);
    pmFleetAssert($unit !== null, 'unit fetched');
    $ids = $f['service']->ensureSchedulesForUnit($f['actor'], $unit);
    pmFleetAssert(count($ids) === 1, 'one schedule spawned');

    $schedules = $f['schedules']->search(['fleet_unit_id' => $unitId]);
    pmFleetAssert(count($schedules) === 1, 'persisted schedule count');
    pmFleetAssert($schedules[0]->plan_id === $planId, 'schedule plan matches');
    pmFleetAssert($schedules[0]->frequency_kind === 'meter', 'frequency preserved');
}, $only, $passed, $failed);

testPmFleet('ensureSchedulesForUnit is idempotent across repeated calls', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Idempotent plan');
    $unitId = seedUnit($f['pdo'], 10, 'T-2', 'truck');
    $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles'],
        'starts_at' => '2026-01-01',
    ]);
    $unit = $f['units']->findById($unitId);

    $first = $f['service']->ensureSchedulesForUnit($f['actor'], $unit);
    $second = $f['service']->ensureSchedulesForUnit($f['actor'], $unit);
    pmFleetAssert(count($first) === 1, 'first run spawns one');
    pmFleetAssert(count($second) === 0, 'second run spawns nothing');
    pmFleetAssert(
        count($f['schedules']->search(['fleet_unit_id' => $unitId])) === 1,
        'still one schedule total',
    );
}, $only, $passed, $failed);

testPmFleet('ensureSchedulesForUnit: unit-specific binding wins over unit_type for same plan', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Shared plan');
    $unitId = seedUnit($f['pdo'], 10, 'T-3', 'truck');

    // Unit_type binding: 5000 mile interval for every truck
    $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles'],
        'starts_at' => '2026-01-01',
    ]);
    // Unit-specific override for the same plan: 3000 mile interval for this unit.
    $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT,
        'fleet_unit_id' => $unitId,
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 3000, 'unit' => 'miles'],
        'starts_at' => '2026-01-01',
    ]);

    $unit = $f['units']->findById($unitId);
    $ids = $f['service']->ensureSchedulesForUnit($f['actor'], $unit);
    pmFleetAssert(count($ids) === 1, 'just one schedule for the shared plan');
    $schedules = $f['schedules']->search(['fleet_unit_id' => $unitId]);
    pmFleetAssert(count($schedules) === 1, 'no duplicate rows');
    pmFleetAssert(
        (int) $schedules[0]->frequency_config['interval_units'] === 3000,
        'unit-specific interval wins',
    );
}, $only, $passed, $failed);

testPmFleet('ensureSchedulesForUnit skips retired unit', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Retired plan');
    $unitId = seedUnit($f['pdo'], 10, 'T-RET', 'truck', 'retired');
    $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles'],
        'starts_at' => '2026-01-01',
    ]);
    $unit = $f['units']->findById($unitId);
    $ids = $f['service']->ensureSchedulesForUnit($f['actor'], $unit);
    pmFleetAssert($ids === [], 'no schedules for retired unit');
}, $only, $passed, $failed);

testPmFleet('ensureSchedulesForUnit skips inactive bindings', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Inactive plan');
    $unitId = seedUnit($f['pdo'], 10, 'T-I', 'truck');
    $created = $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles'],
        'starts_at' => '2026-01-01',
    ]);
    $f['service']->updateBinding($f['actor'], $created['id'], ['is_active' => 0]);

    $unit = $f['units']->findById($unitId);
    $ids = $f['service']->ensureSchedulesForUnit($f['actor'], $unit);
    pmFleetAssert($ids === [], 'no schedules for inactive binding');
}, $only, $passed, $failed);

// ── Hook: onReadingRecorded ─────────────────────────────────────────────

testPmFleet('onReadingRecorded advances meter schedule when odometer crosses threshold', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Oil 5k');
    $unitId = seedUnit($f['pdo'], 10, 'T-OIL', 'truck');
    $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles', 'baseline_reading' => 0],
        'starts_at' => '2026-01-01',
    ]);
    $unit = $f['units']->findById($unitId);
    $f['service']->ensureSchedulesForUnit($f['actor'], $unit);

    $reading = new FleetUnitReading([
        'id' => 1,
        'fleet_unit_id' => $unitId,
        'reading_type' => FleetUnitReading::TYPE_ODOMETER,
        'value' => 5250.0,
        'recorded_at' => '2026-04-15 10:00:00',
        'source' => 'manual',
        'recorded_by_user_id' => 42,
    ]);
    $advanced = $f['service']->onReadingRecorded($unit, $reading);
    pmFleetAssert(count($advanced) === 1, 'one schedule advanced');

    $schedules = $f['schedules']->search(['fleet_unit_id' => $unitId]);
    pmFleetAssert($schedules[0]->next_due_at === '2026-04-15', 'next_due shifts to reading date');
    pmFleetAssert(
        (float) $schedules[0]->frequency_config['baseline_reading'] === 5000.0,
        'baseline advances by one interval',
    );
}, $only, $passed, $failed);

testPmFleet('onReadingRecorded does nothing when threshold not crossed', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Far off');
    $unitId = seedUnit($f['pdo'], 10, 'T-F', 'truck');
    $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 10000, 'unit' => 'miles', 'baseline_reading' => 0],
        'starts_at' => '2026-01-01',
    ]);
    $unit = $f['units']->findById($unitId);
    $f['service']->ensureSchedulesForUnit($f['actor'], $unit);

    $reading = new FleetUnitReading([
        'id' => 2,
        'fleet_unit_id' => $unitId,
        'reading_type' => FleetUnitReading::TYPE_ODOMETER,
        'value' => 2000.0,
        'recorded_at' => '2026-02-01 10:00:00',
        'source' => 'manual',
        'recorded_by_user_id' => 42,
    ]);
    $advanced = $f['service']->onReadingRecorded($unit, $reading);
    pmFleetAssert($advanced === [], 'no advance below threshold');
}, $only, $passed, $failed);

testPmFleet('onReadingRecorded advances engine-hours schedule independently from odometer', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Hours 250');
    $unitId = seedUnit($f['pdo'], 10, 'E-1', 'equipment');
    $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'equipment',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 250, 'unit' => 'hours', 'baseline_reading' => 0],
        'starts_at' => '2026-01-01',
    ]);
    $unit = $f['units']->findById($unitId);
    $f['service']->ensureSchedulesForUnit($f['actor'], $unit);

    // Odometer reading on an hours-based schedule should be a no-op.
    $odoReading = new FleetUnitReading([
        'id' => 1,
        'fleet_unit_id' => $unitId,
        'reading_type' => FleetUnitReading::TYPE_ODOMETER,
        'value' => 999999.0,
        'recorded_at' => '2026-04-01 10:00:00',
        'source' => 'manual',
        'recorded_by_user_id' => 42,
    ]);
    pmFleetAssert(
        $f['service']->onReadingRecorded($unit, $odoReading) === [],
        'odometer does not advance hours schedule',
    );

    // Actual engine-hours reading crosses 250 hour threshold.
    $hrsReading = new FleetUnitReading([
        'id' => 2,
        'fleet_unit_id' => $unitId,
        'reading_type' => FleetUnitReading::TYPE_ENGINE_HOURS,
        'value' => 260.0,
        'recorded_at' => '2026-04-10 10:00:00',
        'source' => 'manual',
        'recorded_by_user_id' => 42,
    ]);
    $advanced = $f['service']->onReadingRecorded($unit, $hrsReading);
    pmFleetAssert(count($advanced) === 1, 'hours reading triggers advance');
}, $only, $passed, $failed);

testPmFleet('onReadingRecorded skips retired unit', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Retired oil');
    $unitId = seedUnit($f['pdo'], 10, 'T-RET2', 'truck');
    // Create plan + schedule while still active.
    $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles', 'baseline_reading' => 0],
        'starts_at' => '2026-01-01',
    ]);
    $unit = $f['units']->findById($unitId);
    $f['service']->ensureSchedulesForUnit($f['actor'], $unit);

    // Now retire and backfill a reading past the threshold.
    $f['pdo']->exec("UPDATE fleet_units SET status = 'retired' WHERE id = {$unitId}");
    $unitRetired = $f['units']->findById($unitId);

    $reading = new FleetUnitReading([
        'id' => 3,
        'fleet_unit_id' => $unitId,
        'reading_type' => FleetUnitReading::TYPE_ODOMETER,
        'value' => 9999.0,
        'recorded_at' => '2026-04-20 10:00:00',
        'source' => 'manual',
        'recorded_by_user_id' => 42,
    ]);
    pmFleetAssert(
        $f['service']->onReadingRecorded($unitRetired, $reading) === [],
        'retired unit does not advance schedules',
    );
}, $only, $passed, $failed);

// ── Gating ──────────────────────────────────────────────────────────────

testPmFleet('listBindingsForCompany denied without pm.view', function (): void {
    $f = makePmFleetFixture();
    $f['gate']->denials['pm.view'] = true;
    pmFleetAssertThrows(
        fn() => $f['service']->listBindingsForCompany($f['actor'], 10),
        UnauthorizedException::class,
        'pm.view',
        'gate denies list',
    );
}, $only, $passed, $failed);

testPmFleet('deleteBinding denied without pm.manage', function (): void {
    $f = makePmFleetFixture();
    $planId = seedPlan($f['pdo'], 10, 'Locked plan');
    $created = $f['service']->createBinding($f['actor'], [
        'plan_id' => $planId,
        'company_id' => 10,
        'scope_type' => PmFleetBinding::SCOPE_UNIT_TYPE,
        'unit_type' => 'truck',
        'frequency_kind' => 'meter',
        'frequency_config' => ['interval_units' => 5000, 'unit' => 'miles'],
        'starts_at' => '2026-01-01',
    ]);
    $f['gate']->denials['pm.manage'] = true;
    pmFleetAssertThrows(
        fn() => $f['service']->deleteBinding($f['actor'], $created['id']),
        UnauthorizedException::class,
        'pm.manage',
        'gate denies delete',
    );
}, $only, $passed, $failed);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
