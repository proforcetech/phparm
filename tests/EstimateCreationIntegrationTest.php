<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Estimate\BundleService;
use App\Services\Estimate\EstimateEditorService;
use App\Services\Inventory\InventoryVehicleCompatibilityRepository;

class EstimateMemoryConnection extends Connection
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

function setupEstimateDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE bundles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT NULL,
        internal_notes TEXT NULL,
        discount_type TEXT NULL,
        discount_value REAL NULL,
        service_type_id INTEGER NULL,
        default_job_title TEXT NOT NULL,
        is_active INTEGER NOT NULL,
        sort_order INTEGER NOT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE bundle_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bundle_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        description TEXT NOT NULL,
        quantity REAL NOT NULL,
        unit_price REAL NOT NULL,
        taxable INTEGER NOT NULL,
        discount_type TEXT NULL,
        sort_order INTEGER NOT NULL
    )');

    $pdo->exec('CREATE TABLE estimates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER NULL,
        number TEXT NOT NULL,
        customer_id INTEGER NOT NULL,
        vehicle_id INTEGER NOT NULL,
        is_mobile INTEGER NOT NULL,
        technician_id INTEGER NULL,
        expiration_date TEXT NOT NULL,
        status TEXT NOT NULL,
        internal_notes TEXT NULL,
        customer_notes TEXT NULL,
        call_out_fee REAL NOT NULL,
        mileage_total REAL NOT NULL,
        discounts REAL NOT NULL,
        shop_fee REAL NOT NULL,
        hazmat_disposal_fee REAL NOT NULL,
        subtotal REAL NOT NULL,
        tax REAL NOT NULL,
        grand_total REAL NOT NULL,
        rejection_reason TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE estimate_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estimate_id INTEGER NOT NULL,
        service_type_id INTEGER NULL,
        title TEXT NOT NULL,
        notes TEXT NULL,
        reference TEXT NULL,
        customer_status TEXT NOT NULL,
        subtotal REAL NOT NULL,
        tax REAL NOT NULL,
        total REAL NOT NULL,
        display_order INTEGER NOT NULL
    )');

    $pdo->exec('CREATE TABLE estimate_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estimate_job_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        sku TEXT NULL,
        description TEXT NOT NULL,
        quantity REAL NOT NULL,
        unit_price REAL NOT NULL,
        list_price REAL NOT NULL,
        taxable INTEGER NOT NULL,
        discount_type TEXT NOT NULL,
        line_total REAL NOT NULL,
        status TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE customer_vehicles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vehicle_master_id INTEGER NULL
    )');

    $pdo->exec('CREATE TABLE inventory_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT NULL,
        name TEXT NULL,
        description TEXT NULL,
        sale_price REAL NULL,
        list_price REAL NULL,
        cost REAL NULL
    )');

    $pdo->exec('CREATE TABLE inventory_vehicle_compatibility (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        inventory_item_id INTEGER NOT NULL,
        vehicle_master_id INTEGER NOT NULL
    )');

    return $pdo;
}

$pdo = setupEstimateDatabase();
$connection = new EstimateMemoryConnection($pdo);

$pdo->prepare('INSERT INTO customer_vehicles (vehicle_master_id) VALUES (:vehicle_master_id)')
    ->execute(['vehicle_master_id' => 5]);
$vehicleId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO estimates (number, customer_id, vehicle_id, is_mobile, expiration_date, status, call_out_fee, mileage_total, discounts, shop_fee, hazmat_disposal_fee, subtotal, tax, grand_total)
    VALUES (:number, :customer_id, :vehicle_id, :is_mobile, :expiration_date, :status, :call_out_fee, :mileage_total, :discounts, :shop_fee, :hazmat_disposal_fee, 0, 0, 0)')
    ->execute([
        'number' => 'EST-CTX',
        'customer_id' => 10,
        'vehicle_id' => $vehicleId,
        'is_mobile' => 0,
        'expiration_date' => date('Y-m-d', strtotime('+14 days')),
        'status' => 'pending',
        'call_out_fee' => 0,
        'mileage_total' => 0,
        'discounts' => 0,
        'shop_fee' => 0,
        'hazmat_disposal_fee' => 0,
    ]);
$contextEstimateId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO bundles (name, default_job_title, is_active, sort_order) VALUES (:name, :title, :active, :sort)')
    ->execute([
        'name' => 'Starter Bundle',
        'title' => 'Starter Job',
        'active' => 1,
        'sort' => 0,
    ]);
$bundleId = (int) $pdo->lastInsertId();

$bundleItemStmt = $pdo->prepare('INSERT INTO bundle_items (bundle_id, type, description, quantity, unit_price, taxable, discount_type, sort_order)
    VALUES (:bundle_id, :type, :description, :quantity, :unit_price, :taxable, :discount_type, :sort_order)');
$bundleItemStmt->execute([
    'bundle_id' => $bundleId,
    'type' => 'PART',
    'description' => 'Oil Filter',
    'quantity' => 1,
    'unit_price' => 10.0,
    'taxable' => 1,
    'discount_type' => 'fixed',
    'sort_order' => 0,
]);
$bundleItemStmt->execute([
    'bundle_id' => $bundleId,
    'type' => 'PART',
    'description' => 'Specialty Belt',
    'quantity' => 1,
    'unit_price' => 22.0,
    'taxable' => 1,
    'discount_type' => 'fixed',
    'sort_order' => 1,
]);

$pdo->prepare('INSERT INTO inventory_items (sku, name, description, sale_price, list_price, cost)
    VALUES (:sku, :name, :description, :sale_price, :list_price, :cost)')
    ->execute([
        'sku' => 'OF-1',
        'name' => 'OEM Oil Filter',
        'description' => 'OEM Oil Filter',
        'sale_price' => 12.0,
        'list_price' => 15.0,
        'cost' => 6.0,
    ]);
$inventoryItemId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO inventory_vehicle_compatibility (inventory_item_id, vehicle_master_id)
    VALUES (:inventory_item_id, :vehicle_master_id)')
    ->execute([
        'inventory_item_id' => $inventoryItemId,
        'vehicle_master_id' => 5,
    ]);

$compatibility = new InventoryVehicleCompatibilityRepository($connection);
$bundleService = new BundleService($connection, $compatibility);

$items = $bundleService->applyToEstimate($bundleId, $contextEstimateId);
$autoItem = $items[0] ?? [];
$manualItem = $items[1] ?? [];

$editor = new EstimateEditorService($connection, null);
$estimate = $editor->create([
    'number' => 'EST-NEW',
    'customer_id' => 10,
    'vehicle_id' => $vehicleId,
    'jobs' => [
        [
            'title' => 'Bundle Job',
            'items' => $items,
        ],
    ],
], 42);

$stmt = $pdo->prepare('SELECT ei.sku, ei.description FROM estimate_items ei JOIN estimate_jobs ej ON ej.id = ei.estimate_job_id WHERE ej.estimate_id = :estimate_id ORDER BY ei.id ASC');
$stmt->execute(['estimate_id' => $estimate->id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$scenarios = [
    [
        'scenario' => 'auto-selected item uses inventory sku',
        'passed' => ($autoItem['sku'] ?? null) === 'OF-1' && ($rows[0]['sku'] ?? null) === 'OF-1',
    ],
    [
        'scenario' => 'manual item flagged for completion',
        'passed' => ($manualItem['manual_completion_required'] ?? false) === true,
    ],
    [
        'scenario' => 'manual item stored without sku',
        'passed' => ($rows[1]['sku'] ?? null) === null,
    ],
    [
        'scenario' => 'auto-selected item description stored',
        'passed' => ($rows[0]['description'] ?? null) === 'OEM Oil Filter',
    ],
];

$failures = array_filter($scenarios, static fn (array $row) => $row['passed'] === false);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All estimate creation integration tests passed." . PHP_EOL;
