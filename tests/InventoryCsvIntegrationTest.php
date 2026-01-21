<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Models\InventoryItem;
use App\Services\Inventory\InventoryCsvService;
use App\Services\Inventory\InventoryItemRepository;

class FakeInventoryItemRepository extends InventoryItemRepository
{
    /** @var array<int, InventoryItem> */
    private array $items = [];
    private int $nextId = 1;

    public function __construct()
    {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): InventoryItem
    {
        $item = new InventoryItem(array_merge($data, ['id' => $this->nextId++]));
        $this->items[$item->id] = $item;

        return $item;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): ?InventoryItem
    {
        if (!isset($this->items[$id])) {
            return null;
        }

        $item = new InventoryItem(array_merge($this->items[$id]->toArray(), $data, ['id' => $id]));
        $this->items[$id] = $item;

        return $item;
    }

    /** @param array<string, mixed> $payload */
    public function findDuplicate(array $payload): ?InventoryItem
    {
        foreach ($this->items as $item) {
            if (!empty($payload['sku']) && $item->sku === $payload['sku']) {
                return $item;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->items);
    }
}

$repository = new FakeInventoryItemRepository();
$service = new InventoryCsvService($repository);

$headers = [
    'name',
    'description',
    'sku',
    'category',
    'stock_quantity',
    'low_stock_threshold',
    'reorder_quantity',
    'cost',
    'core_cost',
    'core_price',
    'core_eligible',
    'sale_price',
    'markup',
    'location',
    'bin_location',
    'vendor',
    'notes',
];

$rows = [];
for ($i = 1; $i <= 120; $i++) {
    $rows[] = [
        "Item {$i}",
        "Description {$i}",
        "SKU{$i}",
        'Filters',
        '10',
        '2',
        '5',
        '5.00',
        '',
        '',
        '0',
        '7.50',
        '',
        'Shelf A',
        'Bin 1',
        'VendorCo',
        'Notes',
    ];
}

$csv = implode(',', $headers) . "\n";
foreach ($rows as $row) {
    $csv .= implode(',', $row) . "\n";
}

$summary = $service->import($csv, false);

$scenarios = [
    [
        'scenario' => 'all rows created in bulk import',
        'passed' => $summary['created'] === 120,
    ],
    [
        'scenario' => 'no failures in bulk import',
        'passed' => $summary['failed'] === 0,
    ],
    [
        'scenario' => 'repository contains all items',
        'passed' => $repository->count() === 120,
    ],
];

$failures = array_filter($scenarios, static fn (array $row) => $row['passed'] === false);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All inventory CSV integration tests passed." . PHP_EOL;
