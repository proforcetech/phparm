<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "InspectionQrLaunchServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\InspectionQrLaunch;
use App\Models\User;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Inspection\InspectionCompletionService;
use App\Services\Inspection\InspectionQrLaunchRepository;
use App\Services\Inspection\InspectionQrLaunchService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 8.5 of docs/expansion-plan.md — QR launch at asset level.
 * Token resolution, division-scoped template filtering, two-phase
 * launch persistence (preview row -> attached report), failure-mode
 * audit trail (unresolved/inactive/template-mismatch), gate denials.
 */

class QrLaunchInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function qrLaunchSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));

    $pdo->exec("CREATE TABLE divisions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NULL)");
    $pdo->exec("CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NULL,
        name TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE site_assets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        division_id INTEGER NULL,
        asset_type_id INTEGER NULL,
        parent_asset_id INTEGER NULL,
        name TEXT NOT NULL,
        code TEXT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        install_date TEXT NULL,
        decommissioned_at TEXT NULL,
        notes TEXT NULL,
        manufacturer TEXT NULL,
        model_number TEXT NULL,
        serial_number TEXT NULL,
        vendor TEXT NULL,
        warranty_start TEXT NULL,
        warranty_end TEXT NULL,
        purchase_cents INTEGER NULL,
        custom_fields TEXT NULL,
        qr_token TEXT NULL,
        building TEXT NULL,
        floor TEXT NULL,
        room TEXT NULL,
        rack TEXT NULL,
        rack_position TEXT NULL,
        ip_address TEXT NULL,
        mac_address TEXT NULL,
        subnet TEXT NULL,
        vlan TEXT NULL,
        condition_score INTEGER NULL,
        expected_life_years REAL NULL,
        replacement_estimate_cents INTEGER NULL,
        last_inspected_at TEXT NULL,
        replace_by_date TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE inspection_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        division_id INTEGER NULL,
        name TEXT NOT NULL,
        description TEXT NULL,
        active INTEGER NOT NULL DEFAULT 1
    )");

    $pdo->exec("CREATE TABLE inspection_reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        template_id INTEGER NOT NULL,
        customer_id INTEGER NOT NULL,
        vehicle_id INTEGER NULL,
        estimate_id INTEGER NULL,
        appointment_id INTEGER NULL,
        site_asset_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        summary TEXT NULL,
        pdf_path TEXT NULL,
        completed_by INTEGER NULL,
        completed_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE inspection_qr_launches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        qr_token TEXT NOT NULL,
        site_asset_id INTEGER NULL,
        inspection_report_id INTEGER NULL,
        inspection_template_id INTEGER NULL,
        launched_by_user_id INTEGER NULL,
        source TEXT NOT NULL DEFAULT 'qr',
        status TEXT NOT NULL DEFAULT 'preview',
        client_meta TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL
    )");

    return $pdo;
}

class QrLaunchFakeAudit extends AuditLogger
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

class QrLaunchPermissiveGate extends AccessGate
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
 * @return array<string, mixed>
 */
function makeQrLaunchFixture(): array
{
    $pdo = qrLaunchSetUpDatabase();
    $conn = new QrLaunchInMemoryConnection($pdo);
    $audit = new QrLaunchFakeAudit();
    $gate = new QrLaunchPermissiveGate();

    $launchRepo = new InspectionQrLaunchRepository($conn);
    $assetRepo = new SiteAssetRepository($conn);
    $completion = new InspectionCompletionService($conn, $audit);
    $service = new InspectionQrLaunchService(
        $conn,
        $launchRepo,
        $assetRepo,
        $completion,
        $gate,
        $audit
    );

    $pdo->exec("INSERT INTO divisions (id, name) VALUES (1, 'Auto'), (2, 'HVAC')");
    $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Tech 1')");
    $pdo->exec("INSERT INTO customers (id, name) VALUES (10, 'Acme')");
    $pdo->exec("INSERT INTO sites (id, customer_id, name) VALUES (100, 10, 'Acme HQ')");

    return [
        'pdo' => $pdo,
        'conn' => $conn,
        'audit' => $audit,
        'gate' => $gate,
        'service' => $service,
        'launchRepo' => $launchRepo,
        'completion' => $completion,
    ];
}

function qrSeedAsset(PDO $pdo, int $id, ?int $divisionId, string $token, string $status = 'active'): void
{
    $stmt = $pdo->prepare("INSERT INTO site_assets (id, site_id, division_id, name, status, qr_token)
        VALUES (:id, 100, :div, :name, :status, :tok)");
    $stmt->execute([
        'id' => $id,
        'div' => $divisionId,
        'name' => "Asset {$id}",
        'status' => $status,
        'tok' => $token,
    ]);
}

function qrSeedTemplate(PDO $pdo, int $id, ?int $divisionId, string $name, int $active = 1): void
{
    $stmt = $pdo->prepare("INSERT INTO inspection_templates (id, division_id, name, active)
        VALUES (:id, :div, :name, :a)");
    $stmt->execute(['id' => $id, 'div' => $divisionId, 'name' => $name, 'a' => $active]);
}

function qrAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function qrAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function qrAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
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

function makeQrLaunchUser(int $id = 1): User
{
    $u = new User();
    $u->id = $id;
    $u->role = 'technician';
    return $u;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

$tests['preview_resolves_token_and_returns_asset_with_division_filtered_templates'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto Brake Inspection');
    qrSeedTemplate($f['pdo'], 11, null, 'Global Safety Walk');
    qrSeedTemplate($f['pdo'], 12, 2, 'HVAC Filter Check'); // wrong division

    $result = $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    qrAssertSame(1, $result['asset']['id']);
    qrAssertSame(1, $result['asset']['division_id']);
    qrAssertSame(2, count($result['available_templates']), 'should include matching division + global only');
    $names = array_map(fn($t) => $t['name'], $result['available_templates']);
    sort($names);
    qrAssertSame(['Auto Brake Inspection', 'Global Safety Walk'], $names);
    qrAssertTrue(is_int($result['launch_id']) && $result['launch_id'] > 0);
};

$tests['preview_records_preview_status_launch_row'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    $rows = $f['launchRepo']->listForAsset(1);
    qrAssertSame(1, count($rows));
    qrAssertSame(InspectionQrLaunch::STATUS_PREVIEW, $rows[0]->status);
    qrAssertSame(1, $rows[0]->launched_by_user_id);
};

$tests['preview_records_meta_blob_when_provided'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', [
        'lat' => 47.6, 'lng' => -122.3, 'ua' => 'Mozilla/5.0 ...',
    ]);
    $rows = $f['launchRepo']->listForAsset(1);
    qrAssertTrue($rows[0]->client_meta !== null);
    $decoded = json_decode($rows[0]->client_meta, true);
    qrAssertSame(47.6, $decoded['lat']);
};

$tests['preview_unresolved_token_records_failed_row_and_throws'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrAssertThrows(
        fn() => $f['service']->previewByToken(makeQrLaunchUser(), 'ffffffffffffffffffffffffffffffff'),
        InvalidArgumentException::class
    );
    $rows = $f['launchRepo']->listForToken('ffffffffffffffffffffffffffffffff');
    qrAssertSame(1, count($rows));
    qrAssertSame(InspectionQrLaunch::STATUS_FAILED, $rows[0]->status);
    qrAssertTrue($rows[0]->site_asset_id === null);
};

$tests['preview_invalid_token_format_rejected'] = function () {
    $f = makeQrLaunchFixture();
    qrAssertThrows(
        fn() => $f['service']->previewByToken(makeQrLaunchUser(), 'not-hex-token!'),
        InvalidArgumentException::class
    );
};

$tests['preview_returns_recent_inspections_for_asset'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Tpl');
    $f['pdo']->exec("INSERT INTO inspection_reports (id, template_id, customer_id, site_asset_id, status, created_at)
        VALUES (500, 10, 10, 1, 'completed', '2026-04-01 09:00:00')");
    $r = $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    qrAssertSame(1, count($r['recent_inspections']));
    qrAssertSame(500, $r['recent_inspections'][0]['id']);
};

$tests['preview_no_division_asset_shows_all_active_templates'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, null, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto');
    qrSeedTemplate($f['pdo'], 11, null, 'Global');
    qrSeedTemplate($f['pdo'], 12, 2, 'HVAC');
    qrSeedTemplate($f['pdo'], 13, null, 'Inactive', 0);
    $r = $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    qrAssertSame(3, count($r['available_templates']), 'no division → all active templates');
};

$tests['launch_creates_draft_report_with_site_asset_id'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto Brake');
    $r = $f['service']->launchFromToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', 10);
    qrAssertSame(1, $r['asset']['id']);
    qrAssertSame(1, $r['report']['site_asset_id']);
    qrAssertSame(10, $r['report']['template_id']);
    qrAssertSame(10, $r['report']['customer_id'], 'customer auto-resolved from site');
    qrAssertSame('draft', $r['report']['status']);
    qrAssertSame(InspectionQrLaunch::STATUS_STARTED, $r['launch']['status']);
    qrAssertSame((int) $r['report']['id'], $r['launch']['inspection_report_id']);
};

$tests['launch_attaches_report_to_existing_launch_row'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto Brake');
    $f['service']->launchFromToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', 10);
    $rows = $f['launchRepo']->listForAsset(1);
    $started = array_values(array_filter($rows, fn($r) => $r->status === InspectionQrLaunch::STATUS_STARTED));
    qrAssertSame(1, count($started));
    qrAssertTrue($started[0]->inspection_report_id !== null);
    qrAssertSame(10, $started[0]->inspection_template_id);
};

$tests['launch_explicit_customer_id_overrides_site_default'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto Brake');
    $r = $f['service']->launchFromToken(
        makeQrLaunchUser(),
        'aabbccddeeff00112233445566778899',
        10,
        ['customer_id' => 99],
    );
    qrAssertSame(99, $r['report']['customer_id']);
};

$tests['launch_propagates_optional_payload_fields'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto Brake');
    $r = $f['service']->launchFromToken(
        makeQrLaunchUser(),
        'aabbccddeeff00112233445566778899',
        10,
        ['vehicle_id' => 42, 'summary' => 'roadside QR scan'],
    );
    qrAssertSame(42, $r['report']['vehicle_id']);
    qrAssertSame('roadside QR scan', $r['report']['summary']);
};

$tests['launch_unresolved_token_records_failed_row_and_throws'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto Brake');
    qrAssertThrows(
        fn() => $f['service']->launchFromToken(makeQrLaunchUser(), 'ffffffffffffffffffffffffffffffff', 10),
        InvalidArgumentException::class
    );
    $rows = $f['launchRepo']->listForToken('ffffffffffffffffffffffffffffffff');
    qrAssertSame(1, count($rows));
    qrAssertSame(InspectionQrLaunch::STATUS_FAILED, $rows[0]->status);
};

$tests['launch_inactive_asset_records_aborted_row_and_throws'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899', 'retired');
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto Brake');
    qrAssertThrows(
        fn() => $f['service']->launchFromToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', 10),
        InvalidArgumentException::class
    );
    $rows = $f['launchRepo']->listForAsset(1);
    qrAssertSame(1, count($rows));
    qrAssertSame(InspectionQrLaunch::STATUS_ABORTED, $rows[0]->status);
    qrAssertTrue(str_contains($rows[0]->notes ?? '', 'retired'));
};

$tests['launch_template_not_in_asset_division_records_aborted_row_and_throws'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 12, 2, 'HVAC Tpl'); // wrong division
    qrAssertThrows(
        fn() => $f['service']->launchFromToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', 12),
        InvalidArgumentException::class
    );
    $rows = $f['launchRepo']->listForAsset(1);
    qrAssertSame(InspectionQrLaunch::STATUS_ABORTED, $rows[0]->status);
    qrAssertSame(12, $rows[0]->inspection_template_id);
};

$tests['launch_inactive_template_rejected'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Inactive', 0);
    qrAssertThrows(
        fn() => $f['service']->launchFromToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', 10),
        InvalidArgumentException::class
    );
};

$tests['launch_global_template_works_for_any_division'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 2, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 11, null, 'Global Safety');
    $r = $f['service']->launchFromToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', 11);
    qrAssertSame(11, $r['report']['template_id']);
};

$tests['launch_zero_template_id_rejected'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrAssertThrows(
        fn() => $f['service']->launchFromToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', 0),
        InvalidArgumentException::class
    );
};

$tests['launch_emits_started_audit_with_report_id'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto Brake');
    $r = $f['service']->launchFromToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', 10);
    $startedAudits = array_values(array_filter(
        $f['audit']->entries,
        fn(AuditEntry $e) => $e->event === 'inspection.qr_launch.started'
    ));
    qrAssertSame(1, count($startedAudits));
    qrAssertSame((int) $r['report']['id'], $startedAudits[0]->context['report_id']);
};

$tests['list_for_asset_returns_in_reverse_chronological_order'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    $rows = $f['service']->listForAsset(makeQrLaunchUser(), 1);
    qrAssertSame(3, count($rows));
    qrAssertTrue($rows[0]['id'] > $rows[1]['id']);
    qrAssertTrue($rows[1]['id'] > $rows[2]['id']);
};

$tests['list_for_asset_rejects_non_positive_id'] = function () {
    $f = makeQrLaunchFixture();
    qrAssertThrows(
        fn() => $f['service']->listForAsset(makeQrLaunchUser(), 0),
        InvalidArgumentException::class
    );
};

$tests['find_for_report_returns_launch_or_null'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto Brake');
    $r = $f['service']->launchFromToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', 10);
    $launch = $f['service']->findForReport(makeQrLaunchUser(), (int) $r['report']['id']);
    qrAssertTrue($launch !== null);
    qrAssertSame((int) $r['report']['id'], $launch['inspection_report_id']);

    $missing = $f['service']->findForReport(makeQrLaunchUser(), 99999);
    qrAssertSame(null, $missing);
};

$tests['list_for_token_returns_history_for_token'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    $rows = $f['service']->listForToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    qrAssertSame(2, count($rows));
};

$tests['preview_serializes_launch_with_redacted_token_prefix'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    // Trigger another preview to check that recent_launches doesn't leak full token
    $r = $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    foreach ($r['recent_launches'] as $launch) {
        qrAssertTrue(strlen($launch['qr_token_prefix']) <= 9, 'token should be redacted in serialization');
        qrAssertTrue(str_contains($launch['qr_token_prefix'], 'aabbccdd'));
    }
};

$tests['preview_token_lowercased_before_lookup'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    $r = $f['service']->previewByToken(makeQrLaunchUser(), 'AABBCCDDEEFF00112233445566778899');
    qrAssertSame(1, $r['asset']['id']);
};

$tests['gate_denial_view_blocks_preview'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    $f['gate']->denials['inspections.view'] = true;
    qrAssertThrows(
        fn() => $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899'),
        UnauthorizedException::class
    );
};

$tests['gate_denial_update_blocks_launch'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    qrSeedTemplate($f['pdo'], 10, 1, 'Auto Brake');
    $f['gate']->denials['inspections.update'] = true;
    qrAssertThrows(
        fn() => $f['service']->launchFromToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899', 10),
        UnauthorizedException::class
    );
};

$tests['gate_denial_view_blocks_list_for_asset'] = function () {
    $f = makeQrLaunchFixture();
    $f['gate']->denials['inspections.view'] = true;
    qrAssertThrows(
        fn() => $f['service']->listForAsset(makeQrLaunchUser(), 1),
        UnauthorizedException::class
    );
};

$tests['gate_denial_view_blocks_find_for_report'] = function () {
    $f = makeQrLaunchFixture();
    $f['gate']->denials['inspections.view'] = true;
    qrAssertThrows(
        fn() => $f['service']->findForReport(makeQrLaunchUser(), 1),
        UnauthorizedException::class
    );
};

$tests['preview_emits_audit_log'] = function () {
    $f = makeQrLaunchFixture();
    qrSeedAsset($f['pdo'], 1, 1, 'aabbccddeeff00112233445566778899');
    $f['service']->previewByToken(makeQrLaunchUser(), 'aabbccddeeff00112233445566778899');
    $previewAudits = array_values(array_filter(
        $f['audit']->entries,
        fn(AuditEntry $e) => $e->event === 'inspection.qr_launch.preview'
    ));
    qrAssertSame(1, count($previewAudits));
};

$tests['unresolved_token_emits_audit_with_redacted_token'] = function () {
    $f = makeQrLaunchFixture();
    try {
        $f['service']->previewByToken(makeQrLaunchUser(), 'ffffffffffffffffffffffffffffffff');
    } catch (InvalidArgumentException) {
        // expected
    }
    $audits = array_values(array_filter(
        $f['audit']->entries,
        fn(AuditEntry $e) => $e->event === 'inspection.qr_launch.unresolved_token'
    ));
    qrAssertSame(1, count($audits));
    qrAssertTrue(strlen($audits[0]->context['qr_token']) <= 9);
};

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

echo "InspectionQrLaunchServiceTest\n";
$pass = 0;
$fail = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  pass: {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  FAIL: {$name} — " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "  ---\n";
echo "  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
