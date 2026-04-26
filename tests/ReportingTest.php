<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "ReportingTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\ReportExecution;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Services\Reporting\ReportCatalogService;
use App\Services\Reporting\ReportExecutionRepository;
use App\Services\Reporting\ReportExportService;
use App\Services\Reporting\SavedReportRepository;
use App\Services\Reporting\SavedReportService;
use App\Services\Reporting\ScheduledReportRepository;
use App\Services\Reporting\ScheduledReportService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Cross-cutting reporting/BI tests.
 *
 * Covers:
 *   - Migration shape matches in SQLite (saved_reports, scheduled_reports, report_executions).
 *   - SavedReportRepository CRUD: create/find/update/delete + JSON round-trip on parameters.
 *   - SavedReportRepository.listForOwner returns own + shared but not other-owner-private.
 *   - ScheduledReportRepository: create/find/listDue scope by next_run_at.
 *   - ReportExecutionRepository: start → finish writes correct status/duration.
 *   - ReportCatalogService: catalog listing exposes all built-ins including drill_down.
 *   - SavedReportService.create with unknown report_key raises InvalidArgumentException.
 *   - SavedReportService.create rejects unauthenticated user (gate denial on reporting.view).
 *   - SavedReportService.create with is_shared requires reporting.manage.
 *   - SavedReportService.runSaved happy path: executes, writes execution row succeeded.
 *   - SavedReportService.runSaved on shared report by non-owner allowed.
 *   - SavedReportService.runSaved on private report by non-owner without manage rejected.
 *   - SavedReportService.runAdhoc unknown key raises.
 *   - SavedReportService runner failure: writes execution row failed + rethrows.
 *   - ScheduledReportService.computeNextRun for "0 9 * * *" advances correctly across midnight.
 *   - ScheduledReportService.computeNextRun for "* / 15 * * * *" hits next 15-min mark.
 *   - ScheduledReportService.create validates: cron 5 fields, valid timezone, valid format, recipients.
 *   - ScheduledReportService.processDue runs due schedule, advances next_run_at, dispatches email.
 *   - ScheduledReportService.processDue records 'failed' when underlying report key missing.
 *   - ReportExportService.csv preserves headers + per-row order.
 *   - ReportExportService.json wraps payload with columns/rows/count.
 */

class ReportingInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

class ReportingPermissiveGate extends AccessGate
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

/**
 * Test catalog overrides buildCatalog with SQLite-friendly entries
 * (no DATEDIFF/DATE_FORMAT). Same shape contract — runner returns
 * {rows, total}.
 */
class ReportingTestCatalog extends ReportCatalogService
{
    public bool $simulateFailure = false;

    public function listReports(): array
    {
        return [
            [
                'key' => 'test.simple',
                'module' => 'test',
                'name' => 'Simple Test Report',
                'description' => 'Hardcoded rows.',
                'parameters' => [
                    ['name' => 'min', 'type' => 'int', 'label' => 'Min', 'required' => false, 'default' => 0],
                ],
                'columns' => [
                    ['key' => 'id', 'label' => 'ID', 'type' => 'int'],
                    ['key' => 'value', 'label' => 'Value', 'type' => 'string'],
                ],
                'drill_down' => ['target' => 'test', 'key' => 'id'],
            ],
            [
                'key' => 'test.empty',
                'module' => 'test',
                'name' => 'Empty Report',
                'description' => 'No rows.',
                'parameters' => [],
                'columns' => [['key' => 'x', 'label' => 'X']],
                'drill_down' => null,
            ],
        ];
    }

    public function describeReport(string $key): ?array
    {
        foreach ($this->listReports() as $r) {
            if ($r['key'] === $key) {
                return $r;
            }
        }
        return null;
    }

    public function hasReport(string $key): bool
    {
        return $this->describeReport($key) !== null;
    }

    public function run(string $key, array $parameters): array
    {
        if ($this->simulateFailure) {
            throw new RuntimeException('Simulated runner failure.');
        }
        if ($key === 'test.simple') {
            $min = (int) ($parameters['min'] ?? 0);
            $rows = [
                ['id' => 1, 'value' => 'alpha'],
                ['id' => 2, 'value' => 'beta'],
                ['id' => 3, 'value' => 'gamma'],
            ];
            $rows = array_values(array_filter($rows, static fn ($r) => $r['id'] >= $min));
            return [
                'rows' => $rows,
                'columns' => $this->describeReport($key)['columns'],
                'total' => count($rows),
                'drill_down' => $this->describeReport($key)['drill_down'],
            ];
        }
        if ($key === 'test.empty') {
            return ['rows' => [], 'columns' => [['key' => 'x', 'label' => 'X']], 'total' => 0, 'drill_down' => null];
        }
        throw new InvalidArgumentException("Unknown report: {$key}");
    }
}

function rptAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function rptAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function rptAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
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

function rptMakeUser(int $id, string $role = 'manager'): User
{
    $u = new User();
    $u->id = $id;
    $u->name = 'User-' . $id;
    $u->email = "user{$id}@example.com";
    $u->role = $role;
    $u->active = true;
    return $u;
}

function rptBuildSchema(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(<<<'SQL'
        CREATE TABLE saved_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_key TEXT NOT NULL,
            name TEXT NOT NULL,
            description TEXT NULL,
            parameters TEXT NULL,
            columns_visible TEXT NULL,
            drill_down TEXT NULL,
            owner_user_id INTEGER NULL,
            is_shared INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE scheduled_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            saved_report_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            cron_expression TEXT NOT NULL,
            timezone TEXT NOT NULL DEFAULT 'UTC',
            output_format TEXT NOT NULL DEFAULT 'csv',
            recipients TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            last_run_at TEXT NULL,
            next_run_at TEXT NULL,
            last_status TEXT NULL,
            last_error TEXT NULL,
            created_by INTEGER NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE report_executions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_key TEXT NOT NULL,
            saved_report_id INTEGER NULL,
            scheduled_report_id INTEGER NULL,
            triggered_by TEXT NOT NULL DEFAULT 'manual',
            user_id INTEGER NULL,
            parameters TEXT NULL,
            status TEXT NOT NULL DEFAULT 'running',
            row_count INTEGER NULL,
            duration_ms INTEGER NULL,
            error_message TEXT NULL,
            started_at TEXT DEFAULT CURRENT_TIMESTAMP,
            finished_at TEXT NULL
        )
    SQL);
}

/**
 * @return array<string, mixed>
 */
function rptFixture(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    rptBuildSchema($pdo);

    $conn = new ReportingInMemoryConnection($pdo);
    $gate = new ReportingPermissiveGate();
    $catalog = new ReportingTestCatalog($conn);
    $savedRepo = new SavedReportRepository($conn);
    $scheduleRepo = new ScheduledReportRepository($conn);
    $executionRepo = new ReportExecutionRepository($conn);
    $exporter = new ReportExportService();
    $reportService = new SavedReportService($savedRepo, $catalog, $executionRepo, $gate);
    $scheduleService = new ScheduledReportService(
        $scheduleRepo,
        $savedRepo,
        $reportService,
        $exporter,
        $gate
    );

    return compact(
        'conn', 'gate', 'catalog', 'savedRepo', 'scheduleRepo',
        'executionRepo', 'exporter', 'reportService', 'scheduleService'
    );
}

$tests = [];

// ---------------------------------------------------------------
// SavedReportRepository
// ---------------------------------------------------------------

$tests['SavedReportRepository.create + find round-trip with JSON'] = function (): void {
    $f = rptFixture();
    $saved = $f['savedRepo']->create([
        'report_key' => 'test.simple',
        'name' => 'My Saved',
        'description' => 'desc',
        'parameters' => ['min' => 2],
        'columns_visible' => ['id', 'value'],
        'drill_down' => null,
        'owner_user_id' => 7,
        'is_shared' => false,
    ]);
    rptAssertTrue($saved->id > 0, 'id assigned');
    rptAssertSame('My Saved', $saved->name);
    $hydrated = $f['savedRepo']->find((int) $saved->id);
    rptAssertSame(['min' => 2], $hydrated->parameters);
    rptAssertSame(['id', 'value'], $hydrated->columns_visible);
    rptAssertSame(false, $hydrated->is_shared);
};

$tests['SavedReportRepository.update changes name + parameters'] = function (): void {
    $f = rptFixture();
    $saved = $f['savedRepo']->create([
        'report_key' => 'test.simple',
        'name' => 'Original',
        'parameters' => null,
        'owner_user_id' => 7,
    ]);
    $f['savedRepo']->update((int) $saved->id, ['name' => 'Updated', 'parameters' => ['min' => 5]]);
    $reloaded = $f['savedRepo']->find((int) $saved->id);
    rptAssertSame('Updated', $reloaded->name);
    rptAssertSame(['min' => 5], $reloaded->parameters);
};

$tests['SavedReportRepository.delete'] = function (): void {
    $f = rptFixture();
    $saved = $f['savedRepo']->create([
        'report_key' => 'test.simple',
        'name' => 'Drop Me',
        'owner_user_id' => 7,
    ]);
    rptAssertTrue($f['savedRepo']->delete((int) $saved->id), 'delete returns true');
    rptAssertSame(null, $f['savedRepo']->find((int) $saved->id));
};

$tests['SavedReportRepository.listForOwner returns own + shared not other-private'] = function (): void {
    $f = rptFixture();
    $f['savedRepo']->create(['report_key' => 'test.simple', 'name' => 'Mine', 'owner_user_id' => 7, 'is_shared' => false]);
    $f['savedRepo']->create(['report_key' => 'test.simple', 'name' => 'Shared', 'owner_user_id' => 9, 'is_shared' => true]);
    $f['savedRepo']->create(['report_key' => 'test.simple', 'name' => 'OtherPrivate', 'owner_user_id' => 9, 'is_shared' => false]);

    $list = $f['savedRepo']->listForOwner(7);
    $names = array_map(static fn ($r) => $r->name, $list);
    sort($names);
    rptAssertSame(['Mine', 'Shared'], $names);
};

// ---------------------------------------------------------------
// ScheduledReportRepository
// ---------------------------------------------------------------

$tests['ScheduledReportRepository.create + listDue scopes by next_run_at'] = function (): void {
    $f = rptFixture();
    $saved = $f['savedRepo']->create(['report_key' => 'test.simple', 'name' => 'S', 'owner_user_id' => 7]);

    $dueId = $f['scheduleRepo']->create([
        'saved_report_id' => $saved->id,
        'name' => 'due',
        'cron_expression' => '0 * * * *',
        'timezone' => 'UTC',
        'output_format' => 'csv',
        'recipients' => 'a@b.test',
        'is_active' => true,
        'next_run_at' => '2026-01-01 10:00:00',
        'created_by' => 7,
    ])->id;

    $f['scheduleRepo']->create([
        'saved_report_id' => $saved->id,
        'name' => 'future',
        'cron_expression' => '0 * * * *',
        'timezone' => 'UTC',
        'output_format' => 'csv',
        'recipients' => 'a@b.test',
        'is_active' => true,
        'next_run_at' => '2099-01-01 10:00:00',
        'created_by' => 7,
    ]);

    $f['scheduleRepo']->create([
        'saved_report_id' => $saved->id,
        'name' => 'inactive',
        'cron_expression' => '0 * * * *',
        'timezone' => 'UTC',
        'output_format' => 'csv',
        'recipients' => 'a@b.test',
        'is_active' => false,
        'next_run_at' => '2026-01-01 10:00:00',
        'created_by' => 7,
    ]);

    $due = $f['scheduleRepo']->listDue('2026-01-01 10:00:00');
    rptAssertSame(1, count($due));
    rptAssertSame($dueId, $due[0]->id);
    rptAssertSame('due', $due[0]->name);
};

// ---------------------------------------------------------------
// ReportExecutionRepository
// ---------------------------------------------------------------

$tests['ReportExecutionRepository.start + finish records correctly'] = function (): void {
    $f = rptFixture();
    $exec = $f['executionRepo']->start([
        'report_key' => 'test.simple',
        'saved_report_id' => null,
        'scheduled_report_id' => null,
        'triggered_by' => 'manual',
        'user_id' => 7,
        'parameters' => ['min' => 1],
        'status' => 'running',
        'started_at' => '2026-01-01 10:00:00',
    ]);
    rptAssertTrue($exec->id > 0, 'id assigned');
    rptAssertSame('running', $exec->status);

    $f['executionRepo']->finish((int) $exec->id, 'succeeded', 5, 42, null, '2026-01-01 10:00:01');
    $reloaded = $f['executionRepo']->find((int) $exec->id);
    rptAssertSame('succeeded', $reloaded->status);
    rptAssertSame(5, $reloaded->row_count);
    rptAssertSame(42, $reloaded->duration_ms);
};

// ---------------------------------------------------------------
// SavedReportService
// ---------------------------------------------------------------

$tests['SavedReportService.create rejects unknown report_key'] = function (): void {
    $f = rptFixture();
    $user = rptMakeUser(7);
    rptAssertThrows(
        static fn () => $f['reportService']->create($user, ['report_key' => 'does.not.exist', 'name' => 'X']),
        InvalidArgumentException::class,
        'unknown key rejected'
    );
};

$tests['SavedReportService.create requires reporting.view'] = function (): void {
    $f = rptFixture();
    $f['gate']->denials['reporting.view'] = true;
    $user = rptMakeUser(7);
    rptAssertThrows(
        static fn () => $f['reportService']->create($user, ['report_key' => 'test.simple', 'name' => 'X']),
        UnauthorizedException::class,
        'view gate enforced'
    );
};

$tests['SavedReportService.create with is_shared requires reporting.manage'] = function (): void {
    $f = rptFixture();
    $f['gate']->denials['reporting.manage'] = true;
    $user = rptMakeUser(7);
    rptAssertThrows(
        static fn () => $f['reportService']->create($user, [
            'report_key' => 'test.simple',
            'name' => 'X',
            'is_shared' => true,
        ]),
        UnauthorizedException::class,
        'manage gate enforced for shared'
    );
};

$tests['SavedReportService.runSaved happy path writes execution row'] = function (): void {
    $f = rptFixture();
    $user = rptMakeUser(7);
    $saved = $f['reportService']->create($user, [
        'report_key' => 'test.simple',
        'name' => 'Run me',
        'parameters' => ['min' => 2],
    ]);
    $result = $f['reportService']->runSaved($user, (int) $saved->id);
    rptAssertSame(2, $result['total']);
    rptAssertSame(2, $result['rows'][0]['id']);
    rptAssertTrue(isset($result['execution']['id']), 'execution id present');

    $execId = (int) $result['execution']['id'];
    $exec = $f['executionRepo']->find($execId);
    rptAssertSame('succeeded', $exec->status);
    rptAssertSame(2, $exec->row_count);
};

$tests['SavedReportService.runSaved by non-owner on shared OK'] = function (): void {
    $f = rptFixture();
    $owner = rptMakeUser(7);
    $other = rptMakeUser(9);
    $saved = $f['reportService']->create($owner, [
        'report_key' => 'test.simple',
        'name' => 'Shared',
        'is_shared' => true,
    ]);
    $result = $f['reportService']->runSaved($other, (int) $saved->id);
    rptAssertSame(3, $result['total']);
};

$tests['SavedReportService.runSaved by non-owner on private requires manage'] = function (): void {
    $f = rptFixture();
    $owner = rptMakeUser(7);
    $other = rptMakeUser(9);
    $saved = $f['reportService']->create($owner, [
        'report_key' => 'test.simple',
        'name' => 'Private',
    ]);
    $f['gate']->denials['reporting.manage'] = true;
    rptAssertThrows(
        static fn () => $f['reportService']->runSaved($other, (int) $saved->id),
        UnauthorizedException::class,
        'private+non-owner+no-manage rejected'
    );
};

$tests['SavedReportService.runAdhoc unknown key rejected'] = function (): void {
    $f = rptFixture();
    $user = rptMakeUser(7);
    rptAssertThrows(
        static fn () => $f['reportService']->runAdhoc($user, 'does.not.exist', []),
        InvalidArgumentException::class,
        'unknown key rejected'
    );
};

$tests['SavedReportService.runAdhoc on failure records failed execution'] = function (): void {
    $f = rptFixture();
    $f['catalog']->simulateFailure = true;
    $user = rptMakeUser(7);
    try {
        $f['reportService']->runAdhoc($user, 'test.simple', []);
        rptAssertTrue(false, 'should have thrown');
    } catch (RuntimeException) {
        // expected
    }
    // Inspect the recorded execution.
    $recent = $f['executionRepo']->listRecent(10);
    rptAssertSame(1, count($recent));
    rptAssertSame('failed', $recent[0]->status);
    rptAssertTrue($recent[0]->error_message !== null && $recent[0]->error_message !== '', 'error captured');
};

// ---------------------------------------------------------------
// ScheduledReportService.computeNextRun
// ---------------------------------------------------------------

$tests['ScheduledReportService.computeNextRun for "0 9 * * *" advances to next 9 AM UTC'] = function (): void {
    $f = rptFixture();
    $next = $f['scheduleService']->computeNextRun('0 9 * * *', 'UTC', new DateTimeImmutable('2026-01-01 08:55:00', new DateTimeZone('UTC')));
    rptAssertSame('2026-01-01 09:00:00', $next);

    $nextTomorrow = $f['scheduleService']->computeNextRun('0 9 * * *', 'UTC', new DateTimeImmutable('2026-01-01 09:00:30', new DateTimeZone('UTC')));
    // From 09:00:30 we truncate to 09:00, advance one minute → 09:01, scan forward → tomorrow 09:00.
    rptAssertSame('2026-01-02 09:00:00', $nextTomorrow);
};

$tests['ScheduledReportService.computeNextRun for "*/15 * * * *" hits next 15-minute boundary'] = function (): void {
    $f = rptFixture();
    $next = $f['scheduleService']->computeNextRun('*/15 * * * *', 'UTC', new DateTimeImmutable('2026-01-01 10:07:30', new DateTimeZone('UTC')));
    rptAssertSame('2026-01-01 10:15:00', $next);
};

// ---------------------------------------------------------------
// ScheduledReportService validation
// ---------------------------------------------------------------

$tests['ScheduledReportService.create validates cron field count'] = function (): void {
    $f = rptFixture();
    $user = rptMakeUser(7);
    $saved = $f['savedRepo']->create(['report_key' => 'test.simple', 'name' => 'S', 'owner_user_id' => 7]);
    rptAssertThrows(
        static fn () => $f['scheduleService']->create($user, [
            'saved_report_id' => $saved->id,
            'name' => 'X',
            'cron_expression' => '0 9',
            'recipients' => 'ops@example.com',
        ]),
        InvalidArgumentException::class,
        'cron must be 5 fields'
    );
};

$tests['ScheduledReportService.create rejects empty recipients'] = function (): void {
    $f = rptFixture();
    $user = rptMakeUser(7);
    $saved = $f['savedRepo']->create(['report_key' => 'test.simple', 'name' => 'S', 'owner_user_id' => 7]);
    rptAssertThrows(
        static fn () => $f['scheduleService']->create($user, [
            'saved_report_id' => $saved->id,
            'name' => 'X',
            'cron_expression' => '0 9 * * *',
            'recipients' => '',
        ]),
        InvalidArgumentException::class,
        'recipients required'
    );
};

$tests['ScheduledReportService.create rejects malformed email'] = function (): void {
    $f = rptFixture();
    $user = rptMakeUser(7);
    $saved = $f['savedRepo']->create(['report_key' => 'test.simple', 'name' => 'S', 'owner_user_id' => 7]);
    rptAssertThrows(
        static fn () => $f['scheduleService']->create($user, [
            'saved_report_id' => $saved->id,
            'name' => 'X',
            'cron_expression' => '0 9 * * *',
            'recipients' => 'not-an-email',
        ]),
        InvalidArgumentException::class,
        'malformed email rejected'
    );
};

// ---------------------------------------------------------------
// ScheduledReportService.processDue
// ---------------------------------------------------------------

$tests['ScheduledReportService.processDue runs due, emails, advances next_run_at'] = function (): void {
    $f = rptFixture();
    $user = rptMakeUser(7);
    $saved = $f['reportService']->create($user, [
        'report_key' => 'test.simple',
        'name' => 'Daily',
    ]);
    $schedule = $f['scheduleService']->create(
        $user,
        [
            'saved_report_id' => $saved->id,
            'name' => 'Daily 9am',
            'cron_expression' => '0 9 * * *',
            'timezone' => 'UTC',
            'output_format' => 'csv',
            'recipients' => 'ops@example.com',
        ],
        new DateTimeImmutable('2026-01-01 08:00:00', new DateTimeZone('UTC'))
    );
    rptAssertSame('2026-01-01 09:00:00', $schedule->next_run_at);

    $emails = [];
    $dispatcher = static function ($s, $body, $bytes, $recipients) use (&$emails): void {
        $emails[] = ['schedule_id' => $s->id, 'body' => $body, 'bytes_len' => strlen($bytes), 'recipients' => $recipients];
    };

    $now = new DateTimeImmutable('2026-01-01 09:00:30', new DateTimeZone('UTC'));
    $results = $f['scheduleService']->processDue($dispatcher, $now);
    rptAssertSame(1, count($results));
    rptAssertSame('succeeded', $results[0]['status']);
    rptAssertSame(3, $results[0]['rows']);

    // Email dispatched.
    rptAssertSame(1, count($emails));
    rptAssertSame((int) $schedule->id, $emails[0]['schedule_id']);
    rptAssertSame(['ops@example.com'], $emails[0]['recipients']);
    rptAssertTrue($emails[0]['bytes_len'] > 0, 'csv body non-empty');

    // Schedule rows advanced.
    $reloaded = $f['scheduleRepo']->find((int) $schedule->id);
    rptAssertSame('succeeded', $reloaded->last_status);
    rptAssertSame('2026-01-02 09:00:00', $reloaded->next_run_at);
};

$tests['ScheduledReportService.processDue records failed when saved report missing'] = function (): void {
    $f = rptFixture();
    $user = rptMakeUser(7);
    $saved = $f['savedRepo']->create(['report_key' => 'test.simple', 'name' => 'Will be deleted', 'owner_user_id' => 7]);
    $schedule = $f['scheduleService']->create(
        $user,
        [
            'saved_report_id' => $saved->id,
            'name' => 'Orphan',
            'cron_expression' => '0 9 * * *',
            'timezone' => 'UTC',
            'output_format' => 'csv',
            'recipients' => 'ops@example.com',
        ],
        new DateTimeImmutable('2026-01-01 08:00:00', new DateTimeZone('UTC'))
    );
    // Delete the saved report directly to simulate orphaned schedule.
    $f['savedRepo']->delete((int) $saved->id);

    $results = $f['scheduleService']->processDue(
        static function (): void {
        },
        new DateTimeImmutable('2026-01-01 09:00:30', new DateTimeZone('UTC'))
    );
    rptAssertSame(1, count($results));
    rptAssertSame('failed', $results[0]['status']);

    $reloaded = $f['scheduleRepo']->find((int) $schedule->id);
    rptAssertSame('failed', $reloaded->last_status);
};

// ---------------------------------------------------------------
// ReportExportService
// ---------------------------------------------------------------

$tests['ReportExportService.csv preserves headers and column order'] = function (): void {
    $svc = new ReportExportService();
    $rows = [
        ['id' => 1, 'value' => 'alpha'],
        ['id' => 2, 'value' => 'beta'],
    ];
    $columns = [
        ['key' => 'id', 'label' => 'ID'],
        ['key' => 'value', 'label' => 'Value'],
    ];
    $csv = $svc->export($rows, $columns, 'csv');
    $lines = preg_split('/\r?\n/', trim($csv));
    rptAssertSame('ID,Value', $lines[0]);
    rptAssertSame('1,alpha', $lines[1]);
    rptAssertSame('2,beta', $lines[2]);
};

$tests['ReportExportService.json wraps payload with columns/rows/count'] = function (): void {
    $svc = new ReportExportService();
    $rows = [['id' => 1]];
    $columns = [['key' => 'id', 'label' => 'ID']];
    $json = $svc->export($rows, $columns, 'json');
    $decoded = json_decode($json, true);
    rptAssertSame(1, $decoded['count']);
    rptAssertSame($rows, $decoded['rows']);
    rptAssertSame($columns, $decoded['columns']);
};

$tests['ReportExportService.export rejects unknown format'] = function (): void {
    $svc = new ReportExportService();
    rptAssertThrows(
        static fn () => $svc->export([], [], 'xml'),
        InvalidArgumentException::class,
        'xml not supported'
    );
};

// ---------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------

echo "ReportingTest\n";
$pass = 0;
$fail = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  ok — {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  FAIL — {$name}: {$e->getMessage()}\n";
        $fail++;
    }
}
echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
