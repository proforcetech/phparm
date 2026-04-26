<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "InspectionEscalationServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\InspectionEscalation;
use App\Models\InspectionEscalationRule;
use App\Models\User;
use App\Services\Inspection\InspectionEscalationRepository;
use App\Services\Inspection\InspectionEscalationService;
use App\Services\Inspection\InspectionEstimateBridgeService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use App\Support\Notifications\NotificationDispatcher;

/**
 * Phase 8.3 of docs/expansion-plan.md — escalation rule CRUD + rule
 * evaluation + per-item UNIQUE-backed idempotency + lifecycle
 * (acknowledge/resolve) + best-effort notification dispatch + gate
 * denials + division scoping.
 */

// ---------------------------------------------------------------------------
// SQLite connection + schema
// ---------------------------------------------------------------------------

class EscalationInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function escalationSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // NOW() stub for the bridge service portability.
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));

    $pdo->exec("CREATE TABLE divisions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NULL
    )");

    $pdo->exec("CREATE TABLE inspection_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        division_id INTEGER NULL,
        name TEXT NOT NULL,
        description TEXT NULL,
        active INTEGER NOT NULL DEFAULT 1
    )");

    $pdo->exec("CREATE TABLE inspection_sections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        display_order INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE inspection_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        section_id INTEGER NOT NULL,
        label TEXT NOT NULL,
        input_type TEXT NOT NULL,
        options TEXT NULL,
        required INTEGER NOT NULL DEFAULT 0,
        display_order INTEGER NOT NULL DEFAULT 0,
        severity TEXT NULL,
        compliance_tag_id INTEGER NULL,
        compliance_reference TEXT NULL,
        requires_photo INTEGER NOT NULL DEFAULT 0,
        requires_measurement INTEGER NOT NULL DEFAULT 0,
        measurement_unit TEXT NULL,
        pass_condition TEXT NULL,
        fail_threshold TEXT NULL,
        recommended_service_type_id INTEGER NULL,
        estimated_labor_hours REAL NULL,
        estimated_parts_cost REAL NULL
    )");

    $pdo->exec("CREATE TABLE inspection_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id INTEGER NOT NULL,
        customer_id INTEGER NOT NULL,
        vehicle_id INTEGER NULL,
        estimate_id INTEGER NULL,
        appointment_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        summary TEXT NULL,
        pdf_path TEXT NULL,
        completed_by INTEGER NULL,
        completed_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE inspection_report_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_id INTEGER NOT NULL,
        template_item_id INTEGER NOT NULL,
        label TEXT NOT NULL,
        response TEXT NOT NULL,
        note TEXT NULL,
        created_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE inspection_compliance_tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL,
        label TEXT NOT NULL,
        description TEXT NULL,
        regulatory_body TEXT NOT NULL DEFAULT 'other',
        division_id INTEGER NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_by_user_id INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE inspection_estimate_conversions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        inspection_report_id INTEGER NOT NULL,
        inspection_item_id INTEGER NOT NULL,
        estimate_id INTEGER NOT NULL,
        estimate_job_id INTEGER NOT NULL,
        converted_by INTEGER NULL,
        created_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE inspection_recommendations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        inspection_report_id INTEGER NOT NULL,
        report_item_id INTEGER NOT NULL,
        severity TEXT NOT NULL DEFAULT 'medium',
        recommended_action TEXT NULL,
        estimated_cost REAL NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        processed_by INTEGER NULL,
        processed_at TEXT NULL,
        created_at TEXT NULL
    )");

    // Phase 8.3 tables under test.
    $pdo->exec("CREATE TABLE inspection_escalation_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        division_id INTEGER NULL,
        name TEXT NOT NULL,
        trigger_severity TEXT NOT NULL DEFAULT 'critical',
        compliance_tag_id INTEGER NULL,
        assign_to_user_id INTEGER NULL,
        assign_to_role TEXT NULL,
        notify_via TEXT NULL,
        notification_template TEXT NULL,
        priority TEXT NOT NULL DEFAULT 'normal',
        require_acknowledgment INTEGER NOT NULL DEFAULT 1,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_by_user_id INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE inspection_escalations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rule_id INTEGER NOT NULL,
        inspection_report_id INTEGER NOT NULL,
        inspection_report_item_id INTEGER NOT NULL,
        priority TEXT NOT NULL DEFAULT 'normal',
        severity TEXT NOT NULL DEFAULT 'high',
        assigned_to_user_id INTEGER NULL,
        assigned_to_role TEXT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        notification_status TEXT NULL,
        notification_error TEXT NULL,
        acknowledged_by_user_id INTEGER NULL,
        acknowledged_at TEXT NULL,
        resolved_by_user_id INTEGER NULL,
        resolved_at TEXT NULL,
        resolution_note TEXT NULL,
        created_by_user_id INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE (rule_id, inspection_report_id, inspection_report_item_id)
    )");

    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class EscalationFakeAudit extends AuditLogger
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

class EscalationPermissiveGate extends AccessGate
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
 * Drop-in NotificationDispatcher that records sends and can be
 * configured to throw on demand (simulate SMTP failure).
 */
class EscalationFakeNotifier extends NotificationDispatcher
{
    /** @var array<int, array<string, mixed>> */
    public array $sentMail = [];
    /** @var array<int, array<string, mixed>> */
    public array $sentSms = [];
    public bool $throwOnMail = false;
    public function __construct()
    {
        // Parent expects config+engine+logs, but we override both send
        // methods so we don't need them.
    }
    public function sendMail(string $templateKey, string $to, array $data, ?string $subject = null): void
    {
        if ($this->throwOnMail) {
            throw new RuntimeException('simulated SMTP failure');
        }
        $this->sentMail[] = compact('templateKey', 'to', 'data', 'subject');
    }
    public function sendSms(string $templateKey, string $to, array $data): void
    {
        $this->sentSms[] = compact('templateKey', 'to', 'data');
    }
}

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function makeEscalationFixture(?EscalationFakeNotifier $notifier = null): array
{
    $pdo = escalationSetUpDatabase();
    $conn = new EscalationInMemoryConnection($pdo);
    $audit = new EscalationFakeAudit();
    $gate = new EscalationPermissiveGate();
    $repo = new InspectionEscalationRepository($conn);
    $bridge = new InspectionEstimateBridgeService($conn, $audit, null);
    $service = new InspectionEscalationService($conn, $repo, $bridge, $gate, $audit, $notifier);

    $actor = new User();
    $actor->id = 7;

    $pdo->exec("INSERT INTO divisions (id, name) VALUES (1, 'Auto'), (2, 'Fleet')");
    $pdo->exec("INSERT INTO users (id, name, email) VALUES (7, 'Tech Lead', 'lead@example.com'), (8, 'DOT Officer', 'dot@example.com'), (9, 'No Email', NULL)");
    $pdo->exec("INSERT INTO inspection_templates (id, division_id, name, active) VALUES (1, 2, 'Fleet PM', 1)");
    $pdo->exec("INSERT INTO inspection_sections (id, template_id, name, display_order) VALUES (1, 1, 'Brakes', 1)");

    // Two failing items + one passing item.
    // Item 10: boolean → severity 'high' when failed
    $pdo->exec("INSERT INTO inspection_items (id, section_id, label, input_type, fail_threshold, estimated_labor_hours, estimated_parts_cost, severity)
                VALUES (10, 1, 'Brake pad OK?', 'boolean', 'no', 1.5, 25.0, 'major')");
    // Item 11: number_scale score 1-10 → severity 'critical' when score <= 2 (ratio <= 0.2)
    $pdo->exec("INSERT INTO inspection_items (id, section_id, label, input_type, fail_threshold, options, estimated_labor_hours, estimated_parts_cost, severity, compliance_tag_id)
                VALUES (11, 1, 'Rotor 1-10', 'number_scale', '3', '{\"max\":10}', 2.0, 80.0, 'critical', NULL)");
    // Item 12: passing boolean
    $pdo->exec("INSERT INTO inspection_items (id, section_id, label, input_type, fail_threshold)
                VALUES (12, 1, 'Parking brake?', 'boolean', 'no')");

    return [
        'service' => $service,
        'repo' => $repo,
        'pdo' => $pdo,
        'conn' => $conn,
        'gate' => $gate,
        'audit' => $audit,
        'actor' => $actor,
        'notifier' => $notifier,
        'templateId' => 1,
        'fleetDivisionId' => 2,
    ];
}

/**
 * Seed a completed report with two failing items + one passing.
 */
function escalationSeedReport(array $f): int
{
    $pdo = $f['pdo'];
    $pdo->exec("INSERT INTO inspection_reports (id, template_id, customer_id, vehicle_id, status)
                VALUES (100, 1, 42, 99, 'completed')");
    $pdo->exec("INSERT INTO inspection_report_items (id, report_id, template_item_id, label, response)
                VALUES (1000, 100, 10, 'Brake pad OK?', 'no')");  // → high
    $pdo->exec("INSERT INTO inspection_report_items (id, report_id, template_item_id, label, response)
                VALUES (1001, 100, 11, 'Rotor 1-10', '1')");       // → critical
    $pdo->exec("INSERT INTO inspection_report_items (id, report_id, template_item_id, label, response)
                VALUES (1002, 100, 12, 'Parking brake?', 'yes')"); // passing
    return 100;
}

// ---------------------------------------------------------------------------
// Harness
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

echo "InspectionEscalationServiceTest\n";

// ---------------------------------------------------------------------------
// Rule CRUD + validation
// ---------------------------------------------------------------------------

runCase('createRule happy path with defaults', function () {
    $f = makeEscalationFixture();
    $r = $f['service']->createRule($f['actor'], [
        'name' => 'Critical brake escalation',
        'assign_to_role' => 'manager',
    ]);
    assertTrue($r['id'] > 0, 'id assigned');
    assertEquals('critical', $r['trigger_severity'], 'severity default critical');
    assertEquals('normal', $r['priority'], 'priority default normal');
    assertEquals('manager', $r['assign_to_role'], 'role persisted');
    assertTrue($r['is_active'], 'active default');
    assertTrue($r['require_acknowledgment'], 'ack default');
    $created = array_filter($f['audit']->entries,
        fn(AuditEntry $e) => $e->action === 'inspection.escalation_rule.created');
    assertEquals(1, count($created), 'one audit entry');
});

runCase('createRule normalizes severity/priority/notify_via casing', function () {
    $f = makeEscalationFixture();
    $r = $f['service']->createRule($f['actor'], [
        'name' => 'test',
        'trigger_severity' => 'HIGH',
        'priority' => 'URGENT',
        'notify_via' => 'EMAIL',
        'notification_template' => 'inspection.escalation.critical',
        'assign_to_user_id' => 8,
    ]);
    assertEquals('high', $r['trigger_severity'], 'sev lowercased');
    assertEquals('urgent', $r['priority'], 'priority lowercased');
    assertEquals('email', $r['notify_via'], 'notify_via lowercased');
});

runCase('createRule rejects unknown severity', function () {
    $f = makeEscalationFixture();
    try {
        $f['service']->createRule($f['actor'], [
            'name' => 'x', 'trigger_severity' => 'catastrophic', 'assign_to_role' => 'mgr',
        ]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'trigger_severity must be one of'), 'right msg');
    }
});

runCase('createRule rejects unknown priority', function () {
    $f = makeEscalationFixture();
    try {
        $f['service']->createRule($f['actor'], [
            'name' => 'x', 'priority' => 'nuclear', 'assign_to_role' => 'mgr',
        ]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'priority must be one of'), 'right msg');
    }
});

runCase('createRule rejects unknown notify_via', function () {
    $f = makeEscalationFixture();
    try {
        $f['service']->createRule($f['actor'], [
            'name' => 'x', 'notify_via' => 'pigeon', 'assign_to_role' => 'mgr',
        ]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'notify_via must be one of'), 'right msg');
    }
});

runCase('createRule rejects empty name', function () {
    $f = makeEscalationFixture();
    try {
        $f['service']->createRule($f['actor'], ['name' => '  ', 'assign_to_role' => 'mgr']);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'name is required'), 'right msg');
    }
});

runCase('createRule requires user or role assignment', function () {
    $f = makeEscalationFixture();
    try {
        $f['service']->createRule($f['actor'], ['name' => 'no routing']);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'assign_to_user_id or assign_to_role'), 'right msg');
    }
});

runCase('createRule rejects unknown compliance_tag_id', function () {
    $f = makeEscalationFixture();
    try {
        $f['service']->createRule($f['actor'], [
            'name' => 'x', 'compliance_tag_id' => 999, 'assign_to_role' => 'mgr',
        ]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), '999 not found'), 'right msg');
    }
});

runCase('updateRule partial patch preserves division_id', function () {
    $f = makeEscalationFixture();
    $r = $f['service']->createRule($f['actor'], [
        'name' => 'orig', 'division_id' => 2, 'assign_to_role' => 'mgr',
    ]);
    $updated = $f['service']->updateRule($f['actor'], $r['id'], [
        'name' => 'renamed', 'priority' => 'high',
    ]);
    assertEquals(2, $updated['division_id'], 'division preserved');
    assertEquals('renamed', $updated['name'], 'name updated');
    assertEquals('high', $updated['priority'], 'priority updated');
});

runCase('updateRule unknown id throws', function () {
    $f = makeEscalationFixture();
    try {
        $f['service']->updateRule($f['actor'], 9999, ['name' => 'x']);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), '9999 not found'), 'right msg');
    }
});

runCase('deleteRule is idempotent', function () {
    $f = makeEscalationFixture();
    $r = $f['service']->createRule($f['actor'], ['name' => 'x', 'assign_to_role' => 'mgr']);
    $f['service']->deleteRule($f['actor'], $r['id']);
    $f['service']->deleteRule($f['actor'], $r['id']); // second no-op
    $deletes = array_filter($f['audit']->entries,
        fn(AuditEntry $e) => $e->action === 'inspection.escalation_rule.deleted');
    assertEquals(1, count($deletes), 'one delete audit only');
});

runCase('listRules includes global + own division', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], ['name' => 'global', 'division_id' => null, 'assign_to_role' => 'mgr']);
    $f['service']->createRule($f['actor'], ['name' => 'fleet', 'division_id' => 2, 'assign_to_role' => 'mgr']);
    $f['service']->createRule($f['actor'], ['name' => 'auto', 'division_id' => 1, 'assign_to_role' => 'mgr']);
    $fleet = $f['service']->listRules($f['actor'], 2);
    $names = array_map(fn($r) => $r['name'], $fleet);
    sort($names);
    assertEquals(['fleet', 'global'], $names, 'fleet sees its own + global only');
});

// ---------------------------------------------------------------------------
// Evaluation + idempotency
// ---------------------------------------------------------------------------

runCase('evaluateReport creates escalations for matching items', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'Any failure',
        'division_id' => 2,
        'trigger_severity' => 'low',
        'assign_to_user_id' => 8,
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    assertEquals(2, count($created), 'two escalations (one per failing item)');
    // Both should carry assigned user id
    foreach ($created as $e) {
        assertEquals(8, $e['assigned_to_user_id'], 'user routed');
        assertEquals(InspectionEscalation::STATUS_PENDING, $e['status'], 'status pending');
    }
    $logs = array_filter($f['audit']->entries,
        fn(AuditEntry $e) => $e->action === 'inspection.escalation.created');
    assertEquals(2, count($logs), 'two created audits');
});

runCase('evaluateReport is idempotent (UNIQUE guard)', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'Any',
        'division_id' => 2,
        'trigger_severity' => 'low',
        'assign_to_role' => 'mgr',
    ]);
    $reportId = escalationSeedReport($f);
    $first = $f['service']->evaluateReport($f['actor'], $reportId);
    $second = $f['service']->evaluateReport($f['actor'], $reportId);
    assertEquals(2, count($first), 'first call creates 2');
    assertEquals(0, count($second), 'second call creates 0');
});

runCase('evaluateReport respects trigger_severity threshold', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'Crit only',
        'division_id' => 2,
        'trigger_severity' => 'critical',
        'assign_to_role' => 'dot',
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    assertEquals(1, count($created), 'only critical-severity item triggers');
    assertEquals('critical', $created[0]['severity'], 'escalation tagged critical');
});

runCase('evaluateReport skips inactive rules', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'disabled',
        'division_id' => 2,
        'trigger_severity' => 'low',
        'assign_to_role' => 'mgr',
        'is_active' => false,
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    assertEquals(0, count($created), 'inactive rule produces no escalations');
});

runCase('evaluateReport filters by compliance_tag_id when set', function () {
    $f = makeEscalationFixture();
    $f['pdo']->exec("INSERT INTO inspection_compliance_tags (id, code, label) VALUES (5, 'DOT-HOS', 'DOT Hours of Service')");
    // Tag item 11 only.
    $f['pdo']->exec("UPDATE inspection_items SET compliance_tag_id = 5 WHERE id = 11");
    $f['service']->createRule($f['actor'], [
        'name' => 'DOT only',
        'division_id' => 2,
        'trigger_severity' => 'low',
        'compliance_tag_id' => 5,
        'assign_to_role' => 'dot',
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    assertEquals(1, count($created), 'only tagged item creates escalation');
});

runCase('evaluateReport on unknown report throws', function () {
    $f = makeEscalationFixture();
    try {
        $f['service']->evaluateReport($f['actor'], 9999);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'not found'), 'right msg');
    }
});

runCase('evaluateReport with no failed items returns empty', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'any', 'division_id' => 2, 'trigger_severity' => 'low', 'assign_to_role' => 'mgr',
    ]);
    $f['pdo']->exec("INSERT INTO inspection_reports (id, template_id, customer_id, status) VALUES (200, 1, 42, 'completed')");
    // Only passing item
    $f['pdo']->exec("INSERT INTO inspection_report_items (id, report_id, template_item_id, label, response)
                    VALUES (2000, 200, 12, 'Parking brake?', 'yes')");
    $created = $f['service']->evaluateReport($f['actor'], 200);
    assertEquals(0, count($created), 'no failed items → no escalations');
});

runCase('evaluateReportOnCompletion swallows bad config', function () {
    $f = makeEscalationFixture();
    $got = $f['service']->evaluateReportOnCompletion(9999, 7);
    assertEquals([], $got, 'returns empty array instead of throwing');
    $hookFailures = array_filter($f['audit']->entries,
        fn(AuditEntry $e) => $e->action === 'inspection.escalation.hook_failure');
    assertEquals(1, count($hookFailures), 'hook_failure audit logged');
});

runCase('evaluateReport scoping respects division', function () {
    $f = makeEscalationFixture();
    // Global matches, auto-division (1) doesn't, fleet (2) matches
    $f['service']->createRule($f['actor'], [
        'name' => 'glob', 'division_id' => null, 'trigger_severity' => 'low', 'assign_to_role' => 'x',
    ]);
    $f['service']->createRule($f['actor'], [
        'name' => 'auto', 'division_id' => 1, 'trigger_severity' => 'low', 'assign_to_role' => 'x',
    ]);
    $f['service']->createRule($f['actor'], [
        'name' => 'fleet', 'division_id' => 2, 'trigger_severity' => 'low', 'assign_to_role' => 'x',
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    // Expect 4 (2 items × 2 matching rules: global + fleet). Auto rule skipped.
    assertEquals(4, count($created), 'only global + fleet rules fire for template in fleet division');
});

// ---------------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------------

runCase('notification dispatch: email to user succeeds', function () {
    $notifier = new EscalationFakeNotifier();
    $f = makeEscalationFixture($notifier);
    $f['service']->createRule($f['actor'], [
        'name' => 'crit email',
        'division_id' => 2,
        'trigger_severity' => 'critical',
        'assign_to_user_id' => 8,
        'notify_via' => 'email',
        'notification_template' => 'inspection.escalation.critical',
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    assertEquals(1, count($created), 'one escalation');
    assertEquals(InspectionEscalation::NOTIFY_STATUS_SENT, $created[0]['notification_status'], 'sent');
    assertEquals(1, count($notifier->sentMail), 'one mail dispatched');
    assertEquals('dot@example.com', $notifier->sentMail[0]['to'], 'to user email');
});

runCase('notification dispatch: role-routed rule skips with reason', function () {
    $notifier = new EscalationFakeNotifier();
    $f = makeEscalationFixture($notifier);
    $f['service']->createRule($f['actor'], [
        'name' => 'role queue',
        'division_id' => 2,
        'trigger_severity' => 'critical',
        'assign_to_role' => 'dot_officer',
        'notify_via' => 'email',
        'notification_template' => 'x',
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    assertEquals(InspectionEscalation::NOTIFY_STATUS_SKIPPED, $created[0]['notification_status'], 'skipped');
    assertEquals(0, count($notifier->sentMail), 'no mail dispatched');
});

runCase('notification dispatch: user without email → failed', function () {
    $notifier = new EscalationFakeNotifier();
    $f = makeEscalationFixture($notifier);
    $f['service']->createRule($f['actor'], [
        'name' => 'no-email user',
        'division_id' => 2,
        'trigger_severity' => 'critical',
        'assign_to_user_id' => 9, // user 9 has NULL email
        'notify_via' => 'email',
        'notification_template' => 'x',
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    assertEquals(InspectionEscalation::NOTIFY_STATUS_FAILED, $created[0]['notification_status'], 'failed');
    assertTrue(str_contains((string) $created[0]['notification_error'], 'no email'), 'error message set');
});

runCase('notification dispatch: dispatcher throws → failed but escalation kept', function () {
    $notifier = new EscalationFakeNotifier();
    $notifier->throwOnMail = true;
    $f = makeEscalationFixture($notifier);
    $f['service']->createRule($f['actor'], [
        'name' => 'smtp fail',
        'division_id' => 2,
        'trigger_severity' => 'critical',
        'assign_to_user_id' => 8,
        'notify_via' => 'email',
        'notification_template' => 'x',
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    assertEquals(1, count($created), 'escalation still created');
    assertEquals(InspectionEscalation::NOTIFY_STATUS_FAILED, $created[0]['notification_status'], 'failed status');
    assertTrue(str_contains((string) $created[0]['notification_error'], 'simulated SMTP'), 'error captured');
});

runCase('notification: internal notify_via records skipped no-error', function () {
    $notifier = new EscalationFakeNotifier();
    $f = makeEscalationFixture($notifier);
    $f['service']->createRule($f['actor'], [
        'name' => 'internal',
        'division_id' => 2,
        'trigger_severity' => 'critical',
        'assign_to_user_id' => 8,
        'notify_via' => 'internal',
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    assertEquals(InspectionEscalation::NOTIFY_STATUS_SKIPPED, $created[0]['notification_status'], 'skipped');
    assertEquals(null, $created[0]['notification_error'], 'no error');
    assertEquals(0, count($notifier->sentMail), 'no mail');
});

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------

runCase('acknowledge moves pending → acknowledged', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'any', 'division_id' => 2, 'trigger_severity' => 'critical', 'assign_to_user_id' => 8,
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    $escId = $created[0]['id'];
    $acked = $f['service']->acknowledge($f['actor'], $escId);
    assertEquals(InspectionEscalation::STATUS_ACKNOWLEDGED, $acked['status'], 'status advanced');
    assertEquals(7, $acked['acknowledged_by_user_id'], 'by actor');
    assertTrue($acked['acknowledged_at'] !== null, 'timestamp set');
});

runCase('acknowledge is idempotent if already acknowledged', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'any', 'division_id' => 2, 'trigger_severity' => 'critical', 'assign_to_user_id' => 8,
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    $escId = $created[0]['id'];
    $f['service']->acknowledge($f['actor'], $escId);
    $second = $f['service']->acknowledge($f['actor'], $escId); // no-op
    assertEquals(InspectionEscalation::STATUS_ACKNOWLEDGED, $second['status'], 'still acknowledged');
    $acks = array_filter($f['audit']->entries,
        fn(AuditEntry $e) => $e->action === 'inspection.escalation.acknowledged');
    assertEquals(1, count($acks), 'audit logged only once');
});

runCase('resolve moves pending → resolved (skipping ack)', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'any', 'division_id' => 2, 'trigger_severity' => 'critical', 'assign_to_user_id' => 8,
    ]);
    $reportId = escalationSeedReport($f);
    $created = $f['service']->evaluateReport($f['actor'], $reportId);
    $escId = $created[0]['id'];
    $resolved = $f['service']->resolve($f['actor'], $escId, 'Signed off by DOT officer on site');
    assertEquals(InspectionEscalation::STATUS_RESOLVED, $resolved['status'], 'status resolved');
    assertEquals('Signed off by DOT officer on site', $resolved['resolution_note'], 'note stored');
    assertTrue($resolved['resolved_at'] !== null, 'resolved_at set');
});

runCase('resolve on unknown id throws', function () {
    $f = makeEscalationFixture();
    try {
        $f['service']->resolve($f['actor'], 9999, 'n/a');
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'not found'), 'right msg');
    }
});

runCase('resolve enforces note length limit', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'any', 'division_id' => 2, 'trigger_severity' => 'critical', 'assign_to_user_id' => 8,
    ]);
    $reportId = escalationSeedReport($f);
    $escId = $f['service']->evaluateReport($f['actor'], $reportId)[0]['id'];
    $tooLong = str_repeat('a', InspectionEscalation::RESOLUTION_NOTE_MAX_LEN + 1);
    try {
        $f['service']->resolve($f['actor'], $escId, $tooLong);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'resolution note exceeds'), 'right msg');
    }
});

runCase('listOpenForMe returns pending + acknowledged assigned to actor', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'to me',
        'division_id' => 2,
        'trigger_severity' => 'low',
        'assign_to_user_id' => 7, // actor
    ]);
    $reportId = escalationSeedReport($f);
    $f['service']->evaluateReport($f['actor'], $reportId);
    $open = $f['service']->listOpenForMe($f['actor']);
    assertEquals(2, count($open), 'both failing items routed to actor');
    // Resolve one — it should drop out
    $f['service']->resolve($f['actor'], $open[0]['id'], 'done');
    $openAfter = $f['service']->listOpenForMe($f['actor']);
    assertEquals(1, count($openAfter), 'resolved escalation removed from open');
});

runCase('listOpenForRole returns only matching role queue', function () {
    $f = makeEscalationFixture();
    $f['service']->createRule($f['actor'], [
        'name' => 'dot queue',
        'division_id' => 2,
        'trigger_severity' => 'low',
        'assign_to_role' => 'dot',
    ]);
    $f['service']->createRule($f['actor'], [
        'name' => 'mgr queue',
        'division_id' => 2,
        'trigger_severity' => 'low',
        'assign_to_role' => 'manager',
    ]);
    $reportId = escalationSeedReport($f);
    $f['service']->evaluateReport($f['actor'], $reportId);
    $dot = $f['service']->listOpenForRole($f['actor'], 'dot');
    $mgr = $f['service']->listOpenForRole($f['actor'], 'manager');
    $other = $f['service']->listOpenForRole($f['actor'], 'ceo');
    assertEquals(2, count($dot), 'two dot escalations');
    assertEquals(2, count($mgr), 'two manager escalations');
    assertEquals(0, count($other), 'unused role empty');
});

// ---------------------------------------------------------------------------
// Gate denials
// ---------------------------------------------------------------------------

runCase('createRule denied without inspections.manage', function () {
    $f = makeEscalationFixture();
    $f['gate']->denials['inspections.manage'] = true;
    try {
        $f['service']->createRule($f['actor'], ['name' => 'x', 'assign_to_role' => 'mgr']);
        throw new RuntimeException('expected denial');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'inspections.manage'), 'right perm');
    }
});

runCase('listRules denied without inspections.view', function () {
    $f = makeEscalationFixture();
    $f['gate']->denials['inspections.view'] = true;
    try {
        $f['service']->listRules($f['actor'], 2);
        throw new RuntimeException('expected denial');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'inspections.view'), 'right perm');
    }
});

runCase('evaluateReport denied without inspections.manage', function () {
    $f = makeEscalationFixture();
    $f['gate']->denials['inspections.manage'] = true;
    try {
        $f['service']->evaluateReport($f['actor'], 100);
        throw new RuntimeException('expected denial');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'inspections.manage'), 'right perm');
    }
});

runCase('acknowledge denied without inspections.view', function () {
    $f = makeEscalationFixture();
    $f['gate']->denials['inspections.view'] = true;
    try {
        $f['service']->acknowledge($f['actor'], 1);
        throw new RuntimeException('expected denial');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'inspections.view'), 'right perm');
    }
});

echo "\n{$cases} cases, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
