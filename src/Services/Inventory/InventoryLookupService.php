<?php

namespace App\Services\Inventory;

use App\Database\Connection;
use App\Models\InventoryLookup;
use InvalidArgumentException;
use PDO;

class InventoryLookupService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<int, InventoryLookup>
     */
    public function list(string $type, array $filters = []): array
    {
        $sql = 'SELECT * FROM inventory_lookups WHERE type = :type';
        $params = ['type' => $type];

        if ($type === 'vendors' && !empty($filters['parts_supplier'])) {
            $sql .= ' AND is_parts_supplier = 1';
        }

        $sql .= ' ORDER BY name ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn ($row) => $this->map($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(string $type, array $payload): InventoryLookup
    {
        $validated = $this->validate($type, $payload);

        $stmt = $this->connection
            ->pdo()
            ->prepare('INSERT INTO inventory_lookups (type, name, description, is_parts_supplier) VALUES (:type, :name, :description, :is_parts_supplier)');

        $stmt->execute([
            'type' => $type,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'is_parts_supplier' => $validated['is_parts_supplier'],
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, string $type, array $payload): ?InventoryLookup
    {
        $existing = $this->find($id);
        if ($existing === null || $existing->type !== $type) {
            return null;
        }

        $validated = $this->validate($type, array_merge($existing->toArray(), $payload));

        $stmt = $this->connection
            ->pdo()
            ->prepare('UPDATE inventory_lookups SET name = :name, description = :description, is_parts_supplier = :is_parts_supplier WHERE id = :id');

        $stmt->execute([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'is_parts_supplier' => $validated['is_parts_supplier'],
            'id' => $id,
        ]);

        return $this->find($id);
    }

    public function delete(int $id, string $type): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM inventory_lookups WHERE id = :id AND type = :type');
        $stmt->execute(['id' => $id, 'type' => $type]);

        return $stmt->rowCount() > 0;
    }

    private function find(int $id): ?InventoryLookup
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inventory_lookups WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->map($row) : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validate(string $type, array $payload): array
    {
        if (!in_array($type, ['categories', 'vendors', 'locations'], true)) {
            throw new InvalidArgumentException('Invalid lookup type');
        }

        if (empty($payload['name'])) {
            throw new InvalidArgumentException('Name is required');
        }

        $payload['description'] = $payload['description'] ?? null;
        
        // FIX: Cast to integer (0 or 1) because PDO casts false to "" (empty string), 
        // causing "Incorrect integer value" errors in MySQL strict mode.
        $payload['is_parts_supplier'] = !empty($payload['is_parts_supplier']) ? 1 : 0;

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): InventoryLookup
    {
        $row['id'] = (int) $row['id'];
        $row['is_parts_supplier'] = (bool) $row['is_parts_supplier'];

        return new InventoryLookup($row);
    }
}