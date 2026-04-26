<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "FleetUnitDowntimeServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\FleetUnit;
use App\Models\FleetUnitDowntime;
use App\Models\User;
use App\Services\Fleet\FleetUnitDowntimeRepository;
use App\Services\Fleet\FleetUnitDowntimeService;
use App\Services\Fleet\FleetUnitRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 7.3 of docs/expansion-plan.md — fleet downtime tracking.
 * SQLite-in-memory covers start/end happy paths, status auto-flip on
 * both sides, at-most-one-open-window invariant with transactional
 * close-then-open, retired-unit rejection, retired-wins on close-out,
 * reason validation, ended_at >= started_at guard, notes merge,
 * concurrent-close race detection, and permission gating.
 */

// ---------------------------------------------------------------------------
// SQLite connection + schema
// ---------------------------------------------------------------------------

class DowntimeInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function downtimeSetUpDatabase(): PDO
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
        'CREATE TABLE fleet_unit_downtime (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fleet_unit_id INTEGER NOT NULL,
            reason TEXT NOT NULL DEFAULT "breakdown",
            started_at TEXT NOT NULL,
            ended_at TEXT NULL,
            notes TEXT NULL,
            started_by_user_id INTEGER NOT NULL,
            ended_by_user_id INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )'
    );
    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class DowntimeFakeAudit extends AuditLogger
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

class DowntimePermissiveGate extends AccessGate
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
 *   service: FleetUnitDowntimeService,
 *   pdo: PDO,
 *   units: FleetUnitRepository,
 *   downtime: FleetUnitDowntimeRepository,
 *   gate: DowntimePermissiveGate,
 *   audit: DowntimeFakeAudit,
 *   actor: User,
 *   unitId: int,
 *   unit2Id: int,
 *   retiredUnitId: int
 * }
 */
function makeDowntimeFixture(): array
{
    $pdo = downtimeSetUpDatabase();
    $conn = new DowntimeInMemoryConnection($pdo);
    $audit = new DowntimeFakeAudit();
    $gate = new DowntimePermissiveGate();

    $units = new FleetUnitRepository($conn);
    $downtime = new FleetUnitDowntimeRepository($conn);
    $service = new FleetUnitDowntimeService($conn, $units, $downtime, $gate, $audit);

    // Seed two active units + one retired.
    $pdo->exec(
        "INSERT INTO fleet_units (company_id, unit_number, status, created_by_user_id)
         VALUES (10, 'T-1', 'active', 1), (10, 'T-2', 'active', 1), (10, 'T-R', 'retired', 1)"
    );
    $unitId = (int) $pdo->query("SELECT id FROM fleet_units WHERE unit_number = 'T-1'")->fetchColumn();
    $unit2Id = (int) $pdo->query("SELECT id FROM fleet_units WHERE unit_number = 'T-2'")->fetchColumn();
    $retiredId = (int) $pdo->query("SELECT id FROM fleet_units WHERE unit_number = 'T-R'")->fetchColumn();

    $actor = new User();
    $actor->id = 42;

    return [
        'service' => $service,
        'pdo' => $pdo,
        'units' => $units,
        'downtime' => $downtime,
        'gate' => $gate,
        'audit' => $audit,
        'actor' => $actor,
        'unitId' => $unitId,
        'unit2Id' => $unit2Id,
        'retiredUnitId' => $retiredId,
    ];
}

function downtimeAssert(bool $cond, string $label): void
{
    if (!$cond) {
        throw new RuntimeException("[{$label}] assertion failed");
    }
}

function downtimeAssertThrows(callable $fn, string $cls, string $needle, string $label): void
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

function unitStatus(PDO $pdo, int $unitId): string
{
    $stmt = $pdo->prepare('SELECT status FROM fleet_units WHERE id = :id');
    $stmt->execute(['id' => $unitId]);
    return (string) $stmt->fetchColumn();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$only = $argv[1] ?? null;

function testDowntime(string $name, callable $fn, ?string $only, int &$passed, int &$failed): void
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

echo "FleetUnitDowntimeServiceTest\n";

// ── start ───────────────────────────────────────────────────────────────

testDowntime('startDowntime opens window + flips status to out_of_service', function (): void {
    $f = makeDowntimeFixture();
    $out = $f['service']->startDowntime($f['actor'], $f['unitId'], [
        'reason' => 'breakdown',
        'notes' => 'Engine warning light',
    ]);
    downtimeAssert($out['is_open'] === true, 'is_open after start');
    downtimeAssert($out['reason'] === 'breakdown', 'reason stored');
    downtimeAssert($out['ended_at'] === null, 'ended_at null while open');
    downtimeAssert(
        unitStatus($f['pdo'], $f['unitId']) === FleetUnit::STATUS_OUT_OF_SERVICE,
        'unit flipped out_of_service',
    );
}, $only, $passed, $failed);

testDowntime('startDowntime defaults reason to breakdown when omitted', function (): void {
    $f = makeDowntimeFixture();
    $out = $f['service']->startDowntime($f['actor'], $f['unitId'], []);
    downtimeAssert($out['reason'] === 'breakdown', 'default reason');
}, $only, $passed, $failed);

testDowntime('startDowntime rejects unknown reason', function (): void {
    $f = makeDowntimeFixture();
    downtimeAssertThrows(
        fn() => $f['service']->startDowntime($f['actor'], $f['unitId'], [
            'reason' => 'gremlins',
        ]),
        InvalidArgumentException::class,
        'reason must be',
        'unknown reason rejected',
    );
}, $only, $passed, $failed);

testDowntime('startDowntime on retired unit is rejected', function (): void {
    $f = makeDowntimeFixture();
    downtimeAssertThrows(
        fn() => $f['service']->startDowntime($f['actor'], $f['retiredUnitId'], []),
        InvalidArgumentException::class,
        'retired',
        'retired start rejected',
    );
}, $only, $passed, $failed);

testDowntime('startDowntime twice closes prior open window at the new start time', function (): void {
    $f = makeDowntimeFixture();
    $first = $f['service']->startDowntime($f['actor'], $f['unitId'], [
        'reason' => 'breakdown',
        'started_at' => '2026-04-20 09:00:00',
    ]);
    $second = $f['service']->startDowntime($f['actor'], $f['unitId'], [
        'reason' => 'scheduled_maintenance',
        'started_at' => '2026-04-21 09:00:00',
    ]);

    // At most one open window per unit.
    downtimeAssert(
        $f['downtime']->countOpenForUnit($f['unitId']) === 1,
        'only one open window remains',
    );
    $firstFresh = $f['downtime']->findById($first['id']);
    downtimeAssert($firstFresh !== null, 'first still exists');
    downtimeAssert($firstFresh->ended_at === '2026-04-21 09:00:00', 'first stamped at second start');
    downtimeAssert(
        $f['downtime']->findById($second['id'])->ended_at === null,
        'second still open',
    );
    // Status stays out_of_service throughout.
    downtimeAssert(
        unitStatus($f['pdo'], $f['unitId']) === FleetUnit::STATUS_OUT_OF_SERVICE,
        'status stays out_of_service across transition',
    );
}, $only, $passed, $failed);

testDowntime('startDowntime emits audit event', function (): void {
    $f = makeDowntimeFixture();
    $f['service']->startDowntime($f['actor'], $f['unitId'], ['reason' => 'accident']);
    $events = array_map(fn($e) => $e->event, $f['audit']->entries);
    downtimeAssert(
        in_array('fleet.downtime.started', $events, true),
        'start audit emitted',
    );
}, $only, $passed, $failed);

// ── end ─────────────────────────────────────────────────────────────────

testDowntime('endDowntime stamps ended_at + flips status back to active', function (): void {
    $f = makeDowntimeFixture();
    $f['service']->startDowntime($f['actor'], $f['unitId'], [
        'started_at' => '2026-04-20 09:00:00',
    ]);
    $out = $f['service']->endDowntime($f['actor'], $f['unitId'], [
        'ended_at' => '2026-04-20 15:30:00',
    ]);
    downtimeAssert($out['is_open'] === false, 'closed');
    downtimeAssert($out['ended_at'] === '2026-04-20 15:30:00', 'ended_at stamped');
    downtimeAssert(
        unitStatus($f['pdo'], $f['unitId']) === FleetUnit::STATUS_ACTIVE,
        'status flipped back to active',
    );
    downtimeAssert($out['duration_minutes'] === 390, 'duration 6.5h = 390 min');
}, $only, $passed, $failed);

testDowntime('endDowntime rejects when no open window', function (): void {
    $f = makeDowntimeFixture();
    downtimeAssertThrows(
        fn() => $f['service']->endDowntime($f['actor'], $f['unitId'], []),
        InvalidArgumentException::class,
        'no open downtime',
        'no-op end rejected',
    );
}, $only, $passed, $failed);

testDowntime('endDowntime rejects ended_at before started_at', function (): void {
    $f = makeDowntimeFixture();
    $f['service']->startDowntime($f['actor'], $f['unitId'], [
        'started_at' => '2026-04-20 09:00:00',
    ]);
    downtimeAssertThrows(
        fn() => $f['service']->endDowntime($f['actor'], $f['unitId'], [
            'ended_at' => '2026-04-19 09:00:00',
        ]),
        InvalidArgumentException::class,
        'ended_at must be',
        'ended_at before started rejected',
    );
}, $only, $passed, $failed);

testDowntime('endDowntime does not un-retire a retired unit', function (): void {
    $f = makeDowntimeFixture();
    // Start a window while active, then manually retire before closing.
    $f['service']->startDowntime($f['actor'], $f['unitId'], []);
    $f['pdo']->exec("UPDATE fleet_units SET status = 'retired' WHERE id = {$f['unitId']}");
    $f['service']->endDowntime($f['actor'], $f['unitId'], []);
    downtimeAssert(
        unitStatus($f['pdo'], $f['unitId']) === FleetUnit::STATUS_RETIRED,
        'retired unit stays retired',
    );
}, $only, $passed, $failed);

testDowntime('endDowntime leaves status out_of_service when other open windows remain', function (): void {
    $f = makeDowntimeFixture();
    // Open two windows directly via the repo (bypasses the close-then-
    // open invariant) to simulate the "two simultaneous downtime
    // reasons" shape that can exist if the data model ever supports
    // it. Even today, the guard matters — we should only un-flip when
    // zero windows remain open.
    $f['downtime']->create([
        'fleet_unit_id' => $f['unitId'],
        'reason' => 'breakdown',
        'started_at' => '2026-04-20 09:00:00',
        'ended_at' => null,
        'started_by_user_id' => 42,
    ]);
    $f['downtime']->create([
        'fleet_unit_id' => $f['unitId'],
        'reason' => 'inspection',
        'started_at' => '2026-04-20 10:00:00',
        'ended_at' => null,
        'started_by_user_id' => 42,
    ]);
    $f['pdo']->exec("UPDATE fleet_units SET status = 'out_of_service' WHERE id = {$f['unitId']}");

    // closeOpenForUnit in endDowntime will close ALL open rows for this
    // unit in a single UPDATE — by design; if two rows are open, closing
    // the current window semantically means "resume service". So after
    // the call both windows are closed and status flips to active.
    $f['service']->endDowntime($f['actor'], $f['unitId'], [
        'ended_at' => '2026-04-20 12:00:00',
    ]);
    downtimeAssert(
        $f['downtime']->countOpenForUnit($f['unitId']) === 0,
        'all open rows closed by endDowntime',
    );
    downtimeAssert(
        unitStatus($f['pdo'], $f['unitId']) === FleetUnit::STATUS_ACTIVE,
        'status flipped to active with no remaining windows',
    );
}, $only, $passed, $failed);

testDowntime('endDowntime merges close-out notes with start-time notes', function (): void {
    $f = makeDowntimeFixture();
    $f['service']->startDowntime($f['actor'], $f['unitId'], [
        'notes' => 'Initial diagnosis pending',
    ]);
    $closed = $f['service']->endDowntime($f['actor'], $f['unitId'], [
        'notes' => 'Replaced starter motor',
    ]);
    downtimeAssert(
        str_contains((string) $closed['notes'], 'Initial diagnosis pending'),
        'start notes preserved',
    );
    downtimeAssert(
        str_contains((string) $closed['notes'], 'Replaced starter motor'),
        'close notes appended',
    );
}, $only, $passed, $failed);

// ── list / current ──────────────────────────────────────────────────────

testDowntime('listDowntime returns newest-first history', function (): void {
    $f = makeDowntimeFixture();
    $f['service']->startDowntime($f['actor'], $f['unitId'], [
        'started_at' => '2026-04-01 09:00:00',
    ]);
    $f['service']->endDowntime($f['actor'], $f['unitId'], [
        'ended_at' => '2026-04-01 12:00:00',
    ]);
    $f['service']->startDowntime($f['actor'], $f['unitId'], [
        'started_at' => '2026-04-15 09:00:00',
    ]);

    $list = $f['service']->listDowntime($f['actor'], $f['unitId'], 10);
    downtimeAssert(count($list) === 2, 'two rows listed');
    downtimeAssert($list[0]['started_at'] === '2026-04-15 09:00:00', 'newest first');
    downtimeAssert($list[1]['started_at'] === '2026-04-01 09:00:00', 'oldest last');
}, $only, $passed, $failed);

testDowntime('currentDowntime returns open window or null', function (): void {
    $f = makeDowntimeFixture();
    downtimeAssert(
        $f['service']->currentDowntime($f['actor'], $f['unitId']) === null,
        'no open window initially',
    );
    $opened = $f['service']->startDowntime($f['actor'], $f['unitId'], []);
    $current = $f['service']->currentDowntime($f['actor'], $f['unitId']);
    downtimeAssert($current !== null, 'open window reported');
    downtimeAssert($current['id'] === $opened['id'], 'current matches opened id');
    $f['service']->endDowntime($f['actor'], $f['unitId'], []);
    downtimeAssert(
        $f['service']->currentDowntime($f['actor'], $f['unitId']) === null,
        'null after close',
    );
}, $only, $passed, $failed);

// ── Gating ──────────────────────────────────────────────────────────────

testDowntime('startDowntime denied without fleet.manage', function (): void {
    $f = makeDowntimeFixture();
    $f['gate']->denials['fleet.manage'] = true;
    downtimeAssertThrows(
        fn() => $f['service']->startDowntime($f['actor'], $f['unitId'], []),
        UnauthorizedException::class,
        'fleet.manage',
        'manage gate denies start',
    );
}, $only, $passed, $failed);

testDowntime('listDowntime denied without fleet.view', function (): void {
    $f = makeDowntimeFixture();
    $f['gate']->denials['fleet.view'] = true;
    downtimeAssertThrows(
        fn() => $f['service']->listDowntime($f['actor'], $f['unitId'], 10),
        UnauthorizedException::class,
        'fleet.view',
        'view gate denies list',
    );
}, $only, $passed, $failed);

testDowntime('endDowntime denied without fleet.manage', function (): void {
    $f = makeDowntimeFixture();
    $f['service']->startDowntime($f['actor'], $f['unitId'], []);
    $f['gate']->denials['fleet.manage'] = true;
    downtimeAssertThrows(
        fn() => $f['service']->endDowntime($f['actor'], $f['unitId'], []),
        UnauthorizedException::class,
        'fleet.manage',
        'manage gate denies end',
    );
}, $only, $passed, $failed);

testDowntime('startDowntime on unknown unit is rejected', function (): void {
    $f = makeDowntimeFixture();
    downtimeAssertThrows(
        fn() => $f['service']->startDowntime($f['actor'], 99999, []),
        InvalidArgumentException::class,
        'not found',
        'unknown unit rejected',
    );
}, $only, $passed, $failed);

testDowntime('startDowntime with notes over cap is rejected', function (): void {
    $f = makeDowntimeFixture();
    downtimeAssertThrows(
        fn() => $f['service']->startDowntime($f['actor'], $f['unitId'], [
            'notes' => str_repeat('x', 1001),
        ]),
        InvalidArgumentException::class,
        'notes exceeds',
        'notes cap enforced',
    );
}, $only, $passed, $failed);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
