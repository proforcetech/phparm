<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "AgingAssetReportServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\User;
use App\Services\Assets\AssetLifecycleService;
use App\Services\Assets\SiteAssetRepository;
use App\Services\CapitalPlan\AgingAssetReportService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 9.1 of docs/expansion-plan.md — aging asset report.
 * Cross-site rollup, capex bucketing, top-risk surfacing, gate denial.
 */

class AgingInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function agingSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE divisions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_id INTEGER NOT NULL,
        division_id INTEGER NULL,
        name TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE site_assets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        division_id INTEGER NULL,
        service_line_id INTEGER NULL,
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

    return $pdo;
}

class AgingPermissiveGate extends AccessGate
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
function makeAgingFixture(): array
{
    $pdo = agingSetUpDatabase();
    $conn = new AgingInMemoryConnection($pdo);
    $gate = new AgingPermissiveGate();
    $assetRepo = new SiteAssetRepository($conn);
    $lifecycle = new AssetLifecycleService($assetRepo);
    $service = new AgingAssetReportService($conn, $assetRepo, $lifecycle, $gate);

    $pdo->exec("INSERT INTO divisions (id, name) VALUES (1, 'Auto'), (2, 'HVAC')");
    $pdo->exec("INSERT INTO companies (id, name) VALUES (10, 'Acme'), (20, 'Globex')");
    // Acme: sites 100 (Auto) + 101 (HVAC); Globex: site 200 (Auto)
    $pdo->exec("INSERT INTO sites (id, company_id, division_id, name) VALUES
        (100, 10, 1, 'Acme HQ'),
        (101, 10, 2, 'Acme Warehouse'),
        (200, 20, 1, 'Globex Plant')");

    return ['pdo' => $pdo, 'conn' => $conn, 'gate' => $gate, 'service' => $service];
}

function agingSeedAsset(
    PDO $pdo,
    int $id,
    int $siteId,
    string $name,
    ?int $conditionScore,
    ?float $expectedLifeYears,
    ?int $replacementEstimateCents,
    ?string $replaceByDate,
    ?string $installDate = null,
    string $status = 'active',
): void {
    $stmt = $pdo->prepare("INSERT INTO site_assets
        (id, site_id, name, status, condition_score, expected_life_years,
         replacement_estimate_cents, replace_by_date, install_date)
        VALUES (:id, :site_id, :name, :status, :cond, :life, :rep, :rby, :inst)");
    $stmt->execute([
        'id' => $id,
        'site_id' => $siteId,
        'name' => $name,
        'status' => $status,
        'cond' => $conditionScore,
        'life' => $expectedLifeYears,
        'rep' => $replacementEstimateCents,
        'rby' => $replaceByDate,
        'inst' => $installDate,
    ]);
}

function agingAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function agingAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function agingAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
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

function makeAgingUser(): User
{
    $u = new User();
    $u->id = 1;
    $u->role = 'manager';
    return $u;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

$tests['company_report_aggregates_only_that_companys_sites'] = function () {
    $f = makeAgingFixture();
    // Acme assets at site 100 + 101
    agingSeedAsset($f['pdo'], 1, 100, 'Boiler A', 30, 10.0, 500000, '2030-01-01');
    agingSeedAsset($f['pdo'], 2, 101, 'AC Unit 1', 80, 8.0, 200000, '2032-06-01');
    // Globex assets at site 200 — must NOT appear
    agingSeedAsset($f['pdo'], 3, 200, 'Pump B', 20, 5.0, 700000, '2026-01-01');

    $report = $f['service']->reportForCompany(makeAgingUser(), 10);
    agingAssertSame('company', $report['scope']['type']);
    agingAssertSame(10, $report['scope']['id']);
    agingAssertSame('Acme', $report['scope']['label']);
    agingAssertSame(2, $report['summary']['total']);
    agingAssertSame(2, $report['summary']['sites_count']);
    agingAssertSame(700000, $report['summary']['replacement_estimate_cents']);
};

$tests['division_report_aggregates_across_companies'] = function () {
    $f = makeAgingFixture();
    // Auto division spans Acme HQ (site 100) + Globex Plant (site 200)
    agingSeedAsset($f['pdo'], 1, 100, 'Lift A', 60, 15.0, 100000, '2030-01-01');
    agingSeedAsset($f['pdo'], 2, 200, 'Lift B', 50, 15.0, 100000, '2031-01-01');
    // HVAC site 101 — must NOT appear in Auto (div=1) report
    agingSeedAsset($f['pdo'], 3, 101, 'AC X', 70, 8.0, 50000, '2033-01-01');

    $report = $f['service']->reportForDivision(makeAgingUser(), 1);
    agingAssertSame('division', $report['scope']['type']);
    agingAssertSame(2, $report['summary']['total']);
    agingAssertSame(2, $report['summary']['sites_count']);
};

$tests['portfolio_report_includes_every_active_asset'] = function () {
    $f = makeAgingFixture();
    agingSeedAsset($f['pdo'], 1, 100, 'A1', 50, 10.0, 100000, '2030-01-01');
    agingSeedAsset($f['pdo'], 2, 101, 'A2', 50, 10.0, 100000, '2031-01-01');
    agingSeedAsset($f['pdo'], 3, 200, 'A3', 50, 10.0, 100000, '2032-01-01');

    $report = $f['service']->reportForPortfolio(makeAgingUser());
    agingAssertSame('portfolio', $report['scope']['type']);
    agingAssertSame(null, $report['scope']['id']);
    agingAssertSame(3, $report['summary']['total']);
    agingAssertSame(3, $report['summary']['sites_count']);
};

$tests['retired_assets_excluded_from_default_filter'] = function () {
    $f = makeAgingFixture();
    agingSeedAsset($f['pdo'], 1, 100, 'Active', 50, 10.0, 100000, '2030-01-01');
    agingSeedAsset($f['pdo'], 2, 100, 'Retired', 10, 5.0, 999999, '2025-01-01', null, 'retired');

    $report = $f['service']->reportForCompany(makeAgingUser(), 10);
    agingAssertSame(1, $report['summary']['total'], 'retired should be excluded');
    agingAssertSame(100000, $report['summary']['replacement_estimate_cents']);
};

$tests['capex_horizon_buckets_by_replace_by_date'] = function () {
    $f = makeAgingFixture();
    $now = new DateTimeImmutable('2026-04-24');
    // overdue (replace_by in the past)
    agingSeedAsset($f['pdo'], 1, 100, 'Overdue', 30, 5.0, 100000, '2026-01-01');
    // 0-12mo (within next 12 months)
    agingSeedAsset($f['pdo'], 2, 100, 'Soon', 70, 10.0, 200000, '2026-12-01');
    // 12-24mo
    agingSeedAsset($f['pdo'], 3, 100, '18mo', 70, 10.0, 300000, '2027-08-01');
    // 24-60mo
    agingSeedAsset($f['pdo'], 4, 100, '4yr', 80, 10.0, 400000, '2030-01-01');
    // beyond 5yr
    agingSeedAsset($f['pdo'], 5, 100, 'Far', 80, 10.0, 500000, '2035-01-01');
    // unscheduled (NULL replace_by_date)
    agingSeedAsset($f['pdo'], 6, 100, 'NoSchedule', 80, 10.0, 600000, null);

    $report = $f['service']->reportForCompany(makeAgingUser(), 10, $now);
    $h = $report['summary']['capex_horizon'];
    agingAssertSame(100000, $h['overdue_cents']);
    agingAssertSame(200000, $h['next_12mo_cents']);
    agingAssertSame(300000, $h['next_24mo_cents']);
    agingAssertSame(400000, $h['next_60mo_cents']);
    agingAssertSame(1100000, $h['beyond_or_unscheduled_cents'], 'far + unscheduled both bucket here');
    agingAssertSame(2100000, $report['summary']['replacement_estimate_cents']);
};

$tests['by_site_breakdown_lists_each_site_with_counts'] = function () {
    $f = makeAgingFixture();
    agingSeedAsset($f['pdo'], 1, 100, 'OK', 90, 10.0, 100000, '2032-01-01');
    agingSeedAsset($f['pdo'], 2, 100, 'Watch', 50, 10.0, 100000, '2032-01-01');
    agingSeedAsset($f['pdo'], 3, 101, 'Action', 30, 10.0, 100000, '2032-01-01');

    $report = $f['service']->reportForCompany(makeAgingUser(), 10);
    $bySite = $report['by_site'];
    agingAssertSame(2, count($bySite));
    $site100 = current(array_filter($bySite, fn($s) => $s['site_id'] === 100));
    agingAssertSame(2, $site100['total']);
    agingAssertSame(200000, $site100['replacement_estimate_cents']);
};

$tests['by_site_sorted_urgent_first_then_action_then_max_risk'] = function () {
    $f = makeAgingFixture();
    $now = new DateTimeImmutable('2026-04-24');
    // Site 100: 1 urgent (replace_by past) + condition very low triggers urgent
    agingSeedAsset($f['pdo'], 1, 100, 'Crisis', 5, 5.0, 100000, '2025-01-01');
    // Site 101: just one watch-level
    agingSeedAsset($f['pdo'], 2, 101, 'Mild', 55, 10.0, 100000, '2030-01-01');

    $report = $f['service']->reportForCompany(makeAgingUser(), 10, $now);
    $bySite = $report['by_site'];
    agingAssertSame(100, $bySite[0]['site_id'], 'urgent site should sort first');
    agingAssertTrue($bySite[0]['urgent'] >= 1);
};

$tests['top_risks_capped_and_sorted_desc_by_risk'] = function () {
    $f = makeAgingFixture();
    // Seed 30 assets so the cap (25) kicks in
    for ($i = 1; $i <= 30; $i++) {
        $cond = max(5, 100 - ($i * 3));
        agingSeedAsset($f['pdo'], $i, 100, "A{$i}", $cond, 10.0, 100000, '2030-01-01');
    }
    $report = $f['service']->reportForCompany(makeAgingUser(), 10);
    agingAssertSame(25, count($report['top_risks']), 'top_risks capped at 25');
    // Risk is descending
    for ($i = 1; $i < count($report['top_risks']); $i++) {
        agingAssertTrue(
            $report['top_risks'][$i - 1]['risk'] >= $report['top_risks'][$i]['risk'],
            'top_risks should be sorted descending by risk'
        );
    }
};

$tests['top_risks_includes_site_context'] = function () {
    $f = makeAgingFixture();
    agingSeedAsset($f['pdo'], 1, 100, 'A', 20, 5.0, 100000, '2025-01-01');
    $report = $f['service']->reportForCompany(makeAgingUser(), 10);
    agingAssertSame(100, $report['top_risks'][0]['site_id']);
    agingAssertSame('Acme HQ', $report['top_risks'][0]['site_name']);
};

$tests['risk_categorization_agrees_with_lifecycle_service'] = function () {
    // Pin a `now` so age-based math is deterministic.
    $now = new DateTimeImmutable('2026-04-24');
    $f = makeAgingFixture();

    // OK — fresh, distant replace, no age data. risk≈2.5
    agingSeedAsset($f['pdo'], 1, 100, 'OK_unit', 95, 10.0, 100000, '2040-01-01');

    // Watch — condition=20, no age, distant replace.
    // condRisk=80*.5=40, ageRisk=0, replaceBy=0 → risk=40 → watch (>=40)
    agingSeedAsset($f['pdo'], 2, 100, 'Watch_unit', 20, 10.0, 100000, '2040-01-01');

    // Action — old + low-but-not-extreme condition, distant replace.
    // condition=10 → condRisk=90 → 90*.5=45
    // installed 2010 / 10y expected → age=16.3y → ageRisk capped 100 → 100*.3=30
    // replaceBy distant → 0
    // total = 75 → action (>=60)
    agingSeedAsset($f['pdo'], 3, 100, 'Action_unit', 10, 10.0, 100000, '2040-01-01', '2010-01-01');

    // Urgent — replace_by date already passed.
    agingSeedAsset($f['pdo'], 4, 100, 'Urgent_unit', 50, 10.0, 100000, '2025-01-01');

    $report = $f['service']->reportForCompany(makeAgingUser(), 10, $now);
    agingAssertSame(4, $report['summary']['total']);
    agingAssertSame(
        4,
        $report['summary']['ok']
            + $report['summary']['watch']
            + $report['summary']['action']
            + $report['summary']['urgent'],
        'every asset must land in exactly one category'
    );
    agingAssertSame(1, $report['summary']['ok']);
    agingAssertSame(1, $report['summary']['watch']);
    agingAssertSame(1, $report['summary']['action']);
    agingAssertSame(1, $report['summary']['urgent']);
};

$tests['empty_scope_returns_zeroed_summary_no_errors'] = function () {
    $f = makeAgingFixture();
    $report = $f['service']->reportForCompany(makeAgingUser(), 10);
    agingAssertSame(0, $report['summary']['total']);
    agingAssertSame(0, $report['summary']['sites_count']);
    agingAssertSame([], $report['by_site']);
    agingAssertSame([], $report['top_risks']);
    agingAssertSame(0, $report['summary']['capex_horizon']['overdue_cents']);
};

$tests['unknown_company_label_falls_back_to_id_string'] = function () {
    $f = makeAgingFixture();
    $report = $f['service']->reportForCompany(makeAgingUser(), 9999);
    agingAssertSame('Company #9999', $report['scope']['label']);
    agingAssertSame(0, $report['summary']['total']);
};

$tests['unknown_division_label_falls_back_to_id_string'] = function () {
    $f = makeAgingFixture();
    $report = $f['service']->reportForDivision(makeAgingUser(), 9999);
    agingAssertSame('Division #9999', $report['scope']['label']);
};

$tests['rejects_non_positive_company_id'] = function () {
    $f = makeAgingFixture();
    agingAssertThrows(
        fn() => $f['service']->reportForCompany(makeAgingUser(), 0),
        InvalidArgumentException::class
    );
};

$tests['rejects_non_positive_division_id'] = function () {
    $f = makeAgingFixture();
    agingAssertThrows(
        fn() => $f['service']->reportForDivision(makeAgingUser(), -1),
        InvalidArgumentException::class
    );
};

$tests['company_report_denies_when_assets_view_blocked'] = function () {
    $f = makeAgingFixture();
    $f['gate']->denials['assets.view'] = true;
    agingAssertThrows(
        fn() => $f['service']->reportForCompany(makeAgingUser(), 10),
        UnauthorizedException::class
    );
};

$tests['portfolio_report_requires_capital_plans_view'] = function () {
    $f = makeAgingFixture();
    $f['gate']->denials['capital_plans.view'] = true;
    agingAssertThrows(
        fn() => $f['service']->reportForPortfolio(makeAgingUser()),
        UnauthorizedException::class
    );
};

$tests['scope_dict_includes_generated_at_iso_timestamp'] = function () {
    $f = makeAgingFixture();
    agingSeedAsset($f['pdo'], 1, 100, 'A', 50, 10.0, 100000, '2030-01-01');
    $now = new DateTimeImmutable('2026-04-24T12:00:00+00:00');
    $report = $f['service']->reportForCompany(makeAgingUser(), 10, $now);
    agingAssertTrue(strpos((string) $report['generated_at'], '2026-04-24') === 0);
};

$tests['assets_with_null_estimate_dont_break_summary'] = function () {
    $f = makeAgingFixture();
    agingSeedAsset($f['pdo'], 1, 100, 'NoEstimate', 50, 10.0, null, '2030-01-01');
    agingSeedAsset($f['pdo'], 2, 100, 'WithEstimate', 50, 10.0, 50000, '2030-01-01');
    $report = $f['service']->reportForCompany(makeAgingUser(), 10);
    agingAssertSame(2, $report['summary']['total']);
    agingAssertSame(50000, $report['summary']['replacement_estimate_cents']);
};

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

echo "AgingAssetReportServiceTest\n";
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
