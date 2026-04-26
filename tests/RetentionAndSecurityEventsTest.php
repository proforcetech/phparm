<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "RetentionAndSecurityEventsTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\DataRetentionPolicy;
use App\Models\DataRetentionRun;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Retention\RetentionController;
use App\Services\Retention\RetentionPolicyRepository;
use App\Services\Retention\RetentionRunRepository;
use App\Services\Retention\RetentionRunner;
use App\Services\Security\SecurityEventController;
use App\Services\Security\SecurityEventLogger;
use App\Services\Security\SecurityEventRepository;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Cross-cutting Retention + SOC tests.
 *
 * Covers:
 *   - Models: action/severity/event-type constants.
 *   - PolicyRepo CRUD: create/find/findByEntity/listAll/listActive/update/delete.
 *   - RunRepo: start/complete + listForPolicy.
 *   - Runner.delete path: counts examined, deletes rows older than cutoff,
 *     leaves fresh rows alone, records examined+affected on the run, and
 *     bumps last_run_at on the policy.
 *   - Runner.archive path: copies old rows into archive table then deletes.
 *   - Runner.dry_run: counts examined but doesn't delete.
 *   - Runner.skipped: missing source table → skipped, not failed.
 *   - Runner.failed: bad config (archive without archive_table_name)
 *     finalises with status=failed and surfaces error_message.
 *   - SecurityEventLogger: scrubs sensitive keys, attaches actor.
 *   - SecurityEventRepository: filtered list + count + aggregate.
 *   - Permission gates on retention.run + retention.manage + security_events.view.
 *   - Controller envelope shape.
 */

class RsInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

class RsPermissiveGate extends AccessGate
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

function rsAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function rsAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function rsAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new RuntimeException("FAIL {$msg}: got " . get_class($e) . " expected {$expectedClass}");
        }
        return;
    }
    throw new RuntimeException("FAIL {$msg}: no exception thrown (expected {$expectedClass})");
}

function makeRsUser(int $id = 1, string $role = 'manager'): User
{
    $u = new User();
    $u->id = $id;
    $u->role = $role;
    $u->name = 'Test User';
    $u->email = "test{$id}@example.com";
    return $u;
}

function rsBuildSchema(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(<<<'SQL'
        CREATE TABLE data_retention_policies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT NOT NULL UNIQUE,
            table_name TEXT NOT NULL,
            timestamp_column TEXT NOT NULL DEFAULT 'created_at',
            retention_days INTEGER NOT NULL DEFAULT 90,
            action TEXT NOT NULL DEFAULT 'delete',
            archive_table_name TEXT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            last_run_at TEXT NULL,
            last_run_status TEXT NULL,
            last_run_records INTEGER NULL,
            notes TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE data_retention_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            policy_id INTEGER NOT NULL,
            started_at TEXT NOT NULL,
            completed_at TEXT NULL,
            status TEXT NOT NULL DEFAULT 'running',
            records_examined INTEGER NULL,
            records_affected INTEGER NULL,
            dry_run INTEGER NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            triggered_by_user_id INTEGER NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (policy_id) REFERENCES data_retention_policies(id) ON DELETE CASCADE
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE security_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL,
            severity TEXT NOT NULL DEFAULT 'info',
            actor_user_id INTEGER NULL,
            target_user_id INTEGER NULL,
            ip_address TEXT NULL,
            user_agent TEXT NULL,
            request_path TEXT NULL,
            context TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    // Sample target tables retained data lives in.
    $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE audit_logs_archive (id INTEGER, message TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE notification_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT, created_at TEXT)');
}

function rsSeedRows(PDO $pdo, string $table, array $createdAtList): void
{
    foreach ($createdAtList as $i => $ts) {
        $pdo->prepare("INSERT INTO {$table} (message, created_at) VALUES (:m, :c)")
            ->execute(['m' => "msg-{$i}", 'c' => $ts]);
    }
}

/**
 * @return array<string, mixed>
 */
function rsFixture(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    rsBuildSchema($pdo);

    $conn = new RsInMemoryConnection($pdo);
    $gate = new RsPermissiveGate();
    $policyRepo = new RetentionPolicyRepository($conn);
    $runRepo = new RetentionRunRepository($conn);
    $runner = new RetentionRunner($conn, $policyRepo, $runRepo, $gate);
    $rcontroller = new RetentionController($policyRepo, $runRepo, $runner, $gate);

    $eventRepo = new SecurityEventRepository($conn);
    $logger = new SecurityEventLogger($eventRepo);
    $secController = new SecurityEventController($eventRepo, $logger, $gate);

    return [
        'pdo' => $pdo,
        'connection' => $conn,
        'gate' => $gate,
        'policyRepo' => $policyRepo,
        'runRepo' => $runRepo,
        'runner' => $runner,
        'rcontroller' => $rcontroller,
        'eventRepo' => $eventRepo,
        'logger' => $logger,
        'secController' => $secController,
    ];
}

$tests = [];

// ---------------------------------------------------------------------------
// Models
// ---------------------------------------------------------------------------

$tests['model: retention policy action constants'] = function () {
    rsAssertSame(['delete', 'archive'], DataRetentionPolicy::ACTIONS);
    rsAssertSame('delete', DataRetentionPolicy::ACTION_DELETE);
};

$tests['model: retention run statuses'] = function () {
    rsAssertTrue(in_array(DataRetentionRun::STATUS_SUCCESS, DataRetentionRun::STATUSES, true));
    rsAssertTrue(in_array(DataRetentionRun::STATUS_FAILED, DataRetentionRun::STATUSES, true));
    rsAssertTrue(in_array(DataRetentionRun::STATUS_DRY_RUN, DataRetentionRun::STATUSES, true));
    rsAssertTrue(in_array(DataRetentionRun::STATUS_SKIPPED, DataRetentionRun::STATUSES, true));
};

$tests['model: security event severities + event constants'] = function () {
    rsAssertSame(['info', 'warning', 'critical'], SecurityEvent::SEVERITIES);
    rsAssertSame('login.failure', SecurityEvent::EVENT_LOGIN_FAILURE);
    rsAssertSame('account.locked', SecurityEvent::EVENT_ACCOUNT_LOCKED);
};

// ---------------------------------------------------------------------------
// Repository
// ---------------------------------------------------------------------------

$tests['policy repo: create/find/findByEntity/list'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs',
        'table_name' => 'audit_logs',
        'timestamp_column' => 'created_at',
        'retention_days' => 30,
        'action' => 'delete',
        'is_active' => true,
    ]);
    rsAssertTrue($p->id !== null);
    rsAssertSame('audit_logs', $p->entity_type);
    rsAssertSame(30, $p->retention_days);
    rsAssertTrue($p->is_active);

    $byEntity = $f['policyRepo']->findByEntity('audit_logs');
    rsAssertSame($p->id, $byEntity?->id);

    $all = $f['policyRepo']->listAll();
    rsAssertSame(1, count($all));
    $active = $f['policyRepo']->listActive();
    rsAssertSame(1, count($active));
};

$tests['policy repo: update + deactivate filters listActive'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => true,
    ]);
    $f['policyRepo']->update($p->id, ['is_active' => false, 'retention_days' => 60]);
    $reloaded = $f['policyRepo']->find($p->id);
    rsAssertSame(false, $reloaded->is_active);
    rsAssertSame(60, $reloaded->retention_days);
    rsAssertSame(0, count($f['policyRepo']->listActive()));
    rsAssertSame(1, count($f['policyRepo']->listAll()));
};

$tests['policy repo: delete'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'delete',
    ]);
    rsAssertTrue($f['policyRepo']->delete($p->id));
    rsAssertSame(null, $f['policyRepo']->find($p->id));
};

$tests['run repo: start + complete'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'delete',
    ]);
    $run = $f['runRepo']->start($p->id, false, 7);
    rsAssertSame('running', $run->status);
    rsAssertSame(false, $run->dry_run);
    rsAssertSame(7, $run->triggered_by_user_id);

    $finished = $f['runRepo']->complete($run->id, DataRetentionRun::STATUS_SUCCESS, 100, 50);
    rsAssertSame('success', $finished->status);
    rsAssertSame(100, $finished->records_examined);
    rsAssertSame(50, $finished->records_affected);
    rsAssertTrue($finished->completed_at !== null);
};

// ---------------------------------------------------------------------------
// Runner: delete path
// ---------------------------------------------------------------------------

$tests['runner: deletes rows older than cutoff, leaves fresh rows'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'timestamp_column' => 'created_at',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => true,
    ]);

    $now = new DateTimeImmutable();
    $old = $now->modify('-100 days')->format('Y-m-d H:i:s');
    $oldish = $now->modify('-31 days')->format('Y-m-d H:i:s');
    $fresh = $now->modify('-5 days')->format('Y-m-d H:i:s');
    rsSeedRows($f['pdo'], 'audit_logs', [$old, $oldish, $fresh, $fresh]);

    $run = $f['runner']->runById(makeRsUser(7), $p->id, false);
    rsAssertSame('success', $run->status);
    rsAssertSame(2, $run->records_examined);
    rsAssertSame(2, $run->records_affected);

    $remaining = (int) $f['pdo']->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    rsAssertSame(2, $remaining);

    $reloaded = $f['policyRepo']->find($p->id);
    rsAssertSame('success', $reloaded->last_run_status);
    rsAssertSame(2, $reloaded->last_run_records);
    rsAssertTrue($reloaded->last_run_at !== null);
};

$tests['runner: dry-run counts but does not delete'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => true,
    ]);
    $now = new DateTimeImmutable();
    rsSeedRows($f['pdo'], 'audit_logs', [
        $now->modify('-100 days')->format('Y-m-d H:i:s'),
        $now->modify('-50 days')->format('Y-m-d H:i:s'),
        $now->modify('-1 days')->format('Y-m-d H:i:s'),
    ]);

    $run = $f['runner']->runById(makeRsUser(7), $p->id, true);
    rsAssertSame('dry_run', $run->status);
    rsAssertSame(2, $run->records_examined);
    rsAssertSame(0, $run->records_affected);

    $remaining = (int) $f['pdo']->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    rsAssertSame(3, $remaining);
};

$tests['runner: zero-affected when no rows match'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => true,
    ]);
    $now = new DateTimeImmutable();
    rsSeedRows($f['pdo'], 'audit_logs', [$now->format('Y-m-d H:i:s')]);

    $run = $f['runner']->runById(makeRsUser(7), $p->id, false);
    rsAssertSame('success', $run->status);
    rsAssertSame(0, $run->records_examined);
    rsAssertSame(0, $run->records_affected);
};

$tests['runner: skipped when source table missing'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'no_such_table', 'table_name' => 'no_such_table',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => true,
    ]);

    $run = $f['runner']->runById(makeRsUser(7), $p->id, false);
    rsAssertSame('skipped', $run->status);
};

$tests['runner: archive path copies then deletes'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'archive',
        'archive_table_name' => 'audit_logs_archive', 'is_active' => true,
    ]);
    $now = new DateTimeImmutable();
    rsSeedRows($f['pdo'], 'audit_logs', [
        $now->modify('-100 days')->format('Y-m-d H:i:s'),
        $now->modify('-90 days')->format('Y-m-d H:i:s'),
        $now->modify('-1 days')->format('Y-m-d H:i:s'),
    ]);

    $run = $f['runner']->runById(makeRsUser(7), $p->id, false);
    rsAssertSame('success', $run->status);
    rsAssertSame(2, $run->records_affected);

    $live = (int) $f['pdo']->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $archived = (int) $f['pdo']->query('SELECT COUNT(*) FROM audit_logs_archive')->fetchColumn();
    rsAssertSame(1, $live);
    rsAssertSame(2, $archived);
};

$tests['runner: archive without archive_table_name fails cleanly'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'archive', 'is_active' => true,
    ]);
    rsSeedRows($f['pdo'], 'audit_logs', [(new DateTimeImmutable())->modify('-100 days')->format('Y-m-d H:i:s')]);

    rsAssertThrows(
        fn () => $f['runner']->runById(makeRsUser(7), $p->id, false),
        RuntimeException::class
    );
    // Run row should be marked failed even though we re-threw.
    $runs = $f['runRepo']->listForPolicy($p->id, 1);
    rsAssertSame(1, count($runs));
    rsAssertSame('failed', $runs[0]->status);
    rsAssertTrue($runs[0]->error_message !== null);
};

$tests['runner: invalid identifier rejected'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs; DROP TABLE x',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => true,
    ]);
    // Source table missing → skipped (the bad identifier never reaches
    // SQL because tableExists() uses a parameterized query).
    $run = $f['runner']->runById(makeRsUser(7), $p->id, false);
    rsAssertSame('skipped', $run->status);
};

$tests['runner: runAllActive iterates only active policies'] = function () {
    $f = rsFixture();
    $a = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => true,
    ]);
    $b = $f['policyRepo']->create([
        'entity_type' => 'notification_logs', 'table_name' => 'notification_logs',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => false,
    ]);

    $runs = $f['runner']->runAllActive(makeRsUser(7), true);
    rsAssertSame(1, count($runs));
    rsAssertSame($a->id, $runs[0]->policy_id);
};

$tests['runner: gate denies retention.run'] = function () {
    $f = rsFixture();
    $f['gate']->denials['retention.run'] = true;
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => true,
    ]);

    rsAssertThrows(
        fn () => $f['runner']->runById(makeRsUser(7), $p->id, false),
        UnauthorizedException::class
    );
};

$tests['runner: null actor bypasses gate (cron mode)'] = function () {
    $f = rsFixture();
    $f['gate']->denials['retention.run'] = true; // would block any non-null actor
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => true,
    ]);
    $run = $f['runner']->runById(null, $p->id, true);
    rsAssertSame('dry_run', $run->status);
};

// ---------------------------------------------------------------------------
// Security events
// ---------------------------------------------------------------------------

$tests['logger: writes event with actor + scrubbed context'] = function () {
    $f = rsFixture();
    $event = $f['logger']->log(SecurityEvent::EVENT_LOGIN_FAILURE, SecurityEvent::SEVERITY_WARNING, [
        'actor' => makeRsUser(42, 'admin'),
        'context' => ['attempt' => 3, 'password' => 'should-not-persist', 'token' => 'xyz'],
    ]);

    rsAssertSame('login.failure', $event->event_type);
    rsAssertSame('warning', $event->severity);
    rsAssertSame(42, $event->actor_user_id);
    rsAssertSame('[REDACTED]', $event->context['password']);
    rsAssertSame('[REDACTED]', $event->context['token']);
    rsAssertSame(3, $event->context['attempt']);
};

$tests['logger: invalid severity falls back to info'] = function () {
    $f = rsFixture();
    $event = $f['logger']->log('custom.event', 'banana', ['actor' => makeRsUser(1)]);
    rsAssertSame('info', $event->severity);
};

$tests['repo: filtered list + count + aggregateBySeverity'] = function () {
    $f = rsFixture();
    $f['logger']->log('login.success', 'info', ['actor' => makeRsUser(1)]);
    $f['logger']->log('login.failure', 'warning', ['actor' => makeRsUser(2)]);
    $f['logger']->log('login.failure', 'critical', ['actor' => makeRsUser(2)]);
    $f['logger']->log('admin.action', 'info', ['actor' => makeRsUser(3)]);

    $bySeverity = $f['eventRepo']->listFiltered(['severity' => 'critical']);
    rsAssertSame(1, count($bySeverity));
    rsAssertSame('login.failure', $bySeverity[0]->event_type);

    rsAssertSame(2, $f['eventRepo']->countFiltered(['actor_user_id' => 2]));

    $agg = $f['eventRepo']->aggregateBySeverity();
    $bucket = [];
    foreach ($agg as $row) {
        $bucket[$row['severity']] = $row['total'];
    }
    rsAssertSame(2, $bucket['info']);
    rsAssertSame(1, $bucket['warning']);
    rsAssertSame(1, $bucket['critical']);
};

// ---------------------------------------------------------------------------
// Controllers
// ---------------------------------------------------------------------------

$tests['retention controller: list + show envelope shape'] = function () {
    $f = rsFixture();
    $p = $f['policyRepo']->create([
        'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        'retention_days' => 30, 'action' => 'delete', 'is_active' => true,
    ]);
    $list = $f['rcontroller']->listPolicies(makeRsUser(1));
    rsAssertTrue(isset($list['data']));
    rsAssertSame(1, count($list['data']));

    $show = $f['rcontroller']->getPolicy(makeRsUser(1), $p->id);
    rsAssertTrue(isset($show['data']['policy']));
    rsAssertTrue(isset($show['data']['recent_runs']));
};

$tests['retention controller: validates archive payload'] = function () {
    $f = rsFixture();
    rsAssertThrows(
        fn () => $f['rcontroller']->createPolicy(makeRsUser(1), [
            'entity_type' => 'audit_logs',
            'table_name' => 'audit_logs',
            'action' => 'archive',
        ]),
        InvalidArgumentException::class
    );
};

$tests['retention controller: gate denies retention.manage'] = function () {
    $f = rsFixture();
    $f['gate']->denials['retention.manage'] = true;
    rsAssertThrows(
        fn () => $f['rcontroller']->createPolicy(makeRsUser(1), [
            'entity_type' => 'audit_logs', 'table_name' => 'audit_logs',
        ]),
        UnauthorizedException::class
    );
};

$tests['security controller: index envelope + total'] = function () {
    $f = rsFixture();
    $f['logger']->log('login.success', 'info', ['actor' => makeRsUser(1)]);
    $resp = $f['secController']->index(makeRsUser(1), []);
    rsAssertTrue(isset($resp['data']['events']));
    rsAssertSame(1, $resp['data']['total']);
};

$tests['security controller: record requires manage'] = function () {
    $f = rsFixture();
    $f['gate']->denials['security_events.manage'] = true;
    rsAssertThrows(
        fn () => $f['secController']->record(makeRsUser(1), [
            'event_type' => 'admin.action', 'severity' => 'warning',
        ]),
        UnauthorizedException::class
    );
};

$tests['security controller: record creates an event'] = function () {
    $f = rsFixture();
    $resp = $f['secController']->record(makeRsUser(99, 'manager'), [
        'event_type' => 'admin.action',
        'severity' => 'warning',
        'context' => ['note' => 'manual triage'],
    ]);
    rsAssertTrue(isset($resp['data']['id']));
    rsAssertSame('admin.action', $resp['data']['event_type']);
    rsAssertSame(99, $resp['data']['actor_user_id']);
};

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

echo "RetentionAndSecurityEventsTest\n";
$pass = 0;
$fail = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  ✓ {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  ✗ {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    exit(1);
}
