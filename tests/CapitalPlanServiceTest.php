<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "CapitalPlanServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\CapitalPlan;
use App\Models\User;
use App\Services\Assets\AssetLifecycleService;
use App\Services\Assets\SiteAssetRepository;
use App\Services\CapitalPlan\CapitalPlanRepository;
use App\Services\CapitalPlan\CapitalPlanService;
use App\Services\CapitalPlan\CapitalScoringModelRepository;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 9.3 — multi-year budget planner + what-if scenarios.
 * Verifies persistence chain, scenario compute math (overdue/horizon/beyond
 * bucketing), per-asset overrides (excluded / pin / defer / estimate), global
 * options (defer_all_months, accelerate_urgent_to_year, inflation_rate_override),
 * inflation projection, gate denials, baseline auto-mint, baseline protections.
 */

class CpsInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function cpsSetUpDatabase(): PDO
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
    $pdo->exec("CREATE TABLE capital_scoring_models (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        division_id INTEGER NULL,
        name TEXT NOT NULL,
        is_default INTEGER NOT NULL DEFAULT 0,
        condition_weight REAL NOT NULL DEFAULT 0.500,
        age_weight REAL NOT NULL DEFAULT 0.300,
        replace_by_weight REAL NOT NULL DEFAULT 0.200,
        watch_threshold REAL NOT NULL DEFAULT 40.00,
        action_threshold REAL NOT NULL DEFAULT 60.00,
        urgent_threshold REAL NOT NULL DEFAULT 80.00,
        annual_inflation_rate REAL NOT NULL DEFAULT 0.0300,
        notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE capital_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        scope_type TEXT NOT NULL,
        scope_id INTEGER NULL,
        base_year INTEGER NOT NULL,
        horizon_years INTEGER NOT NULL DEFAULT 5,
        scoring_model_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        notes TEXT NULL,
        created_by_user_id INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE capital_plan_scenarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        capital_plan_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        is_baseline INTEGER NOT NULL DEFAULT 0,
        global_options TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE capital_plan_scenario_overrides (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scenario_id INTEGER NOT NULL,
        site_asset_id INTEGER NOT NULL,
        defer_months INTEGER NULL,
        pin_to_year INTEGER NULL,
        replacement_estimate_cents_override INTEGER NULL,
        excluded INTEGER NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_at TEXT NULL
    )");

    return $pdo;
}

class CpsPermissiveGate extends AccessGate
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

function cpsUser(): User
{
    $u = new User();
    $u->id = 7;
    $u->role = 'manager';
    return $u;
}

function makeCpsFixture(): array
{
    $pdo = cpsSetUpDatabase();
    $conn = new CpsInMemoryConnection($pdo);
    $gate = new CpsPermissiveGate();
    $assetRepo = new SiteAssetRepository($conn);
    $lifecycle = new AssetLifecycleService($assetRepo);
    $scoringRepo = new CapitalScoringModelRepository($conn);
    $planRepo = new CapitalPlanRepository($conn);
    $service = new CapitalPlanService($planRepo, $assetRepo, $lifecycle, $scoringRepo, $gate);

    $pdo->exec("INSERT INTO divisions (id, name) VALUES (1, 'HVAC'), (2, 'Plumbing')");
    $pdo->exec("INSERT INTO companies (id, name) VALUES (10, 'Acme'), (11, 'Globex')");
    $pdo->exec("INSERT INTO sites (id, company_id, division_id, name) VALUES
        (100, 10, 1, 'Acme HQ'),
        (101, 10, 2, 'Acme Annex'),
        (200, 11, 1, 'Globex Plant')");

    return compact('pdo', 'conn', 'gate', 'service', 'planRepo', 'scoringRepo', 'assetRepo', 'lifecycle');
}

function cpsSeedAsset(
    PDO $pdo,
    int $id,
    int $siteId,
    string $name,
    ?int $cond,
    ?int $estCents,
    ?string $replaceBy,
    ?string $install = null,
    ?float $life = null,
    string $status = 'active'
): void {
    $stmt = $pdo->prepare("INSERT INTO site_assets
        (id, site_id, name, status, condition_score, replacement_estimate_cents,
         replace_by_date, install_date, expected_life_years)
        VALUES (:id, :s, :n, :st, :c, :r, :rb, :i, :l)");
    $stmt->execute([
        'id' => $id, 's' => $siteId, 'n' => $name, 'st' => $status,
        'c' => $cond, 'r' => $estCents, 'rb' => $replaceBy, 'i' => $install, 'l' => $life,
    ]);
}

function cpsAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function cpsAssertNear(float $expected, float $actual, float $tol, string $msg = ''): void
{
    if (abs($expected - $actual) > $tol) {
        throw new RuntimeException("FAIL {$msg}: expected ~{$expected}, got {$actual}");
    }
}

function cpsAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function cpsAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
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

function cpsBucketByYear(array $compute, int $year): array
{
    foreach ($compute['years'] as $b) {
        if ((int) $b['year'] === $year) {
            return $b;
        }
    }
    throw new RuntimeException("year {$year} not in compute payload");
}

function cpsAssetLine(array $compute, int $assetId): array
{
    foreach ($compute['assets'] as $line) {
        if ((int) $line['asset_id'] === $assetId) {
            return $line;
        }
    }
    throw new RuntimeException("asset {$assetId} not in compute payload");
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── Plan lifecycle ────

$tests['create_plan_auto_mints_baseline_scenario'] = function () {
    $f = makeCpsFixture();
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'FY26 HVAC',
        'scope_type' => CapitalPlan::SCOPE_DIVISION,
        'scope_id' => 1,
        'base_year' => 2026,
        'horizon_years' => 5,
    ]);
    cpsAssertTrue($plan->id > 0, 'plan persisted');
    $scenarios = $f['service']->listScenarios(cpsUser(), $plan->id);
    cpsAssertSame(1, count($scenarios), 'one auto-baseline');
    cpsAssertSame(true, $scenarios[0]->is_baseline);
    cpsAssertSame('Baseline', $scenarios[0]->name);
};

$tests['create_plan_validates_scope_type'] = function () {
    $f = makeCpsFixture();
    cpsAssertThrows(
        fn() => $f['service']->createPlan(cpsUser(), [
            'name' => 'X', 'scope_type' => 'unknown', 'base_year' => 2026,
        ]),
        InvalidArgumentException::class
    );
};

$tests['create_plan_requires_scope_id_for_company_or_division'] = function () {
    $f = makeCpsFixture();
    cpsAssertThrows(
        fn() => $f['service']->createPlan(cpsUser(), [
            'name' => 'X', 'scope_type' => CapitalPlan::SCOPE_DIVISION, 'base_year' => 2026,
        ]),
        InvalidArgumentException::class
    );
};

$tests['create_plan_allows_portfolio_without_scope_id'] = function () {
    $f = makeCpsFixture();
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'Portfolio26',
        'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026,
    ]);
    cpsAssertSame(null, $plan->scope_id);
};

$tests['create_plan_requires_manage_permission'] = function () {
    $f = makeCpsFixture();
    $f['gate']->denials['capital_plans.manage'] = true;
    cpsAssertThrows(
        fn() => $f['service']->createPlan(cpsUser(), [
            'name' => 'X', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO, 'base_year' => 2026,
        ]),
        UnauthorizedException::class
    );
};

$tests['list_plans_requires_view_permission'] = function () {
    $f = makeCpsFixture();
    $f['gate']->denials['capital_plans.view'] = true;
    cpsAssertThrows(
        fn() => $f['service']->listPlans(cpsUser()),
        UnauthorizedException::class
    );
};

$tests['update_plan_persists_changes'] = function () {
    $f = makeCpsFixture();
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'Original',
        'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026,
    ]);
    $updated = $f['service']->updatePlan(cpsUser(), $plan->id, [
        'name' => 'Renamed',
        'status' => CapitalPlan::STATUS_PUBLISHED,
        'horizon_years' => 7,
    ]);
    cpsAssertSame('Renamed', $updated->name);
    cpsAssertSame('published', $updated->status);
    cpsAssertSame(7, $updated->horizon_years);
};

$tests['delete_plan_cascades_in_practice'] = function () {
    $f = makeCpsFixture();
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'Doomed', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO, 'base_year' => 2026,
    ]);
    $f['service']->deletePlan(cpsUser(), $plan->id);
    cpsAssertThrows(
        fn() => $f['service']->findPlan(cpsUser(), $plan->id),
        InvalidArgumentException::class
    );
};

// ──── Scenario CRUD ────

$tests['add_scenario_strips_is_baseline_flag'] = function () {
    $f = makeCpsFixture();
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO, 'base_year' => 2026,
    ]);
    $sc = $f['service']->addScenario(cpsUser(), $plan->id, [
        'name' => 'Sneaky',
        'is_baseline' => true, // user attempt — should be stripped
    ]);
    cpsAssertSame(false, $sc->is_baseline, 'baseline flag must not be settable from user payload');
};

$tests['cannot_delete_baseline_scenario'] = function () {
    $f = makeCpsFixture();
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO, 'base_year' => 2026,
    ]);
    $scenarios = $f['service']->listScenarios(cpsUser(), $plan->id);
    $baseline = $scenarios[0];
    cpsAssertThrows(
        fn() => $f['service']->deleteScenario(cpsUser(), $baseline->id),
        InvalidArgumentException::class
    );
};

$tests['can_delete_non_baseline_scenario'] = function () {
    $f = makeCpsFixture();
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO, 'base_year' => 2026,
    ]);
    $sc = $f['service']->addScenario(cpsUser(), $plan->id, ['name' => 'Variant']);
    $f['service']->deleteScenario(cpsUser(), $sc->id);
    $remaining = $f['service']->listScenarios(cpsUser(), $plan->id);
    cpsAssertSame(1, count($remaining));
    cpsAssertSame(true, $remaining[0]->is_baseline);
};

$tests['scenario_global_options_round_trip_as_array'] = function () {
    $f = makeCpsFixture();
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO, 'base_year' => 2026,
    ]);
    $sc = $f['service']->addScenario(cpsUser(), $plan->id, [
        'name' => 'Defer12',
        'global_options' => ['defer_all_months' => 12, 'accelerate_urgent_to_year' => 2026],
    ]);
    cpsAssertSame(12, $sc->global_options['defer_all_months']);
    cpsAssertSame(2026, $sc->global_options['accelerate_urgent_to_year']);
};

$tests['cannot_attach_override_to_baseline'] = function () {
    $f = makeCpsFixture();
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO, 'base_year' => 2026,
    ]);
    $baseline = $f['service']->listScenarios(cpsUser(), $plan->id)[0];
    cpsAssertThrows(
        fn() => $f['service']->setOverride(cpsUser(), $baseline->id, 1, ['defer_months' => 6]),
        InvalidArgumentException::class
    );
};

// ──── Compute scenario — bucketing math ────

$tests['baseline_buckets_by_replace_year'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'AC1', 70, 100000, '2026-06-01');
    cpsSeedAsset($f['pdo'], 2, 100, 'AC2', 50, 200000, '2027-06-01');
    cpsSeedAsset($f['pdo'], 3, 100, 'AC3', 30, 300000, '2028-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_COMPANY, 'scope_id' => 10,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $baseline = $f['service']->listScenarios(cpsUser(), $plan->id)[0];
    $now = new DateTimeImmutable('2026-04-24');
    $r = $f['service']->computeScenario(cpsUser(), $baseline->id, $now);
    cpsAssertSame(100000, cpsBucketByYear($r, 2026)['raw_cents']);
    cpsAssertSame(200000, cpsBucketByYear($r, 2027)['raw_cents']);
    cpsAssertSame(300000, cpsBucketByYear($r, 2028)['raw_cents']);
    cpsAssertSame(0, cpsBucketByYear($r, 2030)['raw_cents'], '2030 should be empty');
};

$tests['overdue_assets_land_in_overdue_bucket'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'OldUnit', 20, 50000, '2024-01-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $baseline = $f['service']->listScenarios(cpsUser(), $plan->id)[0];
    $now = new DateTimeImmutable('2026-04-24');
    $r = $f['service']->computeScenario(cpsUser(), $baseline->id, $now);
    cpsAssertSame(50000, $r['overdue']['raw_cents']);
    cpsAssertSame(1, $r['overdue']['asset_count']);
    cpsAssertSame('overdue', cpsAssetLine($r, 1)['bucket']);
};

$tests['far_future_assets_land_in_beyond_bucket'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'NewUnit', 95, 750000, '2040-01-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $baseline = $f['service']->listScenarios(cpsUser(), $plan->id)[0];
    $r = $f['service']->computeScenario(cpsUser(), $baseline->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(750000, $r['beyond']['raw_cents']);
    cpsAssertSame('beyond', cpsAssetLine($r, 1)['bucket']);
};

$tests['scope_filter_company_excludes_other_companies'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'Acme1', 70, 100000, '2026-06-01');
    cpsSeedAsset($f['pdo'], 2, 200, 'Globex1', 70, 999999, '2026-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_COMPANY, 'scope_id' => 10,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $baseline = $f['service']->listScenarios(cpsUser(), $plan->id)[0];
    $r = $f['service']->computeScenario(cpsUser(), $baseline->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(1, $r['totals']['asset_count'], 'only Acme asset in scope');
    cpsAssertSame(100000, $r['totals']['raw_cents']);
};

$tests['scope_filter_division_excludes_other_divisions'] = function () {
    $f = makeCpsFixture();
    // site 100 is division 1, site 101 is division 2
    cpsSeedAsset($f['pdo'], 1, 100, 'HVAC1', 70, 100000, '2026-06-01');
    cpsSeedAsset($f['pdo'], 2, 101, 'Plumb1', 70, 200000, '2026-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_DIVISION, 'scope_id' => 1,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $baseline = $f['service']->listScenarios(cpsUser(), $plan->id)[0];
    $r = $f['service']->computeScenario(cpsUser(), $baseline->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(1, $r['totals']['asset_count']);
    cpsAssertSame(100000, $r['totals']['raw_cents']);
};

$tests['retired_assets_excluded_from_compute'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'Live', 70, 100000, '2026-06-01');
    cpsSeedAsset($f['pdo'], 2, 100, 'Retired', 70, 99999, '2026-06-01', null, null, 'retired');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $baseline = $f['service']->listScenarios(cpsUser(), $plan->id)[0];
    $r = $f['service']->computeScenario(cpsUser(), $baseline->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(1, $r['totals']['asset_count']);
};

// ──── Per-asset overrides ────

$tests['excluded_override_drops_asset_from_totals'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'Keep', 70, 100000, '2026-06-01');
    cpsSeedAsset($f['pdo'], 2, 100, 'Drop', 70, 200000, '2026-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, ['name' => 'Skip2']);
    $f['service']->setOverride(cpsUser(), $variant->id, 2, ['excluded' => true]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(100000, $r['totals']['raw_cents']);
    cpsAssertSame(1, $r['counts']['excluded']);
};

$tests['pin_to_year_overrides_baseline_year'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2028-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, ['name' => 'PinTo2026']);
    $f['service']->setOverride(cpsUser(), $variant->id, 1, ['pin_to_year' => 2026]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(100000, cpsBucketByYear($r, 2026)['raw_cents']);
    cpsAssertSame(0, cpsBucketByYear($r, 2028)['raw_cents']);
    cpsAssertSame(2026, cpsAssetLine($r, 1)['replace_year']);
    cpsAssertTrue(in_array('pin', cpsAssetLine($r, 1)['overrides_applied'], true));
};

$tests['defer_months_shifts_replace_year'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2027-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, ['name' => 'Defer18']);
    $f['service']->setOverride(cpsUser(), $variant->id, 1, ['defer_months' => 18]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    // 2027-06-01 + 18 months → 2028-12-01
    cpsAssertSame(2028, cpsAssetLine($r, 1)['replace_year']);
    cpsAssertSame(100000, cpsBucketByYear($r, 2028)['raw_cents']);
};

$tests['estimate_override_replaces_asset_estimate'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2027-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, ['name' => 'CostUpdate']);
    $f['service']->setOverride(cpsUser(), $variant->id, 1, [
        'replacement_estimate_cents_override' => 250000,
    ]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(250000, cpsAssetLine($r, 1)['raw_cents']);
    cpsAssertSame(250000, $r['totals']['raw_cents']);
};

$tests['pin_overrides_defer_months_when_both_set'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2027-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, ['name' => 'Both']);
    $f['service']->setOverride(cpsUser(), $variant->id, 1, [
        'pin_to_year' => 2030,
        'defer_months' => 999,
    ]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(2030, cpsAssetLine($r, 1)['replace_year']);
};

$tests['upsert_override_overwrites_previous'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2027-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, ['name' => 'V']);
    $first = $f['service']->setOverride(cpsUser(), $variant->id, 1, ['defer_months' => 6]);
    $second = $f['service']->setOverride(cpsUser(), $variant->id, 1, ['defer_months' => 24]);
    cpsAssertSame($first->id, $second->id, 'same row updated, not duplicated');
    cpsAssertSame(24, $second->defer_months);
    $list = $f['service']->listOverrides(cpsUser(), $variant->id);
    cpsAssertSame(1, count($list));
};

// ──── Global options ────

$tests['global_defer_all_months_shifts_unoverridden_assets'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2026-06-01');
    cpsSeedAsset($f['pdo'], 2, 100, 'B', 70, 200000, '2027-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, [
        'name' => 'Push12',
        'global_options' => ['defer_all_months' => 12],
    ]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(2027, cpsAssetLine($r, 1)['replace_year']);
    cpsAssertSame(2028, cpsAssetLine($r, 2)['replace_year']);
};

$tests['per_asset_override_takes_priority_over_global_defer'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2026-06-01');
    cpsSeedAsset($f['pdo'], 2, 100, 'B', 70, 200000, '2026-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, [
        'name' => 'Mix',
        'global_options' => ['defer_all_months' => 24],
    ]);
    $f['service']->setOverride(cpsUser(), $variant->id, 1, ['pin_to_year' => 2026]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(2026, cpsAssetLine($r, 1)['replace_year'], 'pinned asset stays put');
    cpsAssertSame(2028, cpsAssetLine($r, 2)['replace_year'], 'others get global defer');
};

$tests['accelerate_urgent_pulls_only_urgent_forward'] = function () {
    $f = makeCpsFixture();
    // Asset 1: urgent via condition+age (cond=0 → conditionRisk=100; install
    // 2010 with 10y life → ageRisk=100; far-future replace_by → replaceByRisk=0;
    // risk = 100*0.5 + 100*0.3 + 0 = 80, hits urgent_threshold). Scheduled for
    // 2030 so the accelerator is genuinely pulling it forward, not delaying.
    cpsSeedAsset($f['pdo'], 1, 100, 'Urgent', 0, 100000, '2030-06-01', '2010-01-01', 10.0);
    // Asset 2: ok category, also scheduled for 2030
    cpsSeedAsset($f['pdo'], 2, 100, 'Ok', 95, 200000, '2030-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, [
        'name' => 'Pull',
        'global_options' => ['accelerate_urgent_to_year' => 2026],
    ]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(2026, cpsAssetLine($r, 1)['replace_year'], 'urgent pulled to 2026');
    cpsAssertSame(2030, cpsAssetLine($r, 2)['replace_year'], 'non-urgent untouched');
};

$tests['accelerate_urgent_never_delays'] = function () {
    $f = makeCpsFixture();
    // Urgent asset already at 2025 (would be overdue at base_year 2026)
    cpsSeedAsset($f['pdo'], 1, 100, 'Now', 20, 100000, '2025-01-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, [
        'name' => 'PullForward',
        'global_options' => ['accelerate_urgent_to_year' => 2028],
    ]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    // Urgent's replace_year is 2025 (before base_year). Accelerate 2028 only
    // pulls *forward*, so 2025 < 2028 should stay 2025 → still 'overdue'.
    cpsAssertSame('overdue', cpsAssetLine($r, 1)['bucket']);
};

// ──── Inflation projection ────

$tests['projected_cents_uses_scoring_model_inflation'] = function () {
    $f = makeCpsFixture();
    $f['scoringRepo']->create([
        'name' => 'global', 'is_default' => true,
        'annual_inflation_rate' => 0.10,
    ]);
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2028-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $baseline = $f['service']->listScenarios(cpsUser(), $plan->id)[0];
    $r = $f['service']->computeScenario(cpsUser(), $baseline->id, new DateTimeImmutable('2026-04-24'));
    // 100000 * (1.10 ^ 2) = 121000
    cpsAssertSame(121000, cpsBucketByYear($r, 2028)['projected_cents']);
    cpsAssertSame(100000, cpsBucketByYear($r, 2028)['raw_cents']);
};

$tests['inflation_rate_override_replaces_model_rate'] = function () {
    $f = makeCpsFixture();
    $f['scoringRepo']->create([
        'name' => 'global', 'is_default' => true,
        'annual_inflation_rate' => 0.03,
    ]);
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2028-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, [
        'name' => 'HighInflation',
        'global_options' => ['inflation_rate_override' => 0.20],
    ]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    // 100000 * (1.20 ^ 2) = 144000
    cpsAssertSame(144000, cpsBucketByYear($r, 2028)['projected_cents']);
    cpsAssertNear(0.20, $r['scoring_model']['annual_inflation_rate'], 1e-9);
};

// ──── Compare ────

$tests['compare_returns_all_scenarios_for_plan_when_no_filter'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2027-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $f['service']->addScenario(cpsUser(), $plan->id, [
        'name' => 'Defer',
        'global_options' => ['defer_all_months' => 12],
    ]);
    $r = $f['service']->compareScenarios(cpsUser(), $plan->id);
    cpsAssertSame(2, count($r['scenarios']), 'baseline + variant');
};

$tests['compare_filters_to_requested_scenarios'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2027-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, ['name' => 'V']);
    $r = $f['service']->compareScenarios(cpsUser(), $plan->id, [$variant->id]);
    cpsAssertSame(1, count($r['scenarios']));
    cpsAssertSame($variant->id, $r['scenarios'][0]['scenario']['id']);
};

// ──── Scoring model resolution ────

$tests['plan_with_explicit_scoring_model_id_uses_it'] = function () {
    $f = makeCpsFixture();
    $explicit = $f['scoringRepo']->create([
        'name' => 'Aggressive',
        'annual_inflation_rate' => 0.50,
    ]);
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2028-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
        'scoring_model_id' => $explicit->id,
    ]);
    $baseline = $f['service']->listScenarios(cpsUser(), $plan->id)[0];
    $r = $f['service']->computeScenario(cpsUser(), $baseline->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame($explicit->id, $r['scoring_model']['id']);
};

$tests['division_plan_uses_division_scoring_model'] = function () {
    $f = makeCpsFixture();
    $f['scoringRepo']->create([
        'name' => 'HVAC tuned', 'division_id' => 1, 'is_default' => true,
        'annual_inflation_rate' => 0.10,
    ]);
    cpsSeedAsset($f['pdo'], 1, 100, 'HVACUnit', 70, 100000, '2028-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'HVAC FY26', 'scope_type' => CapitalPlan::SCOPE_DIVISION, 'scope_id' => 1,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $baseline = $f['service']->listScenarios(cpsUser(), $plan->id)[0];
    $r = $f['service']->computeScenario(cpsUser(), $baseline->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame('HVAC tuned', $r['scoring_model']['name']);
};

// ──── Counts metadata ────

$tests['counts_reports_pinned_deferred_excluded'] = function () {
    $f = makeCpsFixture();
    cpsSeedAsset($f['pdo'], 1, 100, 'A', 70, 100000, '2027-06-01');
    cpsSeedAsset($f['pdo'], 2, 100, 'B', 70, 200000, '2027-06-01');
    cpsSeedAsset($f['pdo'], 3, 100, 'C', 70, 300000, '2027-06-01');
    cpsSeedAsset($f['pdo'], 4, 100, 'D', 70, 400000, '2027-06-01');
    $plan = $f['service']->createPlan(cpsUser(), [
        'name' => 'P', 'scope_type' => CapitalPlan::SCOPE_PORTFOLIO,
        'base_year' => 2026, 'horizon_years' => 5,
    ]);
    $variant = $f['service']->addScenario(cpsUser(), $plan->id, ['name' => 'Mixed']);
    $f['service']->setOverride(cpsUser(), $variant->id, 1, ['pin_to_year' => 2026]);
    $f['service']->setOverride(cpsUser(), $variant->id, 2, ['defer_months' => 12]);
    $f['service']->setOverride(cpsUser(), $variant->id, 3, ['excluded' => true]);
    $r = $f['service']->computeScenario(cpsUser(), $variant->id, new DateTimeImmutable('2026-04-24'));
    cpsAssertSame(4, $r['counts']['assets_in_scope']);
    cpsAssertSame(1, $r['counts']['pinned']);
    cpsAssertSame(1, $r['counts']['deferred']);
    cpsAssertSame(1, $r['counts']['excluded']);
    cpsAssertSame(3, $r['counts']['with_overrides']);
};

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

echo "CapitalPlanServiceTest\n";
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
