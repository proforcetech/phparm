<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Estimate\BundleService;
use App\Services\Inventory\InventoryVehicleCompatibilityRepository;

class BundleMemoryConnection extends Connection
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

function setupBundleDatabase(): PDO
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
        vehicle_id INTEGER NULL
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

$pdo = setupBundleDatabase();
$connection = new BundleMemoryConnection($pdo);
$compatibility = new InventoryVehicleCompatibilityRepository($connection);
$service = new BundleService($connection, $compatibility);

$pdo->prepare('INSERT INTO bundles (name, default_job_title, is_active, sort_order) VALUES (:name, :title, :active, :sort)')
    ->execute([
        'name' => 'Filter Bundle',
        'title' => 'Oil Service',
        'active' => 1,
        'sort' => 0,
    ]);
$bundleId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO bundle_items (bundle_id, type, description, quantity, unit_price, taxable, discount_type, sort_order)
    VALUES (:bundle_id, :type, :description, :quantity, :unit_price, :taxable, :discount_type, :sort_order)')
    ->execute([
        'bundle_id' => $bundleId,
        'type' => 'PART',
        'description' => 'Generic Oil Filter',
        'quantity' => 1,
        'unit_price' => 8.0,
        'taxable' => 1,
        'discount_type' => 'fixed',
        'sort_order' => 0,
    ]);

$pdo->prepare('INSERT INTO customer_vehicles (vehicle_master_id) VALUES (:vehicle_master_id)')
    ->execute(['vehicle_master_id' => 7]);
$vehicleId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO estimates (vehicle_id) VALUES (:vehicle_id)')
    ->execute(['vehicle_id' => $vehicleId]);
$estimateId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO inventory_items (sku, name, description, sale_price, list_price, cost)
    VALUES (:sku, :name, :description, :sale_price, :list_price, :cost)')
    ->execute([
        'sku' => 'OF-123',
        'name' => 'OEM Oil Filter',
        'description' => 'OEM Oil Filter',
        'sale_price' => 15.0,
        'list_price' => 18.0,
        'cost' => 7.5,
    ]);
$inventoryItemId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO inventory_vehicle_compatibility (inventory_item_id, vehicle_master_id)
    VALUES (:inventory_item_id, :vehicle_master_id)')
    ->execute([
        'inventory_item_id' => $inventoryItemId,
        'vehicle_master_id' => 7,
    ]);

$items = $service->applyToEstimate($bundleId, $estimateId);
$part = $items[0] ?? [];

$scenarios = [
    [
        'scenario' => 'matched part uses inventory description',
        'passed' => ($part['description'] ?? null) === 'OEM Oil Filter',
    ],
    [
        'scenario' => 'matched part uses inventory sku',
        'passed' => ($part['sku'] ?? null) === 'OF-123',
    ],
    [
        'scenario' => 'matched part sets inventory item id',
        'passed' => ($part['inventory_item_id'] ?? null) === $inventoryItemId,
    ],
    [
        'scenario' => 'matched part uses inventory price',
        'passed' => abs(($part['unit_price'] ?? 0) - 15.0) < 0.0001,
    ],
    [
        'scenario' => 'matched part does not require manual completion',
        'passed' => ($part['manual_completion_required'] ?? true) === false,
    ],
];

$failures = array_filter($scenarios, static fn (array $row) => $row['passed'] === false);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All Bundle service tests passed." . PHP_EOL;
