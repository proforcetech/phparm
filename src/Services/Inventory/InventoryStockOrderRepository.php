<?php

namespace App\Services\Inventory;

use App\Database\Connection;
use App\Models\InventoryStockOrder;
use InvalidArgumentException;
use PDO;

class InventoryStockOrderRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function find(int $id): ?InventoryStockOrder
    {
        $sql = "SELECT so.*,
                ii.name as inventory_item_name,
                u.name as created_by_name
            FROM inventory_stock_orders so
            LEFT JOIN inventory_items ii ON so.inventory_item_id = ii.id
            LEFT JOIN users u ON so.created_by = u.id
            WHERE so.id = :id";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: InventoryStockOrder[], total: int}
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = [];
        $bindings = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'so.status = :status';
            $bindings['status'] = $filters['status'];
        }

        if (!empty($filters['inventory_item_id'])) {
            $clauses[] = 'so.inventory_item_id = :inventory_item_id';
            $bindings['inventory_item_id'] = (int) $filters['inventory_item_id'];
        }

        if (!empty($filters['query'])) {
            $clauses[] = '(so.description LIKE :query OR so.sku LIKE :query)';
            $bindings['query'] = '%' . $filters['query'] . '%';
        }

        $whereClause = count($clauses) > 0 ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $countSql = "SELECT COUNT(*) FROM inventory_stock_orders so $whereClause";
        $stmt = $this->connection->pdo()->prepare($countSql);
        $stmt->execute($bindings);
        $total = (int) $stmt->fetchColumn();

        $sql = "SELECT so.*,
                ii.name as inventory_item_name,
                u.name as created_by_name
            FROM inventory_stock_orders so
            LEFT JOIN inventory_items ii ON so.inventory_item_id = ii.id
            LEFT JOIN users u ON so.created_by = u.id
            $whereClause
            ORDER BY so.created_at DESC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = $this->mapRow($row);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, ?int $actorId = null): InventoryStockOrder
    {
        $this->validateData($data);

        $sql = "INSERT INTO inventory_stock_orders (
                inventory_item_id, sku, description, quantity_ordered, status,
                expected_arrival_date, notes, vendor, order_reference, created_by
            ) VALUES (
                :inventory_item_id, :sku, :description, :quantity_ordered, :status,
                :expected_arrival_date, :notes, :vendor, :order_reference, :created_by
            )";

        $this->connection->pdo()->prepare($sql)->execute([
            'inventory_item_id' => isset($data['inventory_item_id']) ? (int) $data['inventory_item_id'] : null,
            'sku' => $data['sku'] ?? null,
            'description' => $data['description'],
            'quantity_ordered' => (int) ($data['quantity_ordered'] ?? 1),
            'status' => $data['status'] ?? InventoryStockOrder::STATUS_ON_ORDER,
            'expected_arrival_date' => $data['expected_arrival_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'vendor' => $data['vendor'] ?? null,
            'order_reference' => $data['order_reference'] ?? null,
            'created_by' => $actorId,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?InventoryStockOrder
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        if (isset($data['status']) && !in_array($data['status'], InventoryStockOrder::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid stock order status');
        }

        $sql = "UPDATE inventory_stock_orders SET
                inventory_item_id = :inventory_item_id,
                sku = :sku,
                description = :description,
                quantity_ordered = :quantity_ordered,
                status = :status,
                expected_arrival_date = :expected_arrival_date,
                notes = :notes,
                vendor = :vendor,
                order_reference = :order_reference,
                updated_at = NOW()
            WHERE id = :id";

        $this->connection->pdo()->prepare($sql)->execute([
            'id' => $id,
            'inventory_item_id' => isset($data['inventory_item_id'])
                ? (int) $data['inventory_item_id']
                : $existing->inventory_item_id,
            'sku' => $data['sku'] ?? $existing->sku,
            'description' => $data['description'] ?? $existing->description,
            'quantity_ordered' => (int) ($data['quantity_ordered'] ?? $existing->quantity_ordered),
            'status' => $data['status'] ?? $existing->status,
            'expected_arrival_date' => $data['expected_arrival_date'] ?? $existing->expected_arrival_date,
            'notes' => $data['notes'] ?? $existing->notes,
            'vendor' => $data['vendor'] ?? $existing->vendor,
            'order_reference' => $data['order_reference'] ?? $existing->order_reference,
        ]);

        return $this->find($id);
    }

    /**
     * @param array<int, int> $itemIds
     * @return array<int, array<string, mixed>>
     */
    public function getOpenOrdersByItemIds(array $itemIds): array
    {
        $itemIds = array_values(array_filter(array_unique(array_map('intval', $itemIds))));
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($itemIds), '?'));
        $sql = "SELECT so.*
            FROM inventory_stock_orders so
            WHERE so.inventory_item_id IN ($placeholders)
            AND so.status IN (?, ?)
            ORDER BY so.expected_arrival_date IS NULL, so.expected_arrival_date ASC, so.created_at DESC";

        $stmt = $this->connection->pdo()->prepare($sql);
        $params = array_merge($itemIds, [InventoryStockOrder::STATUS_BACKORDER, InventoryStockOrder::STATUS_ON_ORDER]);
        $stmt->execute($params);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $itemId = (int) ($row['inventory_item_id'] ?? 0);
            if ($itemId === 0 || isset($results[$itemId])) {
                continue;
            }
            $results[$itemId] = $row;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateData(array $data): void
    {
        if (empty($data['description'])) {
            throw new InvalidArgumentException('Description is required');
        }

        if (!empty($data['status']) && !in_array($data['status'], InventoryStockOrder::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid stock order status');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): InventoryStockOrder
    {
        $row['inventory_item_id'] = isset($row['inventory_item_id']) ? (int) $row['inventory_item_id'] : null;
        $row['quantity_ordered'] = (int) ($row['quantity_ordered'] ?? 0);

        return new InventoryStockOrder($row);
    }
}
