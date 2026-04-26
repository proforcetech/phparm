<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "WorkorderKitInstallServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\User;
use App\Models\WorkorderKitInstall;
use App\Models\WorkorderKitInstallItem;
use App\Services\Estimate\BundleService;
use App\Services\Inventory\InventoryTransactionRepository;
use App\Services\Inventory\InventoryVehicleCompatibilityRepository;
use App\Services\Workorder\Kit\WorkorderKitInstallController;
use App\Services\Workorder\Kit\WorkorderKitInstallRepository;
use App\Services\Workorder\Kit\WorkorderKitInstallService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 10.8 of docs/expansion-plan.md — Kit/bundle install support.
 *
 * Covers:
 *   - Model constants (STATUSES, ALLOWED_TRANSITIONS).
 *   - Repository CRUD for installs + items, status filter, item updates.
 *   - Service.plan: snapshots bundle items, no inventory movement.
 *   - Service.install: materialises workorder_items, decrements stock for
 *     PART rows resolved against inventory, records inventory_transactions
 *     with source='workorder_kit_install', updates total_parts_consumed.
 *   - Service.install: skips stock consumption for non-PART items, and
 *     for PART items without a matching inventory row.
 *   - Service.install: caps consumption at available stock (no negative
 *     inventory) and returns 0 when item has zero stock.
 *   - Service.cancel: planned → cancelled (no inventory move).
 *   - Service.cancel: installed → cancelled returns stock and removes
 *     workorder_items rows it created.
 *   - Service.delete: only allowed on planned installs.
 *   - Permission gates (.view, .install, .cancel, .manage).
 *   - Controller envelope shape.
 *   - Default workorder_jobs resolution when install header doesn't pin a
 *     job target.
 *   - Install rejected if no jobs exist on the workorder.
 */

class WkInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

class WkPermissiveGate extends AccessGate
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

function wkSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    // workorder + jobs (minimal columns the service touches).
    $pdo->exec("CREATE TABLE workorders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NULL
    )");
    $pdo->exec("CREATE TABLE workorder_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE workorder_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_job_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        description TEXT NOT NULL,
        quantity REAL NOT NULL DEFAULT 1,
        unit_price REAL NOT NULL DEFAULT 0,
        taxable INTEGER NOT NULL DEFAULT 1,
        line_total REAL NOT NULL DEFAULT 0,
        position INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs(id) ON DELETE CASCADE
    )");

    // Inventory ledger + items (minimal).
    $pdo->exec("CREATE TABLE inventory_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT NULL,
        sku TEXT NULL,
        stock_quantity INTEGER NOT NULL DEFAULT 0,
        sale_price REAL NULL,
        cost REAL NULL,
        list_price REAL NULL
    )");
    $pdo->exec("CREATE TABLE inventory_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        inventory_item_id INTEGER NOT NULL,
        branch_id INTEGER NULL,
        quantity_before INTEGER NOT NULL,
        quantity_after INTEGER NOT NULL,
        quantity_change INTEGER NOT NULL,
        source TEXT NOT NULL,
        reference TEXT NULL,
        reason TEXT NULL,
        created_by INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Bundles + items.
    $pdo->exec("CREATE TABLE bundles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT NULL,
        internal_notes TEXT NULL,
        discount_type TEXT NULL,
        discount_value REAL NULL,
        service_type_id INTEGER NULL,
        default_job_title TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE bundle_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bundle_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        description TEXT NOT NULL,
        quantity REAL NOT NULL DEFAULT 1,
        unit_price REAL NOT NULL DEFAULT 0,
        taxable INTEGER NOT NULL DEFAULT 1,
        discount_type TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (bundle_id) REFERENCES bundles(id) ON DELETE CASCADE
    )");

    // Install tables (the unit under test).
    $pdo->exec("CREATE TABLE workorder_kit_installs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NOT NULL,
        workorder_job_id INTEGER NULL,
        bundle_id INTEGER NULL,
        bundle_name_snapshot TEXT NOT NULL,
        installed_by_user_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'planned',
        planned_at TEXT NULL,
        installed_at TEXT NULL,
        cancelled_at TEXT NULL,
        cancellation_reason TEXT NULL,
        notes TEXT NULL,
        total_parts_consumed INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE workorder_kit_install_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        install_id INTEGER NOT NULL,
        workorder_item_id INTEGER NULL,
        bundle_item_id INTEGER NULL,
        inventory_item_id INTEGER NULL,
        type TEXT NOT NULL,
        description TEXT NOT NULL,
        quantity REAL NOT NULL DEFAULT 1,
        unit_price REAL NOT NULL DEFAULT 0,
        line_total REAL NOT NULL DEFAULT 0,
        stock_consumed INTEGER NOT NULL DEFAULT 0,
        stock_consumed_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (install_id) REFERENCES workorder_kit_installs(id) ON DELETE CASCADE
    )");

    // BundleService::applyToEstimate joins customer_vehicles; we don't
    // exercise that codepath but the table reference must resolve so the
    // ctor won't barf on a fresh-install schema.
    $pdo->exec("CREATE TABLE customer_vehicles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_master_id INTEGER NULL
    )");
    $pdo->exec("CREATE TABLE service_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL
    )");

    return $pdo;
}

function wkAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function wkAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function wkAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
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

function makeWkUser(int $id = 7, string $role = 'technician'): User
{
    $u = new User();
    $u->id = $id;
    $u->role = $role;
    return $u;
}

function makeWkFixture(): array
{
    $pdo = wkSetUpDatabase();
    $conn = new WkInMemoryConnection($pdo);
    $gate = new WkPermissiveGate();
    $repo = new WorkorderKitInstallRepository($conn);
    $compat = new InventoryVehicleCompatibilityRepository($conn);
    $bundleService = new BundleService($conn, $compat);
    $txRepo = new InventoryTransactionRepository($conn);
    $service = new WorkorderKitInstallService($conn, $repo, $bundleService, $txRepo, $gate);
    $controller = new WorkorderKitInstallController($service);
    return compact('pdo', 'conn', 'gate', 'repo', 'bundleService', 'txRepo', 'service', 'controller');
}

function wkSeedWorkorderWithJob(PDO $pdo): array
{
    $pdo->exec("INSERT INTO workorders (title) VALUES ('WO 1')");
    $woId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO workorder_jobs (workorder_id, title, position) VALUES (?, 'Brakes', 0)")
        ->execute([$woId]);
    $jobId = (int) $pdo->lastInsertId();
    return ['workorder_id' => $woId, 'workorder_job_id' => $jobId];
}

function wkSeedBundle(BundleService $bundleService, array $items): int
{
    $bundle = $bundleService->create([
        'name' => 'Front Brake Kit',
        'default_job_title' => 'Front brake service',
        'items' => $items,
    ]);
    return (int) $bundle->id;
}

function wkSeedInventory(PDO $pdo, string $name, int $stock): int
{
    $pdo->prepare("INSERT INTO inventory_items (name, description, sku, stock_quantity) VALUES (?, ?, NULL, ?)")
        ->execute([$name, $name, $stock]);
    return (int) $pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── Model constants ────

$tests['statuses_published'] = function () {
    wkAssertSame(['planned', 'installed', 'cancelled'], WorkorderKitInstall::STATUSES);
};

$tests['allowed_transitions_published'] = function () {
    wkAssertTrue(in_array('installed', WorkorderKitInstall::ALLOWED_TRANSITIONS['planned'], true),
        'planned → installed allowed');
    wkAssertTrue(in_array('cancelled', WorkorderKitInstall::ALLOWED_TRANSITIONS['planned'], true),
        'planned → cancelled allowed');
    wkAssertTrue(in_array('cancelled', WorkorderKitInstall::ALLOWED_TRANSITIONS['installed'], true),
        'installed → cancelled allowed');
    wkAssertSame([], WorkorderKitInstall::ALLOWED_TRANSITIONS['cancelled'],
        'cancelled is terminal');
};

$tests['item_types_published'] = function () {
    wkAssertTrue(in_array('PART', WorkorderKitInstallItem::TYPES, true), 'PART listed');
    wkAssertTrue(in_array('LABOR', WorkorderKitInstallItem::TYPES, true), 'LABOR listed');
    wkAssertTrue(in_array('FEE', WorkorderKitInstallItem::TYPES, true), 'FEE listed');
    wkAssertTrue(in_array('DISCOUNT', WorkorderKitInstallItem::TYPES, true), 'DISCOUNT listed');
};

// ──── Repository basics ────

$tests['repo_create_and_find'] = function () {
    $f = makeWkFixture();
    $install = $f['repo']->create([
        'workorder_id' => 100,
        'workorder_job_id' => null,
        'bundle_id' => 1,
        'bundle_name_snapshot' => 'Test Kit',
        'status' => 'planned',
    ]);
    wkAssertTrue($install->id !== null, 'id assigned');
    $found = $f['repo']->find((int) $install->id);
    wkAssertSame(100, $found->workorder_id);
    wkAssertSame('Test Kit', $found->bundle_name_snapshot);
};

$tests['repo_status_filter'] = function () {
    $f = makeWkFixture();
    $f['repo']->create(['workorder_id' => 1, 'bundle_name_snapshot' => 'A', 'status' => 'planned']);
    $f['repo']->create(['workorder_id' => 1, 'bundle_name_snapshot' => 'B', 'status' => 'installed']);
    $planned = $f['repo']->listByStatus('planned');
    wkAssertSame(1, count($planned));
    wkAssertSame('A', $planned[0]->bundle_name_snapshot);
};

$tests['repo_item_round_trip'] = function () {
    $f = makeWkFixture();
    $install = $f['repo']->create(['workorder_id' => 1, 'bundle_name_snapshot' => 'X', 'status' => 'planned']);
    $item = $f['repo']->addItem((int) $install->id, [
        'type' => 'PART',
        'description' => 'Brake pad',
        'quantity' => 2.0,
        'unit_price' => 25.0,
        'line_total' => 50.0,
    ]);
    wkAssertTrue($item->id !== null);
    $items = $f['repo']->itemsForInstall((int) $install->id);
    wkAssertSame(1, count($items));
    wkAssertSame(2.0, $items[0]->quantity);
    $f['repo']->updateItem((int) $item->id, ['stock_consumed' => 2, 'stock_consumed_at' => '2026-04-24 10:00:00']);
    $reread = $f['repo']->findItem((int) $item->id);
    wkAssertSame(2, $reread->stock_consumed);
};

// ──── Service.plan ────

$tests['plan_snapshots_bundle_items_without_inventory_move'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $invId = wkSeedInventory($f['pdo'], 'Brake Pad', 10);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'PART', 'description' => 'Brake Pad', 'quantity' => 2, 'unit_price' => 30],
        ['type' => 'LABOR', 'description' => 'Install', 'quantity' => 1, 'unit_price' => 80],
    ]);

    $bundle = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'bundle_id' => $bundleId,
    ]);

    wkAssertSame('planned', $bundle['install']['status']);
    wkAssertSame(2, count($bundle['items']));
    wkAssertSame(0, $bundle['install']['total_parts_consumed']);
    // No inventory movement.
    $row = $f['pdo']->query("SELECT stock_quantity FROM inventory_items WHERE id = {$invId}")->fetch(PDO::FETCH_ASSOC);
    wkAssertSame(10, (int) $row['stock_quantity'], 'plan does not touch inventory');
    wkAssertSame(0, (int) $f['pdo']->query("SELECT COUNT(*) FROM workorder_items")->fetchColumn(),
        'plan does not create WO line items');
};

$tests['plan_rejects_unknown_bundle'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    wkAssertThrows(
        fn () => $f['service']->plan(makeWkUser(7), ['workorder_id' => $seed['workorder_id'], 'bundle_id' => 999]),
        InvalidArgumentException::class
    );
};

$tests['plan_rejects_empty_bundle'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], []);
    wkAssertThrows(
        fn () => $f['service']->plan(makeWkUser(7), ['workorder_id' => $seed['workorder_id'], 'bundle_id' => $bundleId]),
        InvalidArgumentException::class
    );
};

// ──── Service.install ────

$tests['install_creates_wo_items_and_decrements_stock'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $invId = wkSeedInventory($f['pdo'], 'Brake Pad', 10);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'PART', 'description' => 'Brake Pad', 'quantity' => 2, 'unit_price' => 30],
        ['type' => 'LABOR', 'description' => 'Install', 'quantity' => 1, 'unit_price' => 80],
    ]);

    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);

    $installed = $f['service']->install(makeWkUser(7), (int) $planned['install']['id']);

    wkAssertSame('installed', $installed['install']['status']);
    wkAssertSame(2, $installed['install']['total_parts_consumed'], 'parts consumed = 2');

    // Inventory decremented.
    $stock = (int) $f['pdo']->query("SELECT stock_quantity FROM inventory_items WHERE id = {$invId}")->fetchColumn();
    wkAssertSame(8, $stock, 'stock decremented from 10 to 8');

    // WO items created.
    $count = (int) $f['pdo']->query("SELECT COUNT(*) FROM workorder_items WHERE workorder_job_id = {$seed['workorder_job_id']}")->fetchColumn();
    wkAssertSame(2, $count, 'two WO line items created (one per snapshot item)');

    // Inventory ledger entry recorded.
    $tx = $f['pdo']->query("SELECT source, quantity_change FROM inventory_transactions WHERE inventory_item_id = {$invId}")->fetch(PDO::FETCH_ASSOC);
    wkAssertSame('workorder_kit_install', $tx['source']);
    wkAssertSame(-2, (int) $tx['quantity_change'], 'ledger shows -2 for the consumption');

    // Snapshot items now carry workorder_item_id and stock_consumed for PART.
    $partItem = null;
    foreach ($installed['items'] as $item) {
        if ($item['type'] === 'PART') {
            $partItem = $item;
        }
    }
    wkAssertTrue($partItem !== null, 'PART snapshot item reachable');
    wkAssertSame(2, $partItem['stock_consumed']);
    wkAssertTrue($partItem['workorder_item_id'] !== null, 'workorder_item_id back-linked');
};

$tests['install_skips_consumption_for_non_part_items'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'Diagnostic', 'quantity' => 1, 'unit_price' => 100],
        ['type' => 'FEE', 'description' => 'Shop fee', 'quantity' => 1, 'unit_price' => 5],
    ]);

    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    $installed = $f['service']->install(makeWkUser(7), (int) $planned['install']['id']);
    wkAssertSame(0, $installed['install']['total_parts_consumed'], 'no PART items → no consumption');
    wkAssertSame(0, (int) $f['pdo']->query("SELECT COUNT(*) FROM inventory_transactions")->fetchColumn());
};

$tests['install_skips_consumption_when_no_inventory_match'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    // No inventory_items row exists for this description.
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'PART', 'description' => 'Mystery widget', 'quantity' => 1, 'unit_price' => 5],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    $installed = $f['service']->install(makeWkUser(7), (int) $planned['install']['id']);
    wkAssertSame(0, $installed['install']['total_parts_consumed']);
    wkAssertSame(1, (int) $f['pdo']->query("SELECT COUNT(*) FROM workorder_items")->fetchColumn(),
        'WO line item still created — parts pull system fills the gap');
};

$tests['install_caps_at_available_stock'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $invId = wkSeedInventory($f['pdo'], 'Rotor', 1);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'PART', 'description' => 'Rotor', 'quantity' => 2, 'unit_price' => 50],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    $installed = $f['service']->install(makeWkUser(7), (int) $planned['install']['id']);
    $stock = (int) $f['pdo']->query("SELECT stock_quantity FROM inventory_items WHERE id = {$invId}")->fetchColumn();
    wkAssertSame(0, $stock, 'consumption capped at available — never goes negative');
    wkAssertSame(1, $installed['install']['total_parts_consumed']);
};

$tests['install_skips_when_stock_is_zero'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    wkSeedInventory($f['pdo'], 'OutOfStock', 0);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'PART', 'description' => 'OutOfStock', 'quantity' => 1, 'unit_price' => 10],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    $installed = $f['service']->install(makeWkUser(7), (int) $planned['install']['id']);
    wkAssertSame(0, $installed['install']['total_parts_consumed']);
    wkAssertSame(0, (int) $f['pdo']->query("SELECT COUNT(*) FROM inventory_transactions")->fetchColumn(),
        'no inventory movement when stock is zero');
};

$tests['install_resolves_default_job_when_header_unpinned'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10],
    ]);
    // Plan WITHOUT workorder_job_id pinned.
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'bundle_id' => $bundleId,
    ]);
    wkAssertSame(null, $planned['install']['workorder_job_id'], 'plan accepted without job target');

    $installed = $f['service']->install(makeWkUser(7), (int) $planned['install']['id']);
    wkAssertSame('installed', $installed['install']['status'], 'install resolves the default job from WO');
    $count = (int) $f['pdo']->query("SELECT COUNT(*) FROM workorder_items WHERE workorder_job_id = {$seed['workorder_job_id']}")->fetchColumn();
    wkAssertSame(1, $count, 'line item created against the default job');
};

$tests['install_rejected_when_no_jobs_exist'] = function () {
    $f = makeWkFixture();
    $f['pdo']->exec("INSERT INTO workorders (title) VALUES ('WO without jobs')");
    $woId = (int) $f['pdo']->lastInsertId();
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $woId,
        'bundle_id' => $bundleId,
    ]);
    wkAssertThrows(
        fn () => $f['service']->install(makeWkUser(7), (int) $planned['install']['id']),
        RuntimeException::class
    );
};

$tests['install_rejects_invalid_transition'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    $f['service']->install(makeWkUser(7), (int) $planned['install']['id']);
    // Second install on the same already-installed row should fail.
    wkAssertThrows(
        fn () => $f['service']->install(makeWkUser(7), (int) $planned['install']['id']),
        InvalidArgumentException::class
    );
};

// ──── Service.cancel ────

$tests['cancel_planned_does_not_touch_inventory'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $invId = wkSeedInventory($f['pdo'], 'Brake Pad', 10);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'PART', 'description' => 'Brake Pad', 'quantity' => 2, 'unit_price' => 30],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    $cancelled = $f['service']->cancel(makeWkUser(7), (int) $planned['install']['id'], 'Wrong kit');
    wkAssertSame('cancelled', $cancelled['install']['status']);
    wkAssertSame('Wrong kit', $cancelled['install']['cancellation_reason']);
    $stock = (int) $f['pdo']->query("SELECT stock_quantity FROM inventory_items WHERE id = {$invId}")->fetchColumn();
    wkAssertSame(10, $stock, 'planned cancel does not touch inventory');
};

$tests['cancel_installed_returns_stock_and_removes_wo_items'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $invId = wkSeedInventory($f['pdo'], 'Brake Pad', 10);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'PART', 'description' => 'Brake Pad', 'quantity' => 3, 'unit_price' => 30],
        ['type' => 'LABOR', 'description' => 'Install', 'quantity' => 1, 'unit_price' => 80],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    $f['service']->install(makeWkUser(7), (int) $planned['install']['id']);
    wkAssertSame(7, (int) $f['pdo']->query("SELECT stock_quantity FROM inventory_items WHERE id = {$invId}")->fetchColumn(),
        'pre-cancel: stock at 7');

    $cancelled = $f['service']->cancel(makeWkUser(7), (int) $planned['install']['id'], 'Wrong call');
    wkAssertSame('cancelled', $cancelled['install']['status']);
    wkAssertSame(0, $cancelled['install']['total_parts_consumed']);
    wkAssertSame(10, (int) $f['pdo']->query("SELECT stock_quantity FROM inventory_items WHERE id = {$invId}")->fetchColumn(),
        'stock returned to 10');
    wkAssertSame(0, (int) $f['pdo']->query("SELECT COUNT(*) FROM workorder_items")->fetchColumn(),
        'WO line items removed');
    // Reversal ledger entry recorded.
    $reverseTx = $f['pdo']->query("SELECT COUNT(*) FROM inventory_transactions WHERE source = 'workorder_kit_install_cancel'")->fetchColumn();
    wkAssertSame(1, (int) $reverseTx, 'reversal ledger entry recorded');
};

$tests['cancel_terminal_install_rejected'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    $f['service']->cancel(makeWkUser(7), (int) $planned['install']['id'], 'first');
    wkAssertThrows(
        fn () => $f['service']->cancel(makeWkUser(7), (int) $planned['install']['id'], 'second'),
        InvalidArgumentException::class,
        'cannot re-cancel terminal install'
    );
};

// ──── Service.delete ────

$tests['delete_only_allowed_on_planned'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    wkAssertSame(true, $f['service']->delete(makeWkUser(7), (int) $planned['install']['id']));
    wkAssertSame(null, $f['repo']->find((int) $planned['install']['id']));

    // Now an installed kit — delete should be rejected.
    $second = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    $f['service']->install(makeWkUser(7), (int) $second['install']['id']);
    wkAssertThrows(
        fn () => $f['service']->delete(makeWkUser(7), (int) $second['install']['id']),
        InvalidArgumentException::class
    );
};

// ──── Permission gates ────

$tests['get_requires_view_perm'] = function () {
    $f = makeWkFixture();
    $f['gate']->denials['workorder_kits.view'] = true;
    wkAssertThrows(
        fn () => $f['service']->get(makeWkUser(7), 1),
        UnauthorizedException::class
    );
};

$tests['plan_requires_install_perm'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10],
    ]);
    $f['gate']->denials['workorder_kits.install'] = true;
    wkAssertThrows(
        fn () => $f['service']->plan(makeWkUser(7), [
            'workorder_id' => $seed['workorder_id'],
            'bundle_id' => $bundleId,
        ]),
        UnauthorizedException::class
    );
};

$tests['cancel_requires_cancel_perm'] = function () {
    $f = makeWkFixture();
    $f['gate']->denials['workorder_kits.cancel'] = true;
    wkAssertThrows(
        fn () => $f['service']->cancel(makeWkUser(7), 999, 'reason'),
        UnauthorizedException::class
    );
};

$tests['delete_requires_manage_perm'] = function () {
    $f = makeWkFixture();
    $f['gate']->denials['workorder_kits.manage'] = true;
    wkAssertThrows(
        fn () => $f['service']->delete(makeWkUser(7), 999),
        UnauthorizedException::class
    );
};

// ──── Controller envelope ────

$tests['controller_plan_returns_data_envelope'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10],
    ]);
    $resp = $f['controller']->plan(makeWkUser(7), $seed['workorder_id'], ['bundle_id' => $bundleId]);
    wkAssertTrue(array_key_exists('data', $resp));
    wkAssertSame('planned', $resp['data']['install']['status']);
};

$tests['controller_listForWorkorder_returns_data_envelope'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10],
    ]);
    $f['service']->plan(makeWkUser(7), ['workorder_id' => $seed['workorder_id'], 'bundle_id' => $bundleId]);
    $f['service']->plan(makeWkUser(7), ['workorder_id' => $seed['workorder_id'], 'bundle_id' => $bundleId]);
    $resp = $f['controller']->listForWorkorder(makeWkUser(7), $seed['workorder_id']);
    wkAssertTrue(array_key_exists('data', $resp));
    wkAssertSame(2, count($resp['data']));
};

$tests['controller_install_returns_installed_envelope'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), [
        'workorder_id' => $seed['workorder_id'],
        'workorder_job_id' => $seed['workorder_job_id'],
        'bundle_id' => $bundleId,
    ]);
    $resp = $f['controller']->install(makeWkUser(7), (int) $planned['install']['id']);
    wkAssertSame('installed', $resp['data']['install']['status']);
};

$tests['controller_delete_returns_deleted_marker'] = function () {
    $f = makeWkFixture();
    $seed = wkSeedWorkorderWithJob($f['pdo']);
    $bundleId = wkSeedBundle($f['bundleService'], [
        ['type' => 'LABOR', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10],
    ]);
    $planned = $f['service']->plan(makeWkUser(7), ['workorder_id' => $seed['workorder_id'], 'bundle_id' => $bundleId]);
    $resp = $f['controller']->delete(makeWkUser(7), (int) $planned['install']['id']);
    wkAssertSame(true, $resp['data']['deleted']);
};

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

echo "WorkorderKitInstallServiceTest\n";
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
