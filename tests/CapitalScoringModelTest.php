<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "CapitalScoringModelTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\CapitalScoringModel;
use App\Models\SiteAsset;
use App\Models\User;
use App\Services\Assets\AssetLifecycleService;
use App\Services\Assets\SiteAssetRepository;
use App\Services\CapitalPlan\AgingAssetReportService;
use App\Services\CapitalPlan\CapitalPlanController;
use App\Services\CapitalPlan\CapitalScoringModelRepository;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 9.2 of docs/expansion-plan.md — tunable lifecycle scoring model.
 * Covers: repo CRUD + clamps, division-aware findActive resolution order,
 * setDefault transactional toggle, normalized weights, AssetLifecycleService
 * with custom thresholds, projectedReplacementCostCents, AgingAssetReportService
 * picking up the right model, controller gate denials.
 */

class CsmInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function csmSetUpDatabase(): PDO
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

    return $pdo;
}

class CsmPermissiveGate extends AccessGate
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

function makeCsmFixture(): array
{
    $pdo = csmSetUpDatabase();
    $conn = new CsmInMemoryConnection($pdo);
    $gate = new CsmPermissiveGate();
    $repo = new CapitalScoringModelRepository($conn);
    $assetRepo = new SiteAssetRepository($conn);
    $lifecycle = new AssetLifecycleService($assetRepo);
    $aging = new AgingAssetReportService($conn, $assetRepo, $lifecycle, $gate, $repo);
    $controller = new CapitalPlanController($aging, $repo, $gate);

    $pdo->exec("INSERT INTO divisions (id, name) VALUES (1, 'Auto'), (2, 'HVAC')");
    $pdo->exec("INSERT INTO companies (id, name) VALUES (10, 'Acme')");
    $pdo->exec("INSERT INTO sites (id, company_id, division_id, name) VALUES
        (100, 10, 1, 'Acme HQ'),
        (101, 10, 2, 'Acme Warehouse')");

    return compact('pdo', 'conn', 'gate', 'repo', 'lifecycle', 'aging', 'controller', 'assetRepo');
}

function csmAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function csmAssertNear(float $expected, float $actual, float $tol, string $msg = ''): void
{
    if (abs($expected - $actual) > $tol) {
        throw new RuntimeException("FAIL {$msg}: expected ~{$expected}, got {$actual}");
    }
}

function csmAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function csmAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
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

function makeCsmUser(): User
{
    $u = new User();
    $u->id = 1;
    $u->role = 'manager';
    return $u;
}

function csmSeedAsset(
    PDO $pdo,
    int $id,
    int $siteId,
    string $name,
    ?int $cond,
    ?float $life,
    ?int $rep,
    ?string $rby,
    ?string $inst = null
): void {
    $stmt = $pdo->prepare("INSERT INTO site_assets
        (id, site_id, name, status, condition_score, expected_life_years,
         replacement_estimate_cents, replace_by_date, install_date)
        VALUES (:id, :site_id, :name, 'active', :c, :l, :r, :rb, :i)");
    $stmt->execute([
        'id' => $id, 'site_id' => $siteId, 'name' => $name,
        'c' => $cond, 'l' => $life, 'r' => $rep, 'rb' => $rby, 'i' => $inst,
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── CapitalScoringModel pure model ────

$tests['fallback_model_uses_historical_defaults'] = function () {
    $m = CapitalScoringModel::fallback();
    csmAssertSame(0.5, $m->condition_weight);
    csmAssertSame(0.3, $m->age_weight);
    csmAssertSame(0.2, $m->replace_by_weight);
    csmAssertSame(40.0, $m->watch_threshold);
    csmAssertSame(60.0, $m->action_threshold);
    csmAssertSame(80.0, $m->urgent_threshold);
    csmAssertSame(0.03, $m->annual_inflation_rate);
};

$tests['normalized_weights_scale_to_sum_one'] = function () {
    $m = CapitalScoringModel::fallback();
    $m->condition_weight = 0.6;
    $m->age_weight = 0.4;
    $m->replace_by_weight = 0.4; // sum = 1.4
    $w = $m->normalizedWeights();
    csmAssertNear(1.0, $w['condition'] + $w['age'] + $w['replace_by'], 1e-9, 'must sum to 1.0');
    csmAssertNear(0.6 / 1.4, $w['condition'], 1e-9);
};

$tests['normalized_weights_zero_sum_falls_back_to_defaults'] = function () {
    $m = CapitalScoringModel::fallback();
    $m->condition_weight = 0.0;
    $m->age_weight = 0.0;
    $m->replace_by_weight = 0.0;
    $w = $m->normalizedWeights();
    csmAssertSame(0.5, $w['condition'], 'fallback to default condition weight');
};

// ──── Repository CRUD + clamping ────

$tests['repo_create_clamps_out_of_range_weights_and_thresholds'] = function () {
    $f = makeCsmFixture();
    $m = $f['repo']->create([
        'name' => 'Aggressive',
        'condition_weight' => 1.5,    // clamped to 1.0
        'age_weight' => -0.2,         // clamped to 0.0
        'replace_by_weight' => 0.4,
        'watch_threshold' => 200.0,   // clamped to 100.0
        'action_threshold' => -10.0,  // clamped to 0.0
        'urgent_threshold' => 75.0,
        'annual_inflation_rate' => 5.0, // clamped to 0.5
    ]);
    csmAssertSame(1.0, $m->condition_weight);
    csmAssertSame(0.0, $m->age_weight);
    csmAssertSame(100.0, $m->watch_threshold);
    csmAssertSame(0.0, $m->action_threshold);
    csmAssertSame(0.5, $m->annual_inflation_rate);
};

$tests['repo_create_global_model_when_division_id_omitted'] = function () {
    $f = makeCsmFixture();
    $m = $f['repo']->create(['name' => 'Global Default']);
    csmAssertSame(null, $m->division_id);
    csmAssertSame('Global Default', $m->name);
};

$tests['repo_update_changes_specific_fields_only'] = function () {
    $f = makeCsmFixture();
    $m = $f['repo']->create(['name' => 'M1', 'condition_weight' => 0.7]);
    $u = $f['repo']->update($m->id, ['watch_threshold' => 35.0]);
    csmAssertSame(0.7, $u->condition_weight, 'untouched field preserved');
    csmAssertSame(35.0, $u->watch_threshold);
};

$tests['repo_delete_removes_row'] = function () {
    $f = makeCsmFixture();
    $m = $f['repo']->create(['name' => 'Doomed']);
    $f['repo']->delete($m->id);
    csmAssertSame(null, $f['repo']->findById($m->id));
};

// ──── Resolution order (the heart of 9.2) ────

$tests['resolve_returns_division_default_when_present'] = function () {
    $f = makeCsmFixture();
    $f['repo']->create(['name' => 'Auto Default', 'division_id' => 1, 'is_default' => 1, 'urgent_threshold' => 70.0]);
    $f['repo']->create(['name' => 'Global Default', 'is_default' => 1, 'urgent_threshold' => 80.0]);
    $resolved = $f['repo']->findActiveForDivision(1);
    csmAssertSame('Auto Default', $resolved->name);
    csmAssertSame(70.0, $resolved->urgent_threshold);
};

$tests['resolve_falls_back_to_any_division_row_when_no_default'] = function () {
    $f = makeCsmFixture();
    $f['repo']->create(['name' => 'Auto Custom', 'division_id' => 1, 'is_default' => 0]);
    $f['repo']->create(['name' => 'Global', 'is_default' => 1]);
    $resolved = $f['repo']->findActiveForDivision(1);
    csmAssertSame('Auto Custom', $resolved->name, 'division row beats global default');
};

$tests['resolve_falls_back_to_global_default_when_no_division_row'] = function () {
    $f = makeCsmFixture();
    $f['repo']->create(['name' => 'Global Default', 'is_default' => 1]);
    $resolved = $f['repo']->findActiveForDivision(1);
    csmAssertSame('Global Default', $resolved->name);
};

$tests['resolve_falls_back_to_built_in_when_table_empty'] = function () {
    $f = makeCsmFixture();
    $resolved = $f['repo']->findActiveForDivision(1);
    csmAssertSame('system-default', $resolved->name);
    csmAssertSame(0, $resolved->id);
};

$tests['resolve_with_null_division_skips_division_lookups'] = function () {
    $f = makeCsmFixture();
    $f['repo']->create(['name' => 'Global', 'is_default' => 1]);
    $resolved = $f['repo']->findActiveForDivision(null);
    csmAssertSame('Global', $resolved->name);
};

// ──── setDefault transactional toggle ────

$tests['set_default_clears_siblings_in_same_division'] = function () {
    $f = makeCsmFixture();
    $a = $f['repo']->create(['name' => 'A', 'division_id' => 1, 'is_default' => 1]);
    $b = $f['repo']->create(['name' => 'B', 'division_id' => 1]);
    $f['repo']->setDefault($b->id);
    csmAssertSame(false, $f['repo']->findById($a->id)->is_default);
    csmAssertSame(true, $f['repo']->findById($b->id)->is_default);
};

$tests['set_default_does_not_clear_other_division_defaults'] = function () {
    $f = makeCsmFixture();
    $auto = $f['repo']->create(['name' => 'AutoDefault', 'division_id' => 1, 'is_default' => 1]);
    $hvac = $f['repo']->create(['name' => 'HvacDefault', 'division_id' => 2, 'is_default' => 1]);
    $other = $f['repo']->create(['name' => 'AutoOther', 'division_id' => 1]);
    $f['repo']->setDefault($other->id);
    csmAssertSame(false, $f['repo']->findById($auto->id)->is_default, 'sibling cleared');
    csmAssertSame(true, $f['repo']->findById($hvac->id)->is_default, 'other division untouched');
    csmAssertSame(true, $f['repo']->findById($other->id)->is_default);
};

$tests['set_default_clears_other_globals_when_target_is_global'] = function () {
    $f = makeCsmFixture();
    $g1 = $f['repo']->create(['name' => 'G1', 'is_default' => 1]);
    $g2 = $f['repo']->create(['name' => 'G2']);
    $f['repo']->setDefault($g2->id);
    csmAssertSame(false, $f['repo']->findById($g1->id)->is_default);
    csmAssertSame(true, $f['repo']->findById($g2->id)->is_default);
};

// ──── AssetLifecycleService with custom model ────

$tests['lifecycle_uses_custom_thresholds_for_categorization'] = function () {
    $f = makeCsmFixture();
    csmSeedAsset($f['pdo'], 1, 100, 'Mid', 50, 10.0, 100000, '2030-01-01');
    $asset = $f['assetRepo']->findById(1);
    // With defaults: condition=50 → conditionRisk=50 → risk=25 → ok
    $defaultScore = $f['lifecycle']->scoreAsset($asset, new DateTimeImmutable('2026-04-24'));
    csmAssertSame('ok', $defaultScore['category']);

    // With watch_threshold lowered to 20, same risk=25 → watch
    $aggressive = CapitalScoringModel::fallback();
    $aggressive->watch_threshold = 20.0;
    $aggressive->action_threshold = 70.0;
    $aggressive->urgent_threshold = 90.0;
    $tunedScore = $f['lifecycle']->scoreAsset($asset, new DateTimeImmutable('2026-04-24'), $aggressive);
    csmAssertSame('watch', $tunedScore['category'], 'lower threshold flipped ok→watch');
};

$tests['lifecycle_uses_custom_weights_when_normalized'] = function () {
    $f = makeCsmFixture();
    // Asset: condition=80 (low risk), age 0, replace_by 5y out (no replace risk)
    csmSeedAsset($f['pdo'], 1, 100, 'A', 80, 10.0, 100000, '2031-01-01');
    $asset = $f['assetRepo']->findById(1);
    // Heavily weight condition only: 1.0 condition / 0 age / 0 replace_by
    $weighted = CapitalScoringModel::fallback();
    $weighted->condition_weight = 1.0;
    $weighted->age_weight = 0.0;
    $weighted->replace_by_weight = 0.0;
    $score = $f['lifecycle']->scoreAsset($asset, new DateTimeImmutable('2026-04-24'), $weighted);
    // conditionRisk = 100 - 80 = 20, weighted 1.0 → risk = 20.0
    csmAssertNear(20.0, $score['risk'], 0.1);
};

$tests['lifecycle_scoring_model_id_surfaces_when_persisted'] = function () {
    $f = makeCsmFixture();
    csmSeedAsset($f['pdo'], 1, 100, 'A', 50, 10.0, 100000, '2030-01-01');
    $asset = $f['assetRepo']->findById(1);
    $persisted = $f['repo']->create(['name' => 'M1']);
    $score = $f['lifecycle']->scoreAsset($asset, new DateTimeImmutable('2026-04-24'), $persisted);
    csmAssertSame($persisted->id, $score['scoring_model_id']);
};

// ──── projectedReplacementCostCents ────

$tests['project_replacement_inflates_at_default_three_percent'] = function () {
    $f = makeCsmFixture();
    // 1.03^5 ≈ 1.1593
    $projected = $f['lifecycle']->projectedReplacementCostCents(100000, 5);
    csmAssertTrue(abs($projected - 115927) < 5, "expected ~115927, got {$projected}");
};

$tests['project_replacement_uses_model_inflation_rate'] = function () {
    $f = makeCsmFixture();
    $hot = CapitalScoringModel::fallback();
    $hot->annual_inflation_rate = 0.10; // 10% annual
    // 1.10^5 = 1.61051
    $projected = $f['lifecycle']->projectedReplacementCostCents(100000, 5, $hot);
    csmAssertTrue(abs($projected - 161051) < 5, "expected ~161051, got {$projected}");
};

$tests['project_replacement_returns_null_for_null_or_nonpositive_base'] = function () {
    $f = makeCsmFixture();
    csmAssertSame(null, $f['lifecycle']->projectedReplacementCostCents(null, 5));
    csmAssertSame(null, $f['lifecycle']->projectedReplacementCostCents(0, 5));
    csmAssertSame(null, $f['lifecycle']->projectedReplacementCostCents(-1000, 5));
};

$tests['project_replacement_clamps_extreme_years_out'] = function () {
    $f = makeCsmFixture();
    // 9999 years would overflow; clamp to 100
    $projected = $f['lifecycle']->projectedReplacementCostCents(100, 9999);
    csmAssertTrue(is_int($projected) && $projected > 0, 'must produce a finite int');
};

// ──── Integration: AgingAssetReportService picks up division model ────

$tests['aging_report_uses_division_specific_model'] = function () {
    $f = makeCsmFixture();
    $f['repo']->create([
        'name' => 'Auto Aggressive',
        'division_id' => 1,
        'is_default' => 1,
        'watch_threshold' => 10.0,  // very aggressive
        'action_threshold' => 20.0,
        'urgent_threshold' => 30.0,
    ]);
    csmSeedAsset($f['pdo'], 1, 100, 'A', 50, 10.0, 100000, '2031-01-01');
    $report = $f['aging']->reportForDivision(makeCsmUser(), 1, new DateTimeImmutable('2026-04-24'));
    csmAssertSame('Auto Aggressive', $report['scoring_model']['name']);
    // condition=50 → conditionRisk=50, total risk≈25. With urgent_threshold=30, this becomes urgent.
    csmAssertSame(1, $report['summary']['urgent']);
};

$tests['aging_report_surfaces_scoring_model_metadata_in_payload'] = function () {
    $f = makeCsmFixture();
    $report = $f['aging']->reportForDivision(makeCsmUser(), 1);
    csmAssertTrue(isset($report['scoring_model']));
    csmAssertSame('system-default', $report['scoring_model']['name']);
    csmAssertSame(0.03, $report['scoring_model']['annual_inflation_rate']);
};

$tests['aging_report_includes_projected_replacement_cents_per_asset'] = function () {
    $f = makeCsmFixture();
    csmSeedAsset($f['pdo'], 1, 100, 'A', 30, 10.0, 100000, '2031-01-01');
    $report = $f['aging']->reportForCompany(makeCsmUser(), 10, new DateTimeImmutable('2026-04-24'));
    csmAssertTrue(isset($report['top_risks'][0]['projected_replacement_cents']));
    // 5 years out at default 3% → ~115927
    csmAssertTrue($report['top_risks'][0]['projected_replacement_cents'] > 100000);
};

$tests['aging_report_populates_capex_horizon_projected_buckets'] = function () {
    $f = makeCsmFixture();
    csmSeedAsset($f['pdo'], 1, 100, 'A', 50, 10.0, 100000, '2031-01-01');
    $report = $f['aging']->reportForCompany(makeCsmUser(), 10, new DateTimeImmutable('2026-04-24'));
    $bucket = $report['summary']['capex_horizon_projected']['next_60mo_cents'];
    csmAssertTrue($bucket > 100000, "projected bucket should reflect inflated value, got {$bucket}");
};

// ──── Controller permission gating ────

$tests['controller_list_requires_capital_plans_view'] = function () {
    $f = makeCsmFixture();
    $f['gate']->denials['capital_plans.view'] = true;
    csmAssertThrows(
        fn() => $f['controller']->listScoringModels(makeCsmUser()),
        UnauthorizedException::class
    );
};

$tests['controller_create_requires_capital_plans_manage'] = function () {
    $f = makeCsmFixture();
    $f['gate']->denials['capital_plans.manage'] = true;
    csmAssertThrows(
        fn() => $f['controller']->createScoringModel(makeCsmUser(), ['name' => 'X']),
        UnauthorizedException::class
    );
};

$tests['controller_create_rejects_missing_name'] = function () {
    $f = makeCsmFixture();
    csmAssertThrows(
        fn() => $f['controller']->createScoringModel(makeCsmUser(), []),
        InvalidArgumentException::class
    );
};

$tests['controller_get_returns_404_style_error_for_missing'] = function () {
    $f = makeCsmFixture();
    csmAssertThrows(
        fn() => $f['controller']->getScoringModel(makeCsmUser(), 999),
        InvalidArgumentException::class
    );
};

$tests['controller_set_default_round_trips'] = function () {
    $f = makeCsmFixture();
    $a = $f['controller']->createScoringModel(makeCsmUser(), ['name' => 'A', 'division_id' => 1]);
    $b = $f['controller']->createScoringModel(makeCsmUser(), ['name' => 'B', 'division_id' => 1]);
    $resp = $f['controller']->setDefaultScoringModel(makeCsmUser(), (int) $b['data']['id']);
    csmAssertSame(true, $resp['data']['is_default']);
    $aRow = $f['controller']->getScoringModel(makeCsmUser(), (int) $a['data']['id']);
    csmAssertSame(false, $aRow['data']['is_default']);
};

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

echo "CapitalScoringModelTest\n";
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
