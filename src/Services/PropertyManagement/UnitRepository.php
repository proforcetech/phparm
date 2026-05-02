<?php

namespace App\Services\PropertyManagement;

use App\Database\Connection;
use App\Models\Unit;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Data access for the `units` table — Phase 12 of docs/woms-expansion-plan.md.
 *
 * All SQL is parameterized; no string interpolation of caller-supplied values.
 * Mirrors the patterns used by ServiceLineRepository and SiteAssetRepository.
 */
class UnitRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{site_id?: int, status?: string, unit_type?: string, search?: string} $filters
     * @return array<int, Unit>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT * FROM units WHERE 1=1';
        $params = [];

        if (isset($filters['site_id'])) {
            $sql .= ' AND site_id = :site_id';
            $params['site_id'] = (int) $filters['site_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['unit_type'])) {
            $sql .= ' AND unit_type = :unit_type';
            $params['unit_type'] = (string) $filters['unit_type'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (code LIKE :search OR name LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY site_id, code LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => Unit::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Total row count matching the same filters as list(); used to render
     * pagination headers without a second round-trip from the controller.
     *
     * @param array{site_id?: int, status?: string, unit_type?: string, search?: string} $filters
     */
    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) FROM units WHERE 1=1';
        $params = [];

        if (isset($filters['site_id'])) {
            $sql .= ' AND site_id = :site_id';
            $params['site_id'] = (int) $filters['site_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['unit_type'])) {
            $sql .= ' AND unit_type = :unit_type';
            $params['unit_type'] = (string) $filters['unit_type'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (code LIKE :search OR name LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?Unit
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM units WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Unit::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Unit
    {
        try {
            $stmt = $this->connection->pdo()->prepare(
                'INSERT INTO units
                    (site_id, code, name, unit_type, floor, square_feet, bedrooms, bathrooms, status, notes)
                 VALUES
                    (:site_id, :code, :name, :unit_type, :floor, :square_feet, :bedrooms, :bathrooms, :status, :notes)'
            );
            $stmt->execute([
                'site_id' => (int) $data['site_id'],
                'code' => (string) $data['code'],
                'name' => $data['name'] ?? null,
                'unit_type' => $data['unit_type'] ?? 'commercial',
                'floor' => $data['floor'] ?? null,
                'square_feet' => isset($data['square_feet']) ? (int) $data['square_feet'] : null,
                'bedrooms' => isset($data['bedrooms']) ? (int) $data['bedrooms'] : null,
                'bathrooms' => isset($data['bathrooms']) ? (float) $data['bathrooms'] : null,
                'status' => $data['status'] ?? 'active',
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (PDOException $e) {
            // Duplicate (site_id, code) pair triggers SQLSTATE 23000 on the unique key.
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException(
                    "Unit code '{$data['code']}' already exists for site {$data['site_id']}",
                    0,
                    $e
                );
            }
            throw $e;
        }

        $id = (int) $this->connection->pdo()->lastInsertId();
        $unit = $this->findById($id);
        if ($unit === null) {
            throw new RuntimeException('Failed to load newly created unit');
        }
        return $unit;
    }

    /**
     * Partial update — only keys present in $data are written.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Unit
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("Unit {$id} not found");
        }

        $fields = [];
        $params = ['id' => $id];

        $simple = ['code', 'name', 'unit_type', 'floor', 'status', 'notes'];
        foreach ($simple as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key];
            }
        }
        if (array_key_exists('square_feet', $data)) {
            $fields[] = 'square_feet = :square_feet';
            $params['square_feet'] = $data['square_feet'] === null ? null : (int) $data['square_feet'];
        }
        if (array_key_exists('bedrooms', $data)) {
            $fields[] = 'bedrooms = :bedrooms';
            $params['bedrooms'] = $data['bedrooms'] === null ? null : (int) $data['bedrooms'];
        }
        if (array_key_exists('bathrooms', $data)) {
            $fields[] = 'bathrooms = :bathrooms';
            $params['bathrooms'] = $data['bathrooms'] === null ? null : (float) $data['bathrooms'];
        }

        if ($fields !== []) {
            try {
                $stmt = $this->connection->pdo()->prepare(
                    'UPDATE units SET ' . implode(', ', $fields) . ' WHERE id = :id'
                );
                $stmt->execute($params);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new InvalidArgumentException(
                        'Unit code conflicts with an existing unit for the same site',
                        0,
                        $e
                    );
                }
                throw $e;
            }
        }

        $updated = $this->findById($id);
        if ($updated === null) {
            throw new RuntimeException("Unit {$id} not found after update");
        }
        return $updated;
    }

    /**
     * Hard-delete is allowed only when no leases reference the unit. Callers
     * should prefer status='inactive' for occupied units.
     */
    public function delete(int $id): void
    {
        $check = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM tenant_leases WHERE unit_id = :id'
        );
        $check->execute(['id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            throw new InvalidArgumentException(
                'Cannot delete unit with existing leases. Mark it inactive instead.'
            );
        }

        $stmt = $this->connection->pdo()->prepare('DELETE FROM units WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
