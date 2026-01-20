<?php

namespace App\Services\Inventory;

use App\Database\Connection;
use PDO;

class InventoryVehicleCompatibilityRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findMatchingInventoryItem(string $description, int $vehicleMasterId): ?array
    {
        $query = trim($description);
        if ($query === '') {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT i.*
            FROM inventory_vehicle_compatibility ivc
            INNER JOIN inventory_items i ON i.id = ivc.inventory_item_id
            WHERE ivc.vehicle_master_id = :vehicle_master_id
              AND (i.name LIKE :search OR i.description LIKE :search OR i.sku LIKE :search)
            ORDER BY
              CASE
                WHEN i.sku = :exact THEN 0
                WHEN i.name = :exact THEN 1
                WHEN i.description = :exact THEN 2
                ELSE 3
              END,
              i.name ASC
            LIMIT 1
        SQL);

        $stmt->execute([
            'vehicle_master_id' => $vehicleMasterId,
            'search' => '%' . $query . '%',
            'exact' => $query,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findMatchingInventoryItemForVehicle(string $description, int $vehicleId): ?array
    {
        $query = trim($description);
        if ($query === '') {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT i.*
            FROM customer_vehicles cv
            INNER JOIN inventory_vehicle_compatibility ivc ON ivc.vehicle_master_id = cv.vehicle_master_id
            INNER JOIN inventory_items i ON i.id = ivc.inventory_item_id
            WHERE cv.id = :vehicle_id
              AND (i.name LIKE :search OR i.description LIKE :search OR i.sku LIKE :search)
            ORDER BY
              CASE
                WHEN i.sku = :exact THEN 0
                WHEN i.name = :exact THEN 1
                WHEN i.description = :exact THEN 2
                ELSE 3
              END,
              i.name ASC
            LIMIT 1
        SQL);

        $stmt->execute([
            'vehicle_id' => $vehicleId,
            'search' => '%' . $query . '%',
            'exact' => $query,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
