<?php

namespace App\Services\Inventory;

use App\Database\Connection;
use App\Models\InventoryItem;
use App\Models\InventoryVehicleCompatibility;
use PDO;

class InventoryItemRepository
{
    private Connection $connection;
    private InventoryItemValidator $validator;
    private ?bool $hasIsTrackedColumn = null;
    /**
     * @var array<int, string>|null
     */
    private ?array $inventoryColumns = null;

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

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Check if the is_tracked column exists (for backwards compatibility)
     */
    private function hasIsTrackedColumn(): bool
    {
        if ($this->hasIsTrackedColumn === null) {
            $this->hasIsTrackedColumn = $this->columnExists('is_tracked');
        }

        return $this->hasIsTrackedColumn;
    }

    /**
     * @return array<int, string>
     */
    private function getInventoryColumns(): array
    {
        if ($this->inventoryColumns !== null) {
            return $this->inventoryColumns;
        }

        try {
            $stmt = $this->connection->pdo()->query('SHOW COLUMNS FROM inventory_items');
            $columns = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns[] = $row['Field'];
            }
            $this->inventoryColumns = $columns;
        } catch (\Exception $e) {
            $this->inventoryColumns = [];
        }

        return $this->inventoryColumns;
    }

    private function columnExists(string $column): bool
    {
        return in_array($column, $this->getInventoryColumns(), true);
    }

    private function resolveColumn(string $primary, ?string $fallback = null): ?string
    {
        if ($this->columnExists($primary)) {
            return $primary;
        }

        if ($fallback !== null && $this->columnExists($fallback)) {
            return $fallback;
        }

        return null;
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
     * Find inventory item by barcode or UPC
     *
     * @param string $code Barcode or UPC value
     * @return InventoryItem|null
     */
    public function findByBarcode(string $code): ?InventoryItem
    {
        // Check cache first
        foreach ($this->cache as $item) {
            if (isset($item->barcode) && $item->barcode === $code) {
                return $item;
            }
            if (isset($item->upc) && $item->upc === $code) {
                return $item;
            }
        }

        // Search by barcode, UPC, or SKU (fallback)
        $sql = 'SELECT * FROM inventory_items
                WHERE barcode = :code OR upc = :code2 OR sku = :code3
                LIMIT 1';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['code' => $code, 'code2' => $code, 'code3' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $item = $this->mapRow($row);
        $this->cache[$item->id] = $item;

        return $item;
    }

    /**
     * Log a barcode scan event
     *
     * @param string $barcodeValue The scanned barcode
     * @param string $scanType Type of scan action
     * @param int|null $inventoryItemId Found item ID (if any)
     * @param int|null $userId User who scanned
     * @param bool $success Whether the scan was successful
     * @param string|null $errorMessage Error message if failed
     * @param int|null $workorderId Related workorder ID
     * @param int|null $invoiceId Related invoice ID
     */
    public function logBarcodeScan(
        string $barcodeValue,
        string $scanType,
        ?int $inventoryItemId,
        ?int $userId,
        bool $success = true,
        ?string $errorMessage = null,
        ?int $workorderId = null,
        ?int $invoiceId = null
    ): void {
        $sql = 'INSERT INTO barcode_scan_log
                (barcode_value, scan_type, inventory_item_id, workorder_id, invoice_id, user_id, success, error_message)
                VALUES
                (:barcode_value, :scan_type, :inventory_item_id, :workorder_id, :invoice_id, :user_id, :success, :error_message)';

        $this->connection->pdo()->prepare($sql)->execute([
            'barcode_value' => $barcodeValue,
            'scan_type' => $scanType,
            'inventory_item_id' => $inventoryItemId,
            'workorder_id' => $workorderId,
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'success' => $success ? 1 : 0,
            'error_message' => $errorMessage,
        ]);
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
        $columnMap = [
            'name' => 'name',
            'description' => 'description',
            'sku' => 'sku',
            'manufacturer_part_number' => $this->resolveColumn('manufacturer_part_number'),
            'category' => 'category',
            'stock_quantity' => $this->resolveColumn('stock_quantity', 'quantity'),
            'low_stock_threshold' => $this->resolveColumn('low_stock_threshold', 'reorder_threshold'),
            'reorder_quantity' => $this->resolveColumn('reorder_quantity'),
            'cost' => 'cost',
            'sale_price' => $this->resolveColumn('sale_price', 'price'),
            'list_price' => $this->resolveColumn('list_price'),
            'markup' => 'markup',
            'location' => 'location',
            'bin_location' => 'bin_location',
            'vendor' => 'vendor',
            'notes' => 'notes',
        ];

        $columns = [];
        $placeholders = [];
        $params = [];
        foreach ($columnMap as $payloadKey => $columnName) {
            if ($columnName === null || !$this->columnExists($columnName)) {
                continue;
            }

            $columns[] = $columnName;
            $placeholders[] = ':' . $columnName;
            $params[$columnName] = $payload[$payloadKey] ?? null;
        }

        if ($this->hasIsTrackedColumn()) {
            $columns[] = 'is_tracked';
            $placeholders[] = ':is_tracked';
            $params['is_tracked'] = isset($payload['is_tracked']) ? (int) (bool) $payload['is_tracked'] : 1;
        }

        $sql = 'INSERT INTO inventory_items (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $this->connection->pdo()->prepare($sql)->execute($params);

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
        $columnMap = [
            'name' => 'name',
            'description' => 'description',
            'sku' => 'sku',
            'manufacturer_part_number' => $this->resolveColumn('manufacturer_part_number'),
            'category' => 'category',
            'stock_quantity' => $this->resolveColumn('stock_quantity', 'quantity'),
            'low_stock_threshold' => $this->resolveColumn('low_stock_threshold', 'reorder_threshold'),
            'reorder_quantity' => $this->resolveColumn('reorder_quantity'),
            'cost' => 'cost',
            'sale_price' => $this->resolveColumn('sale_price', 'price'),
            'list_price' => $this->resolveColumn('list_price'),
            'markup' => 'markup',
            'location' => 'location',
            'bin_location' => 'bin_location',
            'vendor' => 'vendor',
            'notes' => 'notes',
        ];

        $setClauses = [];
        $params = ['id' => $id];
        foreach ($columnMap as $payloadKey => $columnName) {
            if ($columnName === null || !$this->columnExists($columnName)) {
                continue;
            }

            $setClauses[] = $columnName . ' = :' . $columnName;
            $params[$columnName] = $payload[$payloadKey] ?? null;
        }

        if ($this->hasIsTrackedColumn()) {
            $setClauses[] = 'is_tracked = :is_tracked';
            $params['is_tracked'] = isset($payload['is_tracked']) ? (int) (bool) $payload['is_tracked'] : 1;
        }

        $sql = 'UPDATE inventory_items SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        $this->connection->pdo()->prepare($sql)->execute($params);

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
                'reorder_quantity' => $item->reorder_quantity,
                'severity' => $item->stock_quantity === 0 ? 'out' : 'low',
                'recommended_reorder' => max(0, $item->reorder_quantity - $item->stock_quantity),
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
            $clauses[] = $this->buildSearchClause($filters['query'], $bindings, '');
        }

        if (!empty($filters['low_stock_only'])) {
            if ($this->columnExists('is_low_stock')) {
                $clauses[] = 'is_low_stock = 1';
            } else {
                $stockColumn = $this->resolveColumn('stock_quantity', 'quantity');
                $thresholdColumn = $this->resolveColumn('low_stock_threshold', 'reorder_threshold');
                if ($stockColumn !== null && $thresholdColumn !== null) {
                    $clauses[] = "({$stockColumn} <= {$thresholdColumn})";
                }
            }

            // Only include tracked items in low stock alerts
            if ($this->hasIsTrackedColumn()) {
                $clauses[] = 'is_tracked = 1';
            }
        }

        return [$clauses, $bindings];
    }

    /**
     * @param array<string, mixed> $bindings
     */
    private function buildSearchClause(string $query, array &$bindings, string $tableAlias = 'i'): string
    {
        $prefix = $tableAlias !== '' ? $tableAlias . '.' : '';
        $bindings['fulltext'] = $this->buildFullTextQuery($query);
        $bindings['sku_prefix'] = $query . '%';

        $conditions = [];
        $conditions[] = 'MATCH(' . $prefix . 'name, ' . $prefix . 'description) AGAINST (:fulltext IN BOOLEAN MODE)';
        $conditions[] = $prefix . 'sku LIKE :sku_prefix';

        if ($this->columnExists('manufacturer_part_number')) {
            $bindings['mpn_prefix'] = $query . '%';
            $conditions[] = $prefix . 'manufacturer_part_number LIKE :mpn_prefix';
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    private function buildFullTextQuery(string $query): string
    {
        $tokens = preg_split('/\s+/', trim($query)) ?: [];
        $terms = [];
        foreach ($tokens as $token) {
            $token = preg_replace('/[^\p{L}\p{N}_]+/u', '', $token) ?? '';
            if ($token === '') {
                continue;
            }
            $terms[] = $token . '*';
        }

        return $terms ? implode(' ', $terms) : $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): InventoryItem
    {
        $row['stock_quantity'] = isset($row['stock_quantity'])
            ? (int) $row['stock_quantity']
            : (int) ($row['quantity'] ?? 0);
        $row['low_stock_threshold'] = isset($row['low_stock_threshold'])
            ? (int) $row['low_stock_threshold']
            : (int) ($row['reorder_threshold'] ?? 0);
        $row['reorder_quantity'] = (int) ($row['reorder_quantity'] ?? 0);
        $row['cost'] = (float) ($row['cost'] ?? 0);
        $row['sale_price'] = isset($row['sale_price'])
            ? (float) $row['sale_price']
            : (float) ($row['price'] ?? 0);
        $row['list_price'] = (float) ($row['list_price'] ?? 0);
        $row['manufacturer_part_number'] = $row['manufacturer_part_number'] ?? null;
        $row['markup'] = isset($row['markup']) && $row['markup'] !== null ? (float) $row['markup'] : null;
        $row['is_tracked'] = (bool) ($row['is_tracked'] ?? 1);
        $row['is_low_stock'] = (bool) ($row['is_low_stock'] ?? 0);

        return new InventoryItem($row);
    }

    /**
     * Search inventory items with optional vehicle compatibility filter
     *
     * @param string $query Search query (name, SKU, description, or manufacturer part number)
     * @param int|null $vehicleMasterId Optional vehicle master ID to filter compatible parts
     * @param int $limit Maximum number of results
     * @return array<int, InventoryItem>
     */
    public function searchForParts(string $query, ?int $vehicleMasterId = null, int $limit = 20): array
    {
        $bindings = [];
        $searchConditions = $this->buildSearchClause($query, $bindings);

        if ($vehicleMasterId !== null) {
            // Search only parts compatible with the specified vehicle
            $sql = 'SELECT DISTINCT i.* FROM inventory_items i
                    INNER JOIN inventory_vehicle_compatibility ivc ON i.id = ivc.inventory_item_id
                    WHERE ivc.vehicle_master_id = :vehicle_master_id
                    AND ' . $searchConditions . '
                    ORDER BY i.name ASC
                    LIMIT :limit';
            $bindings['vehicle_master_id'] = $vehicleMasterId;
        } else {
            $sql = 'SELECT * FROM inventory_items i
                    WHERE ' . $searchConditions . '
                    ORDER BY i.name ASC
                    LIMIT :limit';
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = $this->mapRow($row);
            $results[] = $item;
            $this->cache[$item->id] = $item;
        }

        return $results;
    }

    /**
     * Get vehicle compatibility entries for an inventory item
     *
     * @param int $inventoryItemId
     * @return array<int, array<string, mixed>>
     */
    public function getVehicleCompatibility(int $inventoryItemId): array
    {
        $sql = 'SELECT ivc.*, vm.year, vm.make, vm.model, vm.engine, vm.transmission, vm.drive, vm.trim
                FROM inventory_vehicle_compatibility ivc
                INNER JOIN vehicle_master vm ON ivc.vehicle_master_id = vm.id
                WHERE ivc.inventory_item_id = :inventory_item_id
                ORDER BY vm.year DESC, vm.make, vm.model';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['inventory_item_id' => $inventoryItemId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add vehicle compatibility entry
     *
     * @param int $inventoryItemId
     * @param int $vehicleMasterId
     * @param string|null $notes
     * @return InventoryVehicleCompatibility
     */
    public function addVehicleCompatibility(int $inventoryItemId, int $vehicleMasterId, ?string $notes = null): InventoryVehicleCompatibility
    {
        $sql = 'INSERT INTO inventory_vehicle_compatibility (inventory_item_id, vehicle_master_id, notes)
                VALUES (:inventory_item_id, :vehicle_master_id, :notes)
                ON DUPLICATE KEY UPDATE notes = :notes2, updated_at = CURRENT_TIMESTAMP';

        $this->connection->pdo()->prepare($sql)->execute([
            'inventory_item_id' => $inventoryItemId,
            'vehicle_master_id' => $vehicleMasterId,
            'notes' => $notes,
            'notes2' => $notes,
        ]);

        // Get the ID
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM inventory_vehicle_compatibility WHERE inventory_item_id = :inventory_item_id AND vehicle_master_id = :vehicle_master_id'
        );
        $stmt->execute(['inventory_item_id' => $inventoryItemId, 'vehicle_master_id' => $vehicleMasterId]);
        $id = (int) $stmt->fetchColumn();

        return new InventoryVehicleCompatibility([
            'id' => $id,
            'inventory_item_id' => $inventoryItemId,
            'vehicle_master_id' => $vehicleMasterId,
            'notes' => $notes,
        ]);
    }

    /**
     * Remove vehicle compatibility entry
     *
     * @param int $inventoryItemId
     * @param int $vehicleMasterId
     * @return bool
     */
    public function removeVehicleCompatibility(int $inventoryItemId, int $vehicleMasterId): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM inventory_vehicle_compatibility WHERE inventory_item_id = :inventory_item_id AND vehicle_master_id = :vehicle_master_id'
        );
        $stmt->execute(['inventory_item_id' => $inventoryItemId, 'vehicle_master_id' => $vehicleMasterId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Search inventory items with compatibility highlighting for a specific vehicle
     * Returns all matching items with is_compatible flag indicating vehicle match
     *
     * @param string $query Search query
     * @param int $vehicleMasterId Vehicle master ID to check compatibility against
     * @param int $limit Maximum results
     * @return array<int, array<string, mixed>>
     */
    public function searchWithCompatibility(string $query, int $vehicleMasterId, int $limit = 20): array
    {
        $bindings = ['vehicle_master_id' => $vehicleMasterId];
        $searchConditions = $this->buildSearchClause($query, $bindings);

        // Use LEFT JOIN to get all matching parts with compatibility flag
        $sql = 'SELECT i.*,
                    CASE WHEN ivc.id IS NOT NULL THEN 1 ELSE 0 END as is_compatible
                FROM inventory_items i
                LEFT JOIN inventory_vehicle_compatibility ivc
                    ON i.id = ivc.inventory_item_id AND ivc.vehicle_master_id = :vehicle_master_id
                WHERE ' . $searchConditions . '
                ORDER BY is_compatible DESC, i.name ASC
                LIMIT :limit';

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isCompatible = (bool) $row['is_compatible'];
            unset($row['is_compatible']);

            $item = $this->mapRow($row);
            $this->cache[$item->id] = $item;

            $itemArray = $item->toArray();
            $itemArray['is_compatible'] = $isCompatible;
            $results[] = $itemArray;
        }

        return $results;
    }

    /**
     * Get all inventory items compatible with a specific vehicle
     *
     * @param int $vehicleMasterId Vehicle master ID
     * @param int $limit Maximum results
     * @return array<int, InventoryItem>
     */
    public function getCompatibleParts(int $vehicleMasterId, int $limit = 100): array
    {
        $sql = 'SELECT i.* FROM inventory_items i
                INNER JOIN inventory_vehicle_compatibility ivc ON i.id = ivc.inventory_item_id
                WHERE ivc.vehicle_master_id = :vehicle_master_id
                ORDER BY i.category, i.name ASC
                LIMIT :limit';

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':vehicle_master_id', $vehicleMasterId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = $this->mapRow($row);
            $results[] = $item;
            $this->cache[$item->id] = $item;
        }

        return $results;
    }

    /**
     * Bulk add vehicle compatibility entries
     *
     * @param int $inventoryItemId
     * @param array<int, int> $vehicleMasterIds
     * @return int Number of entries added
     */
    public function bulkAddVehicleCompatibility(int $inventoryItemId, array $vehicleMasterIds): int
    {
        if (empty($vehicleMasterIds)) {
            return 0;
        }

        $sql = 'INSERT IGNORE INTO inventory_vehicle_compatibility (inventory_item_id, vehicle_master_id) VALUES ';
        $placeholders = [];
        $bindings = [];

        foreach ($vehicleMasterIds as $index => $vehicleMasterId) {
            $placeholders[] = "(:inventory_item_id, :vehicle_master_id_{$index})";
            $bindings["vehicle_master_id_{$index}"] = $vehicleMasterId;
        }

        $sql .= implode(', ', $placeholders);
        $bindings['inventory_item_id'] = $inventoryItemId;

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }
}
