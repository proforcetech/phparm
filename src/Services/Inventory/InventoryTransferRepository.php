<?php

namespace App\Services\Inventory;

use App\Database\Connection;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class InventoryTransferRepository
{
    private Connection $connection;
    private AuditLogger $auditLogger;
    private InventoryTransactionRepository $transactionRepository;

    public function __construct(Connection $connection, AuditLogger $auditLogger)
    {
        $this->connection = $connection;
        $this->auditLogger = $auditLogger;
        $this->transactionRepository = new InventoryTransactionRepository($connection);
    }

    public function find(int $id): ?InventoryTransfer
    {
        $sql = "SELECT it.*,
                u1.name as requested_by_name,
                u2.name as approved_by_name,
                u3.name as rejected_by_name,
                u4.name as completed_by_name,
                u5.name as cancelled_by_name,
                totals.total_quantity_requested,
                totals.total_quantity_transferred
            FROM inventory_transfers it
            LEFT JOIN users u1 ON it.requested_by = u1.id
            LEFT JOIN users u2 ON it.approved_by = u2.id
            LEFT JOIN users u3 ON it.rejected_by = u3.id
            LEFT JOIN users u4 ON it.completed_by = u4.id
            LEFT JOIN users u5 ON it.cancelled_by = u5.id
            LEFT JOIN (
                SELECT transfer_id,
                    SUM(quantity_requested) as total_quantity_requested,
                    SUM(COALESCE(quantity_transferred, 0)) as total_quantity_transferred
                FROM inventory_transfer_items
                GROUP BY transfer_id
            ) totals ON it.id = totals.transfer_id
            WHERE it.id = :id";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $transfer = $this->mapTransferRow($row);
        $transfer->items = $this->fetchItems($transfer->id);

        return $transfer;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: InventoryTransfer[], total: int}
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$clauses, $bindings] = $this->buildFilterClauses($filters);
        $whereClause = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $countSql = "SELECT COUNT(*) FROM inventory_transfers it $whereClause";
        $stmt = $this->connection->pdo()->prepare($countSql);
        $stmt->execute($bindings);
        $total = (int) $stmt->fetchColumn();

        $sql = "SELECT it.*,
                u1.name as requested_by_name,
                u2.name as approved_by_name,
                u3.name as rejected_by_name,
                u4.name as completed_by_name,
                u5.name as cancelled_by_name,
                totals.total_quantity_requested,
                totals.total_quantity_transferred
            FROM inventory_transfers it
            LEFT JOIN users u1 ON it.requested_by = u1.id
            LEFT JOIN users u2 ON it.approved_by = u2.id
            LEFT JOIN users u3 ON it.rejected_by = u3.id
            LEFT JOIN users u4 ON it.completed_by = u4.id
            LEFT JOIN users u5 ON it.cancelled_by = u5.id
            LEFT JOIN (
                SELECT transfer_id,
                    SUM(quantity_requested) as total_quantity_requested,
                    SUM(COALESCE(quantity_transferred, 0)) as total_quantity_transferred
                FROM inventory_transfer_items
                GROUP BY transfer_id
            ) totals ON it.id = totals.transfer_id
            $whereClause
            ORDER BY it.requested_at DESC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = $this->mapTransferRow($row);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, int $actorId): InventoryTransfer
    {
        $items = $data['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new InvalidArgumentException('Transfer items are required.');
        }

        $sourceLocation = $data['source_location'] ?? null;
        $destinationLocation = $data['destination_location'] ?? null;
        $notes = $data['notes'] ?? null;

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO inventory_transfers (status, source_location, destination_location, notes, requested_by, requested_at)
                VALUES (:status, :source_location, :destination_location, :notes, :requested_by, NOW())'
            );
            $stmt->execute([
                'status' => InventoryTransfer::STATUS_PENDING,
                'source_location' => $sourceLocation,
                'destination_location' => $destinationLocation,
                'notes' => $notes,
                'requested_by' => $actorId,
            ]);

            $transferId = (int) $pdo->lastInsertId();
            $itemStmt = $pdo->prepare(
                'INSERT INTO inventory_transfer_items (transfer_id, source_inventory_item_id, destination_inventory_item_id, quantity_requested)
                VALUES (:transfer_id, :source_inventory_item_id, :destination_inventory_item_id, :quantity_requested)'
            );

            foreach ($items as $item) {
                $sourceItemId = (int) ($item['source_inventory_item_id'] ?? 0);
                $destinationItemId = (int) ($item['destination_inventory_item_id'] ?? 0);
                $quantity = (int) ($item['quantity_requested'] ?? $item['quantity'] ?? 0);

                if ($sourceItemId <= 0 || $destinationItemId <= 0) {
                    throw new InvalidArgumentException('Source and destination items are required.');
                }
                if ($quantity <= 0) {
                    throw new InvalidArgumentException('Transfer quantity must be greater than zero.');
                }

                $itemStmt->execute([
                    'transfer_id' => $transferId,
                    'source_inventory_item_id' => $sourceItemId,
                    'destination_inventory_item_id' => $destinationItemId,
                    'quantity_requested' => $quantity,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $this->logAudit('inventory_transfer.requested', $transferId, $actorId, [
            'source_location' => $sourceLocation,
            'destination_location' => $destinationLocation,
            'notes' => $notes,
        ]);

        $transfer = $this->find($transferId);
        if ($transfer === null) {
            throw new InvalidArgumentException('Transfer not found after creation.');
        }

        return $transfer;
    }

    public function approve(int $id, int $actorId): ?InventoryTransfer
    {
        return $this->updateStatus($id, InventoryTransfer::STATUS_APPROVED, $actorId, 'approved_by', 'approved_at');
    }

    public function reject(int $id, int $actorId, ?string $reason = null): ?InventoryTransfer
    {
        return $this->updateStatus(
            $id,
            InventoryTransfer::STATUS_REJECTED,
            $actorId,
            'rejected_by',
            'rejected_at',
            $reason
        );
    }

    public function cancel(int $id, int $actorId, ?string $reason = null): ?InventoryTransfer
    {
        return $this->updateStatus(
            $id,
            InventoryTransfer::STATUS_CANCELLED,
            $actorId,
            'cancelled_by',
            'cancelled_at',
            $reason
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function complete(int $id, array $data, int $actorId): ?InventoryTransfer
    {
        $transfer = $this->find($id);
        if ($transfer === null) {
            return null;
        }

        if ($transfer->status !== InventoryTransfer::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved transfers can be completed.');
        }

        $items = $transfer->items;
        if ($items === []) {
            throw new InvalidArgumentException('Transfer has no items.');
        }

        $quantityMap = $this->resolveQuantityMap($data['items'] ?? []);

        $inventoryIds = [];
        foreach ($items as $item) {
            $inventoryIds[$item->source_inventory_item_id] = true;
            $inventoryIds[$item->destination_inventory_item_id] = true;
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $stockMap = $this->loadInventoryStock(array_keys($inventoryIds));

            $updateStockStmt = $pdo->prepare('UPDATE inventory_items SET stock_quantity = :stock_quantity WHERE id = :id');
            $updateItemStmt = $pdo->prepare('UPDATE inventory_transfer_items SET quantity_transferred = :quantity_transferred WHERE id = :id');

            foreach ($items as $item) {
                $quantity = $quantityMap[$item->id] ?? $item->quantity_requested;

                if ($quantity <= 0) {
                    throw new InvalidArgumentException('Transfer quantity must be greater than zero.');
                }
                if ($quantity > $item->quantity_requested) {
                    throw new InvalidArgumentException('Transfer quantity exceeds requested amount.');
                }

                $sourceId = $item->source_inventory_item_id;
                $destinationId = $item->destination_inventory_item_id;

                if (!isset($stockMap[$sourceId]) || !isset($stockMap[$destinationId])) {
                    throw new InvalidArgumentException('Inventory item not found for transfer.');
                }

                $sourceBefore = $stockMap[$sourceId];
                $destinationBefore = $stockMap[$destinationId];

                if ($sourceBefore < $quantity) {
                    throw new InvalidArgumentException('Insufficient stock to complete transfer.');
                }

                $sourceAfter = $sourceBefore - $quantity;
                $destinationAfter = $destinationBefore + $quantity;

                $updateStockStmt->execute([
                    'stock_quantity' => $sourceAfter,
                    'id' => $sourceId,
                ]);
                $updateStockStmt->execute([
                    'stock_quantity' => $destinationAfter,
                    'id' => $destinationId,
                ]);

                $updateItemStmt->execute([
                    'quantity_transferred' => $quantity,
                    'id' => $item->id,
                ]);

                $this->transactionRepository->record(
                    $sourceId,
                    $sourceBefore,
                    $sourceAfter,
                    'inventory_transfer',
                    'transfer:' . $id,
                    'Transfer to item #' . $destinationId,
                    $actorId
                );
                $this->transactionRepository->record(
                    $destinationId,
                    $destinationBefore,
                    $destinationAfter,
                    'inventory_transfer',
                    'transfer:' . $id,
                    'Transfer from item #' . $sourceId,
                    $actorId
                );

                $stockMap[$sourceId] = $sourceAfter;
                $stockMap[$destinationId] = $destinationAfter;
            }

            $pdo->prepare(
                'UPDATE inventory_transfers
                SET status = :status, completed_by = :completed_by, completed_at = NOW()
                WHERE id = :id'
            )->execute([
                'status' => InventoryTransfer::STATUS_COMPLETED,
                'completed_by' => $actorId,
                'id' => $id,
            ]);

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $this->logAudit('inventory_transfer.completed', $id, $actorId, [
            'item_count' => count($items),
        ]);

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function report(array $filters = []): array
    {
        [$clauses, $bindings] = $this->buildFilterClauses($filters);
        $whereClause = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $statusCounts = array_fill_keys(InventoryTransfer::ALLOWED_STATUSES, 0);
        $countSql = "SELECT status, COUNT(*) as count
            FROM inventory_transfers
            $whereClause
            GROUP BY status";
        $stmt = $this->connection->pdo()->prepare($countSql);
        $stmt->execute($bindings);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $statusCounts[$row['status']] = (int) $row['count'];
        }

        $totalSql = "SELECT
                SUM(items.quantity_requested) as total_quantity_requested,
                SUM(COALESCE(items.quantity_transferred, 0)) as total_quantity_transferred
            FROM inventory_transfers it
            LEFT JOIN inventory_transfer_items items ON it.id = items.transfer_id
            $whereClause";
        $stmt = $this->connection->pdo()->prepare($totalSql);
        $stmt->execute($bindings);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'status_counts' => $statusCounts,
            'total_quantity_requested' => (int) ($totals['total_quantity_requested'] ?? 0),
            'total_quantity_transferred' => (int) ($totals['total_quantity_transferred'] ?? 0),
        ];
    }

    /**
     * @return InventoryTransferItem[]
     */
    private function fetchItems(int $transferId): array
    {
        $sql = "SELECT iti.*,
                src.name as source_item_name,
                dest.name as destination_item_name,
                src.sku as source_item_sku,
                dest.sku as destination_item_sku
            FROM inventory_transfer_items iti
            LEFT JOIN inventory_items src ON iti.source_inventory_item_id = src.id
            LEFT JOIN inventory_items dest ON iti.destination_inventory_item_id = dest.id
            WHERE iti.transfer_id = :transfer_id
            ORDER BY iti.id ASC";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['transfer_id' => $transferId]);

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = $this->mapItemRow($row);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapTransferRow(array $row): InventoryTransfer
    {
        $row['id'] = (int) $row['id'];
        $row['requested_by'] = (int) $row['requested_by'];
        $row['approved_by'] = isset($row['approved_by']) ? (int) $row['approved_by'] : null;
        $row['rejected_by'] = isset($row['rejected_by']) ? (int) $row['rejected_by'] : null;
        $row['cancelled_by'] = isset($row['cancelled_by']) ? (int) $row['cancelled_by'] : null;
        $row['completed_by'] = isset($row['completed_by']) ? (int) $row['completed_by'] : null;
        $row['total_quantity_requested'] = isset($row['total_quantity_requested']) ? (int) $row['total_quantity_requested'] : null;
        $row['total_quantity_transferred'] = isset($row['total_quantity_transferred']) ? (int) $row['total_quantity_transferred'] : null;

        return new InventoryTransfer($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapItemRow(array $row): InventoryTransferItem
    {
        $row['id'] = (int) $row['id'];
        $row['transfer_id'] = (int) $row['transfer_id'];
        $row['source_inventory_item_id'] = (int) $row['source_inventory_item_id'];
        $row['destination_inventory_item_id'] = (int) $row['destination_inventory_item_id'];
        $row['quantity_requested'] = (int) $row['quantity_requested'];
        $row['quantity_transferred'] = isset($row['quantity_transferred']) ? (int) $row['quantity_transferred'] : null;

        return new InventoryTransferItem($row);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string[], 1: array<string, mixed>}
     */
    private function buildFilterClauses(array $filters): array
    {
        $clauses = [];
        $bindings = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'it.status = :status';
            $bindings['status'] = $filters['status'];
        }

        if (!empty($filters['requested_by'])) {
            $clauses[] = 'it.requested_by = :requested_by';
            $bindings['requested_by'] = (int) $filters['requested_by'];
        }

        if (!empty($filters['source_location'])) {
            $clauses[] = 'it.source_location = :source_location';
            $bindings['source_location'] = $filters['source_location'];
        }

        if (!empty($filters['destination_location'])) {
            $clauses[] = 'it.destination_location = :destination_location';
            $bindings['destination_location'] = $filters['destination_location'];
        }

        if (!empty($filters['created_from'])) {
            $clauses[] = 'it.requested_at >= :created_from';
            $bindings['created_from'] = $filters['created_from'];
        }

        if (!empty($filters['created_to'])) {
            $clauses[] = 'it.requested_at <= :created_to';
            $bindings['created_to'] = $filters['created_to'];
        }

        return [$clauses, $bindings];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, int>
     */
    private function resolveQuantityMap(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId = (int) ($item['id'] ?? $item['transfer_item_id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $quantity = (int) ($item['quantity_transferred'] ?? $item['quantity'] ?? $item['quantity_requested'] ?? 0);
            if ($quantity > 0) {
                $map[$itemId] = $quantity;
            }
        }

        return $map;
    }

    /**
     * @param int[] $inventoryIds
     * @return array<int, int>
     */
    private function loadInventoryStock(array $inventoryIds): array
    {
        if ($inventoryIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($inventoryIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            "SELECT id, stock_quantity FROM inventory_items WHERE id IN ($placeholders) FOR UPDATE"
        );
        $stmt->execute(array_values($inventoryIds));

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $row['id']] = (int) $row['stock_quantity'];
        }

        return $map;
    }

    private function updateStatus(
        int $id,
        string $status,
        int $actorId,
        string $actorColumn,
        string $timeColumn,
        ?string $notes = null
    ): ?InventoryTransfer {
        if (!in_array($status, InventoryTransfer::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid transfer status.');
        }

        $transfer = $this->find($id);
        if ($transfer === null) {
            return null;
        }

        if ($transfer->status === InventoryTransfer::STATUS_COMPLETED) {
            throw new InvalidArgumentException('Completed transfers cannot be updated.');
        }

        $allowedStatuses = [
            InventoryTransfer::STATUS_PENDING,
            InventoryTransfer::STATUS_APPROVED,
        ];

        if (!in_array($transfer->status, $allowedStatuses, true)) {
            throw new InvalidArgumentException('Transfer status cannot be updated.');
        }

        $sql = "UPDATE inventory_transfers
            SET status = :status,
                {$actorColumn} = :actor_id,
                {$timeColumn} = NOW(),
                notes = COALESCE(:notes, notes)
            WHERE id = :id";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'status' => $status,
            'actor_id' => $actorId,
            'notes' => $notes,
            'id' => $id,
        ]);

        $event = match ($status) {
            InventoryTransfer::STATUS_APPROVED => 'inventory_transfer.approved',
            InventoryTransfer::STATUS_REJECTED => 'inventory_transfer.rejected',
            InventoryTransfer::STATUS_CANCELLED => 'inventory_transfer.cancelled',
            default => 'inventory_transfer.updated',
        };

        $this->logAudit($event, $id, $actorId, [
            'notes' => $notes,
        ]);

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAudit(string $action, int $transferId, int $actorId, array $context = []): void
    {
        $entry = new AuditEntry($action, 'inventory_transfer', (string) $transferId, $actorId, $context);
        $this->auditLogger->log($entry);
    }
}
