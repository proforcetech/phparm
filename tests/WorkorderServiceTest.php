<?php

declare(strict_types=1);

namespace App\Services\Inventory {
    use App\Models\InventoryPullRequest;
    use App\Models\InventoryStockOrder;

    class InventoryPullRequestRepository
    {
        /** @var array<int, array<string, mixed>> */
        public array $created = [];
        private int $nextId = 1;

        /** @param array<string, mixed> $data */
        public function create(array $data, ?int $actorId = null): InventoryPullRequest
        {
            $this->created[] = $data;

            return new InventoryPullRequest(array_merge($data, ['id' => $this->nextId++]));
        }
    }

    class InventoryStockOrderRepository
    {
        /** @var array<int, array<string, mixed>> */
        public array $created = [];
        private int $nextId = 1;

        /** @param array<string, mixed> $data */
        public function create(array $data, ?int $actorId = null): InventoryStockOrder
        {
            $this->created[] = $data;

            return new InventoryStockOrder(array_merge($data, ['id' => $this->nextId++]));
        }
    }
}

namespace {
    require __DIR__ . '/test_bootstrap.php';
    use App\Database\Connection;
    use App\Services\Inventory\CoreReturnService;
    use App\Services\Inventory\InventoryPullRequestRepository;
    use App\Services\Inventory\InventoryPullRequestService;
    use App\Services\Inventory\InventoryStockOrderRepository;
    use App\Services\Inventory\InventoryStockOrderService;
    use App\Services\Workorder\WorkorderRepository;
    use App\Services\Workorder\WorkorderService;
    use App\Support\Audit\AuditLogger;
    use PDO;
    use ReflectionMethod;

    class WorkorderMemoryConnection extends Connection
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

    function setupWorkorderDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE workorder_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            workorder_id INTEGER NOT NULL
        )');

        $pdo->exec('CREATE TABLE workorder_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            workorder_job_id INTEGER NOT NULL,
            inventory_item_id INTEGER NULL,
            sku TEXT NULL,
            description TEXT NOT NULL,
            quantity REAL NOT NULL,
            unit_price REAL NOT NULL,
            type TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE inventory_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stock_quantity INTEGER NOT NULL,
            cost REAL NULL,
            sale_price REAL NULL,
            vendor TEXT NULL
        )');

        return $pdo;
    }

    $pdo = setupWorkorderDatabase();
    $connection = new WorkorderMemoryConnection($pdo);

    $pdo->prepare('INSERT INTO workorder_jobs (workorder_id) VALUES (:workorder_id)')
        ->execute(['workorder_id' => 101]);
    $workorderJobId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO inventory_items (stock_quantity, cost, sale_price, vendor) VALUES (:stock, :cost, :sale_price, :vendor)')
        ->execute([
            'stock' => 5,
            'cost' => 3.0,
            'sale_price' => 8.0,
            'vendor' => 'Acme',
        ]);
    $inStockItemId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO inventory_items (stock_quantity, cost, sale_price, vendor) VALUES (:stock, :cost, :sale_price, :vendor)')
        ->execute([
            'stock' => 0,
            'cost' => 5.0,
            'sale_price' => 12.0,
            'vendor' => 'BoltCo',
        ]);
    $outOfStockItemId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO workorder_items (workorder_job_id, inventory_item_id, sku, description, quantity, unit_price, type)
        VALUES (:workorder_job_id, :inventory_item_id, :sku, :description, :quantity, :unit_price, :type)')
        ->execute([
            'workorder_job_id' => $workorderJobId,
            'inventory_item_id' => $inStockItemId,
            'sku' => 'BRK-001',
            'description' => 'Brake Pad',
            'quantity' => 2,
            'unit_price' => 9.0,
            'type' => 'PART',
        ]);

    $pdo->prepare('INSERT INTO workorder_items (workorder_job_id, inventory_item_id, sku, description, quantity, unit_price, type)
        VALUES (:workorder_job_id, :inventory_item_id, :sku, :description, :quantity, :unit_price, :type)')
        ->execute([
            'workorder_job_id' => $workorderJobId,
            'inventory_item_id' => $outOfStockItemId,
            'sku' => 'RTR-002',
            'description' => 'Rotor',
            'quantity' => 1,
            'unit_price' => 20.0,
            'type' => 'PART',
        ]);

    $pullRepo = new InventoryPullRequestRepository();
    $stockRepo = new InventoryStockOrderRepository();

    $pullService = new InventoryPullRequestService($pullRepo);
    $stockService = new InventoryStockOrderService($stockRepo);

    $audit = new AuditLogger($connection, ['enabled' => false]);
    $coreReturns = new CoreReturnService($connection, $audit);

    $repository = new WorkorderRepository($connection);
    $service = new WorkorderService(
        $connection,
        $repository,
        $coreReturns,
        $pullService,
        $stockService,
        null
    );

    $method = new ReflectionMethod(WorkorderService::class, 'createInventoryRequestsForWorkorderParts');
    $method->setAccessible(true);
    $method->invoke($service, 101, 5, 9);

    $pullRequest = $pullRepo->created[0] ?? [];
    $stockOrder = $stockRepo->created[0] ?? [];

    $scenarios = [
        [
            'scenario' => 'pull request created for in-stock part',
            'passed' => count($pullRepo->created) === 1 && ($pullRequest['inventory_item_id'] ?? null) === $inStockItemId,
        ],
        [
            'scenario' => 'pull request quantity uses ceil',
            'passed' => ($pullRequest['quantity_requested'] ?? null) === 2,
        ],
        [
            'scenario' => 'stock order created for out-of-stock part',
            'passed' => count($stockRepo->created) === 1 && ($stockOrder['inventory_item_id'] ?? null) === $outOfStockItemId,
        ],
        [
            'scenario' => 'stock order quantity matches request',
            'passed' => ($stockOrder['quantity_ordered'] ?? null) === 1,
        ],
    ];

    $failures = array_filter($scenarios, static fn (array $row) => $row['passed'] === false);
    if ($failures) {
        foreach ($failures as $failure) {
            fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
        }
        exit(1);
    }

    echo "All Workorder service tests passed." . PHP_EOL;
}
