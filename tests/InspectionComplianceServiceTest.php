<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "InspectionComplianceServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\InspectionComplianceTag;
use App\Models\User;
use App\Services\Inspection\InspectionComplianceService;
use App\Services\Inspection\InspectionComplianceTagRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 8.1 of docs/expansion-plan.md — compliance-tagged inspection
 * templates per-division. SQLite-in-memory exercises: tag CRUD +
 * UNIQUE(code, division_id) collision + code normalization +
 * regulatory_body enum + division scoping, template<->tag replace-all
 * binding + idempotent clear, listTemplatesByCompliance joining
 * across division and tag codes, gate denials.
 */

// ---------------------------------------------------------------------------
// SQLite connection
// ---------------------------------------------------------------------------

class ComplianceInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function complianceSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec(
        'CREATE TABLE inspection_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            division_id INTEGER NULL,
            name TEXT NOT NULL,
            description TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1
        )'
    );
    $pdo->exec(
        'CREATE TABLE inspection_compliance_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL,
            label TEXT NOT NULL,
            description TEXT NULL,
            regulatory_body TEXT NOT NULL DEFAULT "other",
            division_id INTEGER NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_by_user_id INTEGER NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )'
    );
    // UNIQUE (code, division_id) — SQLite treats two NULLs as distinct
    // by default, which matches MySQL's InnoDB behavior on NULL in
    // UNIQUE keys. Add an explicit unique index to enforce the constraint.
    $pdo->exec(
        'CREATE UNIQUE INDEX uk_ctag_code_division
         ON inspection_compliance_tags (code, COALESCE(division_id, 0))'
    );

    $pdo->exec(
        'CREATE TABLE inspection_template_compliance_tags (
            template_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            created_at TEXT NULL,
            PRIMARY KEY (template_id, tag_id)
        )'
    );

    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class ComplianceFakeAudit extends AuditLogger
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

class CompliancePermissiveGate extends AccessGate
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
 *   service: InspectionComplianceService,
 *   tags: InspectionComplianceTagRepository,
 *   pdo: PDO,
 *   gate: CompliancePermissiveGate,
 *   audit: ComplianceFakeAudit,
 *   actor: User,
 *   autoTemplateId: int,
 *   fleetTemplateId: int,
 *   autoDivisionId: int,
 *   fleetDivisionId: int
 * }
 */
function makeComplianceFixture(): array
{
    $pdo = complianceSetUpDatabase();
    $conn = new ComplianceInMemoryConnection($pdo);
    $audit = new ComplianceFakeAudit();
    $gate = new CompliancePermissiveGate();
    $tags = new InspectionComplianceTagRepository($conn);
    $service = new InspectionComplianceService($conn, $tags, $gate, $audit);

    // Seed two templates in different divisions.
    $pdo->exec("INSERT INTO inspection_templates (division_id, name, description, active)
                VALUES (1, 'Auto Annual Safety', 'state annual safety', 1),
                       (2, 'Fleet DOT Preventive', 'DOT PM inspection', 1),
                       (NULL, 'Shop All-Around', 'cross-division catch-all', 1)");
    $autoId = (int) $pdo->query("SELECT id FROM inspection_templates WHERE name='Auto Annual Safety'")->fetchColumn();
    $fleetId = (int) $pdo->query("SELECT id FROM inspection_templates WHERE name='Fleet DOT Preventive'")->fetchColumn();

    $actor = new User();
    $actor->id = 7;

    return [
        'service' => $service,
        'tags' => $tags,
        'pdo' => $pdo,
        'gate' => $gate,
        'audit' => $audit,
        'actor' => $actor,
        'autoTemplateId' => $autoId,
        'fleetTemplateId' => $fleetId,
        'autoDivisionId' => 1,
        'fleetDivisionId' => 2,
    ];
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

echo "InspectionComplianceServiceTest\n";

// ---------------------------------------------------------------------------
// Tag CRUD
// ---------------------------------------------------------------------------

runCase('createTag happy path + audit + default is_active/sort_order', function () {
    $f = makeComplianceFixture();
    $tag = $f['service']->createTag($f['actor'], [
        'code' => 'dot_annual',
        'label' => 'DOT Annual Inspection',
        'regulatory_body' => 'dot',
        'division_id' => $f['fleetDivisionId'],
    ]);
    assertTrue($tag['id'] > 0, 'returns id');
    assertEquals('dot_annual', $tag['code'], 'code stored');
    assertEquals('dot', $tag['regulatory_body'], 'reg body');
    assertEquals(true, $tag['is_active'], 'active default');
    assertEquals(0, $tag['sort_order'], 'sort default');
    assertTrue(count($f['audit']->entries) === 1, 'one audit entry');
    assertEquals('inspection.compliance_tag.created', $f['audit']->entries[0]->action, 'audit action');
});

runCase('createTag normalizes uppercase code to lowercase', function () {
    $f = makeComplianceFixture();
    $tag = $f['service']->createTag($f['actor'], [
        'code' => 'OSHA_LOTO',
        'label' => 'OSHA Lockout/Tagout',
        'regulatory_body' => 'OSHA',
    ]);
    assertEquals('osha_loto', $tag['code'], 'code lowercased');
    assertEquals('osha', $tag['regulatory_body'], 'reg body lowercased');
});

runCase('createTag rejects invalid code chars', function () {
    $f = makeComplianceFixture();
    try {
        $f['service']->createTag($f['actor'], [
            'code' => 'invalid code!',
            'label' => 'x',
            'regulatory_body' => 'other',
        ]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'lowercase alphanumeric'), 'right message');
    }
});

runCase('createTag rejects unknown regulatory_body', function () {
    $f = makeComplianceFixture();
    try {
        $f['service']->createTag($f['actor'], [
            'code' => 'custom',
            'label' => 'x',
            'regulatory_body' => 'nasa',
        ]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'regulatory_body must be one of'), 'right message');
    }
});

runCase('createTag rejects duplicate (code, division_id)', function () {
    $f = makeComplianceFixture();
    $f['service']->createTag($f['actor'], [
        'code' => 'state_safety',
        'label' => 'State Safety',
        'regulatory_body' => 'state',
        'division_id' => 1,
    ]);
    try {
        $f['service']->createTag($f['actor'], [
            'code' => 'state_safety',
            'label' => 'State Safety Dup',
            'regulatory_body' => 'state',
            'division_id' => 1,
        ]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'already in use'), 'right message');
    }
});

runCase('same code allowed across different divisions', function () {
    $f = makeComplianceFixture();
    $f['service']->createTag($f['actor'], [
        'code' => 'state_safety',
        'label' => 'Auto state safety',
        'regulatory_body' => 'state',
        'division_id' => 1,
    ]);
    $other = $f['service']->createTag($f['actor'], [
        'code' => 'state_safety',
        'label' => 'Fleet state safety',
        'regulatory_body' => 'state',
        'division_id' => 2,
    ]);
    assertTrue($other['id'] > 0, 'second division accepts same code');
});

runCase('global tag (division_id null) allowed once', function () {
    $f = makeComplianceFixture();
    $g1 = $f['service']->createTag($f['actor'], [
        'code' => 'general_safety',
        'label' => 'General Safety',
        'regulatory_body' => 'internal',
    ]);
    assertTrue($g1['division_id'] === null, 'stored as global');
    try {
        $f['service']->createTag($f['actor'], [
            'code' => 'general_safety',
            'label' => 'Dup',
            'regulatory_body' => 'internal',
        ]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'already in use'), 'right message');
    }
});

runCase('createTag rejects empty code/label', function () {
    $f = makeComplianceFixture();
    foreach (['code', 'label'] as $field) {
        try {
            $payload = ['code' => 'ok', 'label' => 'ok', 'regulatory_body' => 'other'];
            $payload[$field] = '  ';
            $f['service']->createTag($f['actor'], $payload);
            throw new RuntimeException("expected rejection for {$field}");
        } catch (InvalidArgumentException $e) {
            assertTrue(str_contains($e->getMessage(), "{$field} is required"), "mentions {$field}");
        }
    }
});

runCase('updateTag partial patch preserves code+division_id', function () {
    $f = makeComplianceFixture();
    $orig = $f['service']->createTag($f['actor'], [
        'code' => 'epa_haz',
        'label' => 'EPA Hazmat',
        'regulatory_body' => 'epa',
        'division_id' => 2,
    ]);
    $updated = $f['service']->updateTag($f['actor'], $orig['id'], [
        'label' => 'EPA Hazmat v2',
        'sort_order' => 5,
    ]);
    assertEquals('epa_haz', $updated['code'], 'code unchanged');
    assertEquals(2, $updated['division_id'], 'division unchanged');
    assertEquals('EPA Hazmat v2', $updated['label'], 'label updated');
    assertEquals(5, $updated['sort_order'], 'sort updated');
    assertEquals('epa', $updated['regulatory_body'], 'reg body preserved');
});

runCase('updateTag unknown id throws', function () {
    $f = makeComplianceFixture();
    try {
        $f['service']->updateTag($f['actor'], 9999, ['label' => 'x']);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), '9999 not found'), 'right message');
    }
});

runCase('deleteTag removes row + emits audit', function () {
    $f = makeComplianceFixture();
    $tag = $f['service']->createTag($f['actor'], [
        'code' => 'to_delete',
        'label' => 'x',
        'regulatory_body' => 'other',
    ]);
    $f['service']->deleteTag($f['actor'], $tag['id']);
    assertTrue($f['tags']->findById($tag['id']) === null, 'row gone');
    $deleteEntries = array_filter($f['audit']->entries,
        fn(AuditEntry $e) => $e->action === 'inspection.compliance_tag.deleted');
    assertTrue(count($deleteEntries) === 1, 'one delete audit');
});

runCase('deleteTag is idempotent (already-absent)', function () {
    $f = makeComplianceFixture();
    $f['service']->deleteTag($f['actor'], 4242);
    $entries = array_filter($f['audit']->entries,
        fn(AuditEntry $e) => $e->action === 'inspection.compliance_tag.deleted');
    assertTrue(count($entries) === 0, 'no audit for absent delete');
});

// ---------------------------------------------------------------------------
// Listing tags per-division
// ---------------------------------------------------------------------------

runCase('listTags for division includes global + own', function () {
    $f = makeComplianceFixture();
    $f['service']->createTag($f['actor'], [
        'code' => 'global_one', 'label' => 'G1', 'regulatory_body' => 'internal',
    ]);
    $f['service']->createTag($f['actor'], [
        'code' => 'auto_only', 'label' => 'A1', 'regulatory_body' => 'state', 'division_id' => 1,
    ]);
    $f['service']->createTag($f['actor'], [
        'code' => 'fleet_only', 'label' => 'F1', 'regulatory_body' => 'dot', 'division_id' => 2,
    ]);

    $autoList = $f['service']->listTags($f['actor'], 1);
    $codes = array_column($autoList, 'code');
    sort($codes);
    assertEquals(['auto_only', 'global_one'], $codes, 'division 1 sees global + auto');

    $fleetList = $f['service']->listTags($f['actor'], 2);
    $fleetCodes = array_column($fleetList, 'code');
    sort($fleetCodes);
    assertEquals(['fleet_only', 'global_one'], $fleetCodes, 'division 2 sees global + fleet');
});

runCase('listTags activeOnly filters inactive', function () {
    $f = makeComplianceFixture();
    $a = $f['service']->createTag($f['actor'], [
        'code' => 'active_one', 'label' => 'A', 'regulatory_body' => 'other',
    ]);
    $b = $f['service']->createTag($f['actor'], [
        'code' => 'inactive_one', 'label' => 'I', 'regulatory_body' => 'other',
    ]);
    $f['service']->updateTag($f['actor'], $b['id'], ['label' => 'I', 'is_active' => false]);
    $active = $f['service']->listTags($f['actor'], null, true);
    $codes = array_column($active, 'code');
    assertTrue(in_array('active_one', $codes, true), 'active present');
    assertTrue(!in_array('inactive_one', $codes, true), 'inactive filtered');
});

// ---------------------------------------------------------------------------
// Template <-> tag bindings
// ---------------------------------------------------------------------------

runCase('setTagsForTemplate binds + listTagsForTemplate returns them', function () {
    $f = makeComplianceFixture();
    $t1 = $f['service']->createTag($f['actor'], [
        'code' => 'dot_annual', 'label' => 'DOT Annual', 'regulatory_body' => 'dot', 'division_id' => 2,
    ]);
    $t2 = $f['service']->createTag($f['actor'], [
        'code' => 'safety', 'label' => 'Safety', 'regulatory_body' => 'internal',
    ]);
    $bound = $f['service']->setTagsForTemplate(
        $f['actor'],
        $f['fleetTemplateId'],
        [$t1['id'], $t2['id']]
    );
    assertTrue(count($bound) === 2, 'two bound');
    $codes = array_column($bound, 'code');
    sort($codes);
    assertEquals(['dot_annual', 'safety'], $codes, 'both returned');

    $reread = $f['service']->listTagsForTemplate($f['actor'], $f['fleetTemplateId']);
    assertTrue(count($reread) === 2, 're-read returns both');
});

runCase('setTagsForTemplate is replace-all (empty clears)', function () {
    $f = makeComplianceFixture();
    $t1 = $f['service']->createTag($f['actor'], [
        'code' => 'a', 'label' => 'A', 'regulatory_body' => 'other',
    ]);
    $f['service']->setTagsForTemplate($f['actor'], $f['autoTemplateId'], [$t1['id']]);
    assertTrue(count($f['service']->listTagsForTemplate($f['actor'], $f['autoTemplateId'])) === 1, 'bound');
    $cleared = $f['service']->setTagsForTemplate($f['actor'], $f['autoTemplateId'], []);
    assertTrue(count($cleared) === 0, 'clears to empty');
    assertTrue(count($f['service']->listTagsForTemplate($f['actor'], $f['autoTemplateId'])) === 0, 're-read empty');
});

runCase('setTagsForTemplate rejects unknown tag id', function () {
    $f = makeComplianceFixture();
    try {
        $f['service']->setTagsForTemplate($f['actor'], $f['autoTemplateId'], [9999]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), '9999 not found'), 'right message');
    }
});

runCase('setTagsForTemplate rejects unknown template id', function () {
    $f = makeComplianceFixture();
    $t = $f['service']->createTag($f['actor'], [
        'code' => 'x', 'label' => 'X', 'regulatory_body' => 'other',
    ]);
    try {
        $f['service']->setTagsForTemplate($f['actor'], 9999, [$t['id']]);
        throw new RuntimeException('expected rejection');
    } catch (InvalidArgumentException $e) {
        assertTrue(str_contains($e->getMessage(), 'template 9999 not found'), 'right message');
    }
});

runCase('setTagsForTemplate dedups repeat ids', function () {
    $f = makeComplianceFixture();
    $t1 = $f['service']->createTag($f['actor'], [
        'code' => 'a', 'label' => 'A', 'regulatory_body' => 'other',
    ]);
    $bound = $f['service']->setTagsForTemplate(
        $f['actor'],
        $f['autoTemplateId'],
        [$t1['id'], $t1['id'], $t1['id']]
    );
    assertTrue(count($bound) === 1, 'deduped');
});

// ---------------------------------------------------------------------------
// listTemplatesByCompliance
// ---------------------------------------------------------------------------

runCase('listTemplatesByCompliance filters by division + tag codes', function () {
    $f = makeComplianceFixture();
    $dot = $f['service']->createTag($f['actor'], [
        'code' => 'dot_annual', 'label' => 'DOT', 'regulatory_body' => 'dot', 'division_id' => 2,
    ]);
    $osha = $f['service']->createTag($f['actor'], [
        'code' => 'osha_loto', 'label' => 'OSHA', 'regulatory_body' => 'osha',
    ]);
    $f['service']->setTagsForTemplate($f['actor'], $f['fleetTemplateId'], [$dot['id'], $osha['id']]);
    $f['service']->setTagsForTemplate($f['actor'], $f['autoTemplateId'], [$osha['id']]);

    // Scope to fleet division + DOT — only the fleet template matches.
    $results = $f['service']->listTemplatesByCompliance(
        $f['actor'],
        $f['fleetDivisionId'],
        ['dot_annual'],
        false
    );
    assertTrue(count($results) === 1, 'one match');
    assertEquals($f['fleetTemplateId'], $results[0]['id'], 'fleet template');
    assertTrue(in_array('dot_annual', $results[0]['tag_codes'], true), 'tag code surfaced');

    // Filter on OSHA across divisions — both templates match.
    $oshaResults = $f['service']->listTemplatesByCompliance(
        $f['actor'],
        null,
        ['osha_loto'],
        false
    );
    $ids = array_column($oshaResults, 'id');
    sort($ids);
    $expected = [$f['autoTemplateId'], $f['fleetTemplateId']];
    sort($expected);
    assertEquals($expected, $ids, 'both templates match on OSHA');
});

runCase('listTemplatesByCompliance activeOnly respects inactive templates', function () {
    $f = makeComplianceFixture();
    $f['pdo']->exec('UPDATE inspection_templates SET active = 0 WHERE id = ' . $f['fleetTemplateId']);
    $dot = $f['service']->createTag($f['actor'], [
        'code' => 'dot_annual', 'label' => 'DOT', 'regulatory_body' => 'dot', 'division_id' => 2,
    ]);
    $f['service']->setTagsForTemplate($f['actor'], $f['fleetTemplateId'], [$dot['id']]);
    $results = $f['service']->listTemplatesByCompliance(
        $f['actor'],
        null,
        ['dot_annual'],
        true
    );
    assertTrue(count($results) === 0, 'inactive template excluded');
});

runCase('listTemplatesByCompliance surfaces all tags per template', function () {
    $f = makeComplianceFixture();
    $a = $f['service']->createTag($f['actor'], [
        'code' => 'alpha', 'label' => 'Alpha', 'regulatory_body' => 'other',
    ]);
    $b = $f['service']->createTag($f['actor'], [
        'code' => 'beta', 'label' => 'Beta', 'regulatory_body' => 'other',
    ]);
    $f['service']->setTagsForTemplate($f['actor'], $f['autoTemplateId'], [$a['id'], $b['id']]);
    $results = $f['service']->listTemplatesByCompliance(
        $f['actor'],
        null,
        ['alpha'],
        false
    );
    assertTrue(count($results) === 1, 'one row');
    $codes = $results[0]['tag_codes'];
    sort($codes);
    assertEquals(['alpha', 'beta'], $codes, 'all bound tags listed, not just matched filter');
});

// ---------------------------------------------------------------------------
// Gate denials
// ---------------------------------------------------------------------------

runCase('inspections.manage denial blocks writes', function () {
    $f = makeComplianceFixture();
    $f['gate']->denials['inspections.manage'] = true;
    try {
        $f['service']->createTag($f['actor'], [
            'code' => 'x', 'label' => 'X', 'regulatory_body' => 'other',
        ]);
        throw new RuntimeException('expected denial');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'inspections.manage'), 'right permission');
    }
});

runCase('inspections.view denial blocks reads', function () {
    $f = makeComplianceFixture();
    $f['gate']->denials['inspections.view'] = true;
    try {
        $f['service']->listTags($f['actor']);
        throw new RuntimeException('expected denial');
    } catch (UnauthorizedException $e) {
        assertTrue(str_contains($e->getMessage(), 'inspections.view'), 'right permission');
    }
});

runCase('setTagsForTemplate gated on inspections.manage', function () {
    $f = makeComplianceFixture();
    $f['gate']->denials['inspections.manage'] = true;
    try {
        $f['service']->setTagsForTemplate($f['actor'], $f['autoTemplateId'], []);
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
