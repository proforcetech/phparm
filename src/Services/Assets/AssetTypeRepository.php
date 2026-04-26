<?php

namespace App\Services\Assets;

use App\Database\Connection;
use App\Models\AssetType;
use PDO;
use RuntimeException;

/**
 * Shared catalog of installed-asset categories (Phase 2.1 of
 * docs/expansion-plan.md). Types can be nested via parent_id so a division
 * can model hierarchies like "HVAC > Rooftop Unit > Compressor".
 */
class AssetTypeRepository
{
    private const COLUMNS = 'id, division_id, parent_id, code, name, description, icon, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, AssetType>
     */
    public function listAll(?int $divisionId = null, bool $activeOnly = true): array
    {
        $where = ['1=1'];
        $params = [];
        if ($divisionId !== null) {
            $where[] = '(division_id = :division_id OR division_id IS NULL)';
            $params['division_id'] = $divisionId;
        }
        if ($activeOnly) {
            $where[] = 'is_active = 1';
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM asset_types WHERE '
            . implode(' AND ', $where) . ' ORDER BY name ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn(array $r) => new AssetType($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?AssetType
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM asset_types WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new AssetType($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AssetType
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO asset_types (division_id, parent_id, code, name, description, icon, is_active)
             VALUES (:division_id, :parent_id, :code, :name, :description, :icon, :is_active)'
        );
        $stmt->execute([
            'division_id' => $data['division_id'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'is_active' => !empty($data['is_active'] ?? true) ? 1 : 0,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('Asset type insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): AssetType
    {
        $fields = [];
        $params = ['id' => $id];
        foreach (['division_id', 'parent_id', 'code', 'name', 'description', 'icon'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = !empty($data['is_active']) ? 1 : 0;
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("Asset type {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE asset_types SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("Asset type {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM asset_types WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
