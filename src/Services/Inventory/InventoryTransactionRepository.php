<?php

namespace App\Services\Inventory;

use App\Database\Connection;
use PDO;

class InventoryTransactionRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function record(
        int $inventoryItemId,
        int $quantityBefore,
        int $quantityAfter,
        string $source,
        ?string $reference = null,
        ?string $reason = null,
        ?int $actorId = null,
        ?int $branchId = null
    ): void {
        if ($quantityBefore === $quantityAfter) {
            return;
        }

        $sql = 'INSERT INTO inventory_transactions (
                inventory_item_id,
                branch_id,
                quantity_before,
                quantity_after,
                quantity_change,
                source,
                reference,
                reason,
                created_by,
                created_at
            ) VALUES (
                :inventory_item_id,
                :quantity_before,
                :quantity_after,
                :quantity_change,
                :source,
                :reference,
                :reason,
                :created_by,
                NOW()
            )';

        $this->connection->pdo()->prepare($sql)->execute([
            'inventory_item_id' => $inventoryItemId,
            'branch_id' => $branchId,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'quantity_change' => $quantityAfter - $quantityBefore,
            'source' => $source,
            'reference' => $reference,
            'reason' => $reason,
            'created_by' => $actorId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByItem(int $inventoryItemId, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT it.*,
                u.name as created_by_name
            FROM inventory_transactions it
            LEFT JOIN users u ON it.created_by = u.id
            WHERE it.inventory_item_id = :inventory_item_id
            ORDER BY it.created_at DESC, it.id DESC
            LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->bindValue(':inventory_item_id', $inventoryItemId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['inventory_item_id'] = (int) $row['inventory_item_id'];
            $row['quantity_before'] = (int) $row['quantity_before'];
            $row['quantity_after'] = (int) $row['quantity_after'];
            $row['quantity_change'] = (int) $row['quantity_change'];
            $row['created_by'] = isset($row['created_by']) ? (int) $row['created_by'] : null;
        }
        unset($row);

        return $rows;
    }
}
