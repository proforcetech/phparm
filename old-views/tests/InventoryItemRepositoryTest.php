<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Inventory\InventoryItemRepository;

class InMemoryConnection extends Connection
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

function setUpInventoryDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE inventory_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(160) NOT NULL,
        sku VARCHAR(120) NULL,
        category VARCHAR(120) NULL,
        stock_quantity INT DEFAULT 0,
        low_stock_threshold INT DEFAULT 0,
        cost DECIMAL(12,2) DEFAULT 0,
        sale_price DECIMAL(12,2) DEFAULT 0,
        markup DECIMAL(6,2) NULL,
        location VARCHAR(160) NULL,
        notes TEXT NULL
    )');

    return $pdo;
}

$pdo = setUpInventoryDatabase();
$repository = new InventoryItemRepository(new InMemoryConnection($pdo));

$oil = $repository->create([
    'name' => '5W-30 Oil',
    'sku' => 'OIL-001',
    'category' => 'Fluids',
    'stock_quantity' => 2,
    'low_stock_threshold' => 3,
    'cost' => 12.5,
    'sale_price' => 18.0,
    'location' => 'Aisle 1',
]);

$filterResults = $repository->list(['category' => 'Fluids']);
$lowStock = $repository->lowStockAlerts();

$updated = $repository->update($oil->id, ['stock_quantity' => 6]);
$deleted = $repository->delete($oil->id);

$scenarios = [
    ['scenario' => 'filter by category', 'passed' => count($filterResults) === 1],
    ['scenario' => 'low stock alert generated', 'passed' => count($lowStock) === 1 && $lowStock[0]['severity'] === 'low'],
    ['scenario' => 'update applies new quantity', 'passed' => $updated !== null && $updated->stock_quantity === 6],
    ['scenario' => 'delete removes record', 'passed' => $deleted === true],
];

$failures = array_filter($scenarios, static fn (array $row) => $row['passed'] === false);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All inventory repository tests passed." . PHP_EOL;
