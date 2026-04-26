<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "InspectionDeficiencyAutoServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\InspectionAutoGenerationPolicy;
use App\Models\User;
use App\Services\Inspection\InspectionAutoGenerationPolicyRepository;
use App\Services\Inspection\InspectionDeficiencyAutoService;
use App\Services\Inspection\InspectionEstimateBridgeService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 8.2 of docs/expansion-plan.md — deficiency → auto-WO/quote
 * generation. SQLite-in-memory exercises: policy CRUD + validation,
 * severity threshold matching, compliance-tag filtering, auto-estimate
 * creation, sub-estimate-on-workorder routing, idempotency (hasRun
 * marker), hook best-effort swallow, division scoping, gate denials.
 */

// ---------------------------------------------------------------------------
// SQLite connection + schema
// ---------------------------------------------------------------------------

class AutoPolicyInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function autoPolicySetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // NOW() function stub for bridge service
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));

    $pdo->exec("CREATE TABLE divisions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");

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

    $pdo->exec("CREATE TABLE estimates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        number TEXT NOT NULL,
        customer_id INTEGER NULL,
        vehicle_id INTEGER NULL,
        workorder_id INTEGER NULL,
        estimate_type TEXT NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        internal_notes TEXT NULL,
        subtotal REAL NOT NULL DEFAULT 0,
        tax REAL NOT NULL DEFAULT 0,
        grand_total REAL NOT NULL DEFAULT 0,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE estimate_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estimate_id INTEGER NOT NULL,
        service_type_id INTEGER NULL,
        title TEXT NULL,
        notes TEXT NULL,
        reference TEXT NULL,
        customer_status TEXT NULL,
        subtotal REAL NOT NULL DEFAULT 0,
        tax REAL NOT NULL DEFAULT 0,
        total REAL NOT NULL DEFAULT 0,
        display_order INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE estimate_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estimate_job_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        description TEXT NULL,
        quantity REAL NOT NULL DEFAULT 1,
        unit_price REAL NOT NULL DEFAULT 0,
        taxable INTEGER NOT NULL DEFAULT 0,
        line_total REAL NOT NULL DEFAULT 0,
        status TEXT NULL
    )");

    $pdo->exec("CREATE TABLE workorders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NULL,
        vehicle_id INTEGER NULL,
        estimate_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'open'
    )");

    $pdo->exec("CREATE TABLE service_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NULL)");

    $pdo->exec("CREATE TABLE inspection_auto_generation_policies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        division_id INTEGER NULL,
        name TEXT NOT NULL,
        trigger_severity TEXT NOT NULL DEFAULT 'high',
        compliance_tag_id INTEGER NULL,
        target_kind TEXT NOT NULL DEFAULT 'auto',
        min_failed_items INTEGER NOT NULL DEFAULT 1,
        require_customer_approval INTEGER NOT NULL DEFAULT 1,
        auto_assign_to_technician_id INTEGER NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_by_user_id INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE inspection_auto_generation_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        policy_id INTEGER NOT NULL,
        inspection_report_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'triggered',
        estimate_id INTEGER NULL,
        sub_estimate_id INTEGER NULL,
        workorder_id INTEGER NULL,
        items_generated INTEGER NOT NULL DEFAULT 0,
        note TEXT NULL,
        triggered_by_user_id INTEGER NULL,
        created_at TEXT NULL,
        UNIQUE (policy_id, inspection_report_id)
    )");

    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class AutoPolicyFakeAudit extends AuditLogger
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

class AutoPolicyPermissiveGate extends AccessGate
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
 * @return array<string, mixed>
 */
function makeAutoPolicyFixture(): array
{
    $pdo = autoPolicySetUpDatabase();
    $conn = new AutoPolicyInMemoryConnection($pdo);
    $audit = new AutoPolicyFakeAudit();
    $gate = new AutoPolicyPermissiveGate();
    $policies = new InspectionAutoGenerationPolicyRepository($conn);
    $bridge = new InspectionEstimateBridgeService($conn, $audit, null);
    $service = new InspectionDeficiencyAutoService($conn, $policies, $bridge, $gate, $audit);

    $actor = new User();
    $actor->id = 7;

    // Seed template + section + items.
    $pdo->exec("INSERT INTO divisions (id, name) VALUES (1, 'Auto'), (2, 'Fleet')");
    $pdo->exec("INSERT INTO inspection_templates (id, division_id, name, active) VALUES (1, 2, 'Fleet PM', 1)");
    $pdo->exec("INSERT INTO inspection_sections (id, template_id, name, display_order) VALUES (1, 1, 'Brakes', 1)");

    // Item 1: boolean fail_threshold 'no' — will fail with severity high
    $pdo->exec("INSERT INTO inspection_items (id, section_id, label, input_type, fail_threshold, estimated_labor_hours, estimated_parts_cost, severity)
                VALUES (10, 1, 'Brake pad thickness OK?', 'boolean', 'no', 1.5, 25.0, 'major')");
    // Item 2: number_scale (score 1-10) — failing score 1 → critical
    $pdo->exec("INSERT INTO inspection_items (id, section_id, label, input_type, fail_threshold, options, estimated_labor_hours, estimated_parts_cost, severity)
                VALUES (11, 1, 'Rotor condition 1-10', 'number_scale', '3', '{\"max\":10}', 2.0, 80.0, 'critical')");
    // Item 3: boolean but passing
    $pdo->exec("INSERT INTO inspection_items (id, section_id, label, input_type, fail_threshold, estimated_labor_hours, estimated_parts_cost)
                VALUES (12, 1, 'Parking brake OK?', 'boolean', 'no', 0.5, 0.0)");

    return [
        'service' => $service,
        'policies' => $policies,
        'bridge' => $bridge,
        'pdo' => $pdo,
        'conn' => $conn,
        'gate' => $gate,
        'audit' => $audit,
        'actor' => $actor,
        'templateId' => 1,
        'fleetDivisionId' => 2,
    ];
}

/**
 * Seed a completed report with two failing items and one passing item.
 */
function seedFailedReport(array $f, ?int $workorderId = null): int
{
    $pdo = $f['pdo'];
    $estimateId = null;
    if ($workorderId !== null) {
        $pdo->exec("INSERT INTO estimates (id, number, customer_id, vehicle_id, status) VALUES (500, 'EST-WO-500', 42, 99, 'approved')");
        $pdo->exec("INSERT INTO workorders (id, customer_id, vehicle_id, estimate_id, status) VALUES ({$workorderId}, 42, 99, 500, 'in_progress')");
        $estimateId = 500;
    }

    $pdo->exec("INSERT INTO inspection_reports (id, template_id, customer_id, vehicle_id, estimate_id, status)
                VALUES (100, 1, 42, 99, " . ($estimateId === null ? 'NULL' : (string) $estimateId) . ", 'completed')");

    // Failed boolean (item 10)
    $pdo->exec("INSERT INTO inspection_report_items (id, report_id, template_item_id, label, response)
                VALUES (1000, 100, 10, 'Brake pad thickness OK?', 'no')");
    // Failed number_scale with score 1 — critical
    $pdo->exec("INSERT INTO inspection_report_items (id, report_id, template_item_id, label, response)
                VALUES (1001, 100, 11, 'Rotor condition 1-10', '1')");
    // Passing boolean
    $pdo->exec("INSERT INTO inspection_report_items (id, report_id, template_item_id, label, response)
                VALUES (1002, 100, 12, 'Parking brake OK?', 'yes')");

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

echo "InspectionDeficiencyAutoServiceTest\n";

// ---------------------------------------------------------------------------
// Policy CRUD + validation
// ---------------------------------------------------------------------------

runCase('createPolicy happy path with defaults', function () {
    $f = makeAutoPolicyFixture();
    $p = $f['service']->createPolicy($f['actor'], [
        'name' => 'High-severity auto-quote',
        'trigger_severity' => 'high',
    ]);
    assertTrue($p['id'] > 0, 'id assigned');
    assertEquals('auto', $p['target_kind'], 'target_kind defaults to auto');
    assertEquals(1, $p['min_failed_items'], 'min defaults to 1');
    assertTrue($p['is_active'], 'active default true');
    assertEquals(true, $p['require_customer_approval'], 'require approval default true');
    $created = array_filter($f['audit']->entries,
        fn(AuditEntry $e) => $e->action === 'inspection.auto_policy.created');
    assertTrue(count($created) === 1, 'one audit entry');
});

runCase('createPolicy normalizes severity + target casing', function () {
    $f = makeAutoPolicyFixture();
    $p = $f['service']->createPolicy($f['actor'], [
        'name' => 'test',
        'trigger_severity' => 'CRITICAL',
        'target_kind' => 'ESTIMATE',
    ]);
    assertEquals('critical', $p['trigger_severity'], 'severity lowercased');
    assertEquals('estimate', $p['target_kind'], 'target_kind lowercased');
});

runCase('createPolicy rejects unknown severity', function () {
    $f = makeAutoPolicyFixture();
    try {
        $f['service']->createPolicy($f['actor'], ['name' => 'x', 'trigger_severity' => 'catastrophic']);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'trigger_severity must be one of'), 'right message');
    }
});

runCase('createPolicy rejects unknown target_kind', function () {
    $f = makeAutoPolicyFixture();
    try {
        $f['service']->createPolicy($f['actor'], ['name' => 'x', 'target_kind' => 'invoice']);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'target_kind must be one of'), 'right message');
    }
});

runCase('createPolicy rejects empty name', function () {
    $f = makeAutoPolicyFixture();
    try {
        $f['service']->createPolicy($f['actor'], ['name' => '  ']);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'name is required'), 'right message');
    }
});

runCase('createPolicy rejects unknown compliance_tag_id', function () {
    $f = makeAutoPolicyFixture();
    try {
        $f['service']->createPolicy($f['actor'], ['name' => 'x', 'compliance_tag_id' => 999]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), '999 not found'), 'right message');
    }
});

runCase('createPolicy rejects min_failed_items < 1', function () {
    $f = makeAutoPolicyFixture();
    try {
        $f['service']->createPolicy($f['actor'], ['name' => 'x', 'min_failed_items' => 0]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'min_failed_items must be >= 1'), 'right message');
    }
});

runCase('updatePolicy partial patch preserves division_id', function () {
    $f = makeAutoPolicyFixture();
    $p = $f['service']->createPolicy($f['actor'], [
        'name' => 'orig', 'division_id' => 2, 'trigger_severity' => 'high',
    ]);
    $updated = $f['service']->updatePolicy($f['actor'], $p['id'], [
        'name' => 'renamed', 'trigger_severity' => 'critical',
    ]);
    assertEquals(2, $updated['division_id'], 'division preserved');
    assertEquals('renamed', $updated['name'], 'name updated');
    assertEquals('critical', $updated['trigger_severity'], 'severity updated');
});

runCase('updatePolicy unknown id throws', function () {
    $f = makeAutoPolicyFixture();
    try {
        $f['service']->updatePolicy($f['actor'], 9999, ['name' => 'x']);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), '9999 not found'), 'right message');
    }
});

runCase('deletePolicy is idempotent', function () {
    $f = makeAutoPolicyFixture();
    $p = $f['service']->createPolicy($f['actor'], ['name' => 'x']);
    $f['service']->deletePolicy($f['actor'], $p['id']);
    $f['service']->deletePolicy($f['actor'], $p['id']); // already-absent
    $deletes = array_filter($f['audit']->entries,
        fn(AuditEntry $e) => $e->action === 'inspection.auto_policy.deleted');
    assertTrue(count($deletes) === 1, 'one delete audit only');
});

runCase('listPolicies includes global + own division', function () {
    $f = makeAutoPolicyFixture();
    $f['service']->createPolicy($f['actor'], ['name' => 'global', 'division_id' => null]);
    $f['service']->createPolicy($f['actor'], ['name' => 'fleet', 'division_id' => 2]);
    $f['service']->createPolicy($f['actor'], ['name' => 'auto', 'division_id' => 1]);
    $fleetList = $f['service']->listPolicies($f['actor'], 2);
    $names = array_column($fleetList, 'name');
    sort($names);
    assertEquals(['fleet', 'global'], $names, 'fleet sees global + own');
});

// ---------------------------------------------------------------------------
// evaluateReport — severity matching + generation
// ---------------------------------------------------------------------------

runCase('evaluateReport fires policy + generates estimate for 2 failed items', function () {
    $f = makeAutoPolicyFixture();
    seedFailedReport($f);
    $policy = $f['service']->createPolicy($f['actor'], [
        'name' => 'Any high severity', 'trigger_severity' => 'high', 'target_kind' => 'estimate',
    ]);
    $runs = $f['service']->evaluateReport($f['actor'], 100);
    assertTrue(count($runs) === 1, 'one policy ran');
    assertEquals('triggered', $runs[0]['status'], 'triggered');
    assertTrue($runs[0]['estimate_id'] > 0, 'estimate created');
    assertTrue($runs[0]['items_generated'] === 2, 'two items generated');

    // Verify estimate + jobs persisted
    $count = (int) $f['pdo']->query('SELECT COUNT(*) FROM estimates')->fetchColumn();
    assertTrue($count === 1, 'one estimate row');
    $jobCount = (int) $f['pdo']->query('SELECT COUNT(*) FROM estimate_jobs')->fetchColumn();
    assertTrue($jobCount === 2, 'two jobs');
});

runCase('evaluateReport is idempotent — second call generates nothing new', function () {
    $f = makeAutoPolicyFixture();
    seedFailedReport($f);
    $f['service']->createPolicy($f['actor'], [
        'name' => 'x', 'trigger_severity' => 'high',
    ]);
    $first = $f['service']->evaluateReport($f['actor'], 100);
    $second = $f['service']->evaluateReport($f['actor'], 100);
    assertTrue(count($first) === 1, 'first run fires');
    assertTrue(count($second) === 0, 'second call no-op due to hasRun marker');
    $runCount = (int) $f['pdo']->query('SELECT COUNT(*) FROM inspection_auto_generation_runs')->fetchColumn();
    assertTrue($runCount === 1, 'one run marker');
});

runCase('evaluateReport skips policy when below min_failed_items', function () {
    $f = makeAutoPolicyFixture();
    seedFailedReport($f);
    // Two failed items; require 5 → policy shouldn't fire.
    $f['service']->createPolicy($f['actor'], [
        'name' => 'bulk', 'trigger_severity' => 'high', 'min_failed_items' => 5,
    ]);
    $runs = $f['service']->evaluateReport($f['actor'], 100);
    assertTrue(count($runs) === 0, 'policy skipped');
});

runCase('evaluateReport respects trigger_severity threshold', function () {
    $f = makeAutoPolicyFixture();
    seedFailedReport($f);
    // Items have severity high (item 10 boolean-fail) and critical (item 11 scored 1).
    // Policy set to critical — only the number_scale item matches.
    $f['service']->createPolicy($f['actor'], [
        'name' => 'critical-only', 'trigger_severity' => 'critical', 'target_kind' => 'estimate',
    ]);
    $runs = $f['service']->evaluateReport($f['actor'], 100);
    assertTrue(count($runs) === 1, 'fires');
    assertTrue($runs[0]['items_generated'] === 1, 'only the critical item included');
});

runCase('evaluateReport does not fire inactive policy', function () {
    $f = makeAutoPolicyFixture();
    seedFailedReport($f);
    $p = $f['service']->createPolicy($f['actor'], ['name' => 'x', 'trigger_severity' => 'high']);
    $f['service']->updatePolicy($f['actor'], $p['id'], ['name' => 'x', 'is_active' => false]);
    $runs = $f['service']->evaluateReport($f['actor'], 100);
    assertTrue(count($runs) === 0, 'inactive policy skipped');
});

runCase('evaluateReport filters by compliance_tag_id', function () {
    $f = makeAutoPolicyFixture();
    seedFailedReport($f);
    // Tag item 11 (the critical rotor one) with DOT tag
    $f['pdo']->exec("INSERT INTO inspection_compliance_tags (id, code, label, regulatory_body) VALUES (50, 'dot_brake', 'DOT Brake', 'dot')");
    $f['pdo']->exec("UPDATE inspection_items SET compliance_tag_id = 50 WHERE id = 11");

    // Policy bound to tag 50 — should only pick up item 11 (critical rotor)
    $f['service']->createPolicy($f['actor'], [
        'name' => 'dot-only', 'trigger_severity' => 'medium', 'compliance_tag_id' => 50,
        'target_kind' => 'estimate',
    ]);
    $runs = $f['service']->evaluateReport($f['actor'], 100);
    assertTrue(count($runs) === 1, 'policy fires');
    assertTrue($runs[0]['items_generated'] === 1, 'only DOT-tagged item');
});

runCase('evaluateReport target=auto becomes subestimate when workorder present', function () {
    $f = makeAutoPolicyFixture();
    seedFailedReport($f, workorderId: 800);
    $f['service']->createPolicy($f['actor'], [
        'name' => 'auto-target', 'trigger_severity' => 'high', 'target_kind' => 'auto',
    ]);
    $runs = $f['service']->evaluateReport($f['actor'], 100);
    assertTrue(count($runs) === 1, 'fires');
    assertTrue($runs[0]['sub_estimate_id'] > 0, 'sub_estimate produced');
    assertTrue(empty($runs[0]['estimate_id']), 'no standalone estimate_id');
    assertEquals(800, $runs[0]['workorder_id'], 'workorder linked');
});

runCase('evaluateReport subestimate_on_workorder skips when no workorder', function () {
    $f = makeAutoPolicyFixture();
    seedFailedReport($f); // no workorder
    $f['service']->createPolicy($f['actor'], [
        'name' => 'wo-only', 'trigger_severity' => 'high', 'target_kind' => 'subestimate_on_workorder',
    ]);
    $runs = $f['service']->evaluateReport($f['actor'], 100);
    assertTrue(count($runs) === 1, 'run recorded');
    assertEquals('skipped', $runs[0]['status'], 'marked skipped');
    assertTrue($runs[0]['items_generated'] === 0, 'nothing generated');
});

runCase('evaluateReport unknown report throws', function () {
    $f = makeAutoPolicyFixture();
    try {
        $f['service']->evaluateReport($f['actor'], 9999);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), '9999 not found'), 'right message');
    }
});

runCase('evaluateReport with no failed items returns empty', function () {
    $f = makeAutoPolicyFixture();
    // Report with only passing items
    $f['pdo']->exec("INSERT INTO inspection_reports (id, template_id, customer_id, status) VALUES (200, 1, 42, 'completed')");
    $f['pdo']->exec("INSERT INTO inspection_report_items (id, report_id, template_item_id, label, response)
                     VALUES (2000, 200, 12, 'Parking brake OK?', 'yes')");
    $f['service']->createPolicy($f['actor'], ['name' => 'x', 'trigger_severity' => 'low']);
    $runs = $f['service']->evaluateReport($f['actor'], 200);
    assertTrue(count($runs) === 0, 'nothing to fire');
});

runCase('evaluateReportOnCompletion swallows exceptions', function () {
    $f = makeAutoPolicyFixture();
    // Intentionally don't seed inspection report — runEvaluation will
    // throw InvalidArgumentException("not found"), and the hook
    // variant should swallow it so completion is never blocked.
    $result = $f['service']->evaluateReportOnCompletion(9999, $f['actor']->id);
    assertEquals([], $result, 'swallowed into empty result');
    $hookFailures = array_filter($f['audit']->entries,
        fn(AuditEntry $e) => $e->action === 'inspection.auto_policy.hook_failure');
    assertTrue(count($hookFailures) === 1, 'recorded hook failure audit');
});

// ---------------------------------------------------------------------------
// Division scoping
// ---------------------------------------------------------------------------

runCase('evaluateReport only fires policies in matching or global division', function () {
    $f = makeAutoPolicyFixture();
    seedFailedReport($f);
    // Report's template is in division 2 (fleet). Policies:
    // - global (division null) — should fire
    // - division 2 (fleet) — should fire
    // - division 1 (auto) — should NOT fire
    $f['service']->createPolicy($f['actor'], [
        'name' => 'global_p', 'division_id' => null, 'trigger_severity' => 'high', 'target_kind' => 'estimate',
    ]);
    $f['service']->createPolicy($f['actor'], [
        'name' => 'fleet_p', 'division_id' => 2, 'trigger_severity' => 'high', 'target_kind' => 'estimate',
    ]);
    $f['service']->createPolicy($f['actor'], [
        'name' => 'auto_p', 'division_id' => 1, 'trigger_severity' => 'high', 'target_kind' => 'estimate',
    ]);
    $runs = $f['service']->evaluateReport($f['actor'], 100);
    assertTrue(count($runs) === 2, 'global + fleet fire, auto skipped');
});

// ---------------------------------------------------------------------------
// Gate denials
// ---------------------------------------------------------------------------

runCase('inspections.manage denial blocks policy writes', function () {
    $f = makeAutoPolicyFixture();
    $f['gate']->denials['inspections.manage'] = true;
    try {
        $f['service']->createPolicy($f['actor'], ['name' => 'x']);
        throw new RuntimeException('expected denial');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'inspections.manage'), 'right permission');
    }
});

runCase('inspections.view denial blocks list', function () {
    $f = makeAutoPolicyFixture();
    $f['gate']->denials['inspections.view'] = true;
    try {
        $f['service']->listPolicies($f['actor']);
        throw new RuntimeException('expected denial');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'inspections.view'), 'right permission');
    }
});

runCase('inspections.manage denial blocks manual evaluateReport', function () {
    $f = makeAutoPolicyFixture();
    seedFailedReport($f);
    $f['gate']->denials['inspections.manage'] = true;
    try {
        $f['service']->evaluateReport($f['actor'], 100);
        throw new RuntimeException('expected denial');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'inspections.manage'), 'right permission');
    }
});

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

echo "\n{$cases} cases, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
