<?php

namespace App\Services\Inventory;

use App\Database\Connection;
use App\Models\InventoryItem;
use PDO;

class InventoryItemRepository
{
    private Connection $connection;
    private InventoryItemValidator $validator;

    /**
     * @var array<int, InventoryItem>
     */
    private array $cache = [];

    /**
     * @var array<string, array<int, InventoryItem>>
     */
    private array $listCache = [];

    public function __construct(Connection $connection, ?InventoryItemValidator $validator = null)
    {
        $this->connection = $connection;
        $this->validator = $validator ?? new InventoryItemValidator();
    }

    public function find(int $id): ?InventoryItem
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inventory_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $item = $this->mapRow($row);
        $this->cache[$id] = $item;

        return $item;
    }

    public function findBySku(string $sku): ?InventoryItem
    {
        foreach ($this->cache as $item) {
            if ($item->sku === $sku) {
                return $item;
            }
        }

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inventory_items WHERE sku = :sku LIMIT 1');
        $stmt->execute(['sku' => $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $item = $this->mapRow($row);
        $this->cache[$item->id] = $item;

        return $item;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function findDuplicate(array $payload): ?InventoryItem
    {
        if (!empty($payload['sku'])) {
            $existing = $this->findBySku((string) $payload['sku']);
            if ($existing !== null) {
                return $existing;
            }
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM inventory_items WHERE name = :name AND (category = :category OR (:category IS NULL AND category IS NULL)) LIMIT 1'
        );

        $stmt->execute([
            'name' => $payload['name'],
            'category' => $payload['category'] ?? null,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $item = $this->mapRow($row);
        $this->cache[$item->id] = $item;

        return $item;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): InventoryItem
    {
        $payload = $this->validator->validate($data);

        $sql = 'INSERT INTO inventory_items (name, sku, category, stock_quantity, low_stock_threshold, cost, sale_price, markup, '
            . 'location, notes) VALUES (:name, :sku, :category, :stock_quantity, :low_stock_threshold, :cost, :sale_price, '
            . ':markup, :location, :notes)';

        $this->connection->pdo()->prepare($sql)->execute([
            'name' => $payload['name'],
            'sku' => $payload['sku'],
            'category' => $payload['category'],
            'stock_quantity' => $payload['stock_quantity'],
            'low_stock_threshold' => $payload['low_stock_threshold'],
            'cost' => $payload['cost'],
            'sale_price' => $payload['sale_price'],
            'markup' => $payload['markup'],
            'location' => $payload['location'],
            'notes' => $payload['notes'],
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $item = new InventoryItem(array_merge($payload, ['id' => $id]));
        $this->cache[$id] = $item;
        $this->listCache = [];

        return $item;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?InventoryItem
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $payload = $this->validator->validate(array_merge($existing->toArray(), $data));

        $sql = 'UPDATE inventory_items SET name = :name, sku = :sku, category = :category, stock_quantity = :stock_quantity, '
            . 'low_stock_threshold = :low_stock_threshold, cost = :cost, sale_price = :sale_price, markup = :markup, '
            . 'location = :location, notes = :notes WHERE id = :id';

        $this->connection->pdo()->prepare($sql)->execute([
            'name' => $payload['name'],
            'sku' => $payload['sku'],
            'category' => $payload['category'],
            'stock_quantity' => $payload['stock_quantity'],
            'low_stock_threshold' => $payload['low_stock_threshold'],
            'cost' => $payload['cost'],
            'sale_price' => $payload['sale_price'],
            'markup' => $payload['markup'],
            'location' => $payload['location'],
            'notes' => $payload['notes'],
            'id' => $id,
        ]);

        $item = new InventoryItem(array_merge($payload, ['id' => $id]));
        $this->cache[$id] = $item;
        $this->listCache = [];

        return $item;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM inventory_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        unset($this->cache[$id]);
        $this->listCache = [];

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, InventoryItem>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $cacheKey = md5(json_encode([$filters, $limit, $offset]));
        if (isset($this->listCache[$cacheKey])) {
            return $this->listCache[$cacheKey];
        }

        [$clauses, $bindings] = $this->buildFilterClauses($filters);
        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $sql = 'SELECT * FROM inventory_items ' . $where . ' ORDER BY name ASC LIMIT :limit OFFSET :offset';
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = $this->mapRow($row);
            $results[] = $item;
            $this->cache[$item->id] = $item;
        }

        $this->listCache[$cacheKey] = $results;

        return $results;
    }

    /**
     * @return array<int, InventoryItem>
     */
    public function lowStock(int $limit = 25, int $offset = 0): array
    {
        $filters = ['low_stock_only' => true];

        return $this->list($filters, $limit, $offset);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lowStockAlerts(int $limit = 25, int $offset = 0): array
    {
        $items = $this->lowStock($limit, $offset);

        return array_map(static function (InventoryItem $item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'stock_quantity' => $item->stock_quantity,
                'low_stock_threshold' => $item->low_stock_threshold,
                'severity' => $item->stock_quantity === 0 ? 'out' : 'low',
            ];
        }, $items);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: array<int, string>, 1: array<string, mixed>}
     */
    private function buildFilterClauses(array $filters): array
    {
        $clauses = [];
        $bindings = [];

        if (isset($filters['category']) && $filters['category'] !== '') {
            $clauses[] = 'category = :category';
            $bindings['category'] = $filters['category'];
        }

        if (isset($filters['location']) && $filters['location'] !== '') {
            $clauses[] = 'location = :location';
            $bindings['location'] = $filters['location'];
        }

        if (isset($filters['query']) && $filters['query'] !== '') {
            $clauses[] = '(name LIKE :query OR sku LIKE :query)';
            $bindings['query'] = $filters['query'] . '%';
        }

        if (!empty($filters['low_stock_only'])) {
            $clauses[] = '(stock_quantity <= low_stock_threshold)';
        }

        return [$clauses, $bindings];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): InventoryItem
    {
        $row['stock_quantity'] = (int) $row['stock_quantity'];
        $row['low_stock_threshold'] = (int) $row['low_stock_threshold'];
        $row['cost'] = (float) $row['cost'];
        $row['sale_price'] = (float) $row['sale_price'];
        $row['markup'] = $row['markup'] === null ? null : (float) $row['markup'];

        return new InventoryItem($row);
    }
}
