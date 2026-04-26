<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use PDO;
use RuntimeException;

/**
 * Shared CRUD base for the code-only catalogs
 * (resolution_codes + failure_codes).  Close reasons have an extra column
 * (`requires_detail`) so they stay in their own repository.
 *
 * Subclasses declare the table and the model class; all CRUD and lookup
 * behavior is inherited.
 */
abstract class TicketCodeCatalogRepository
{
    protected const COLUMNS = 'id, code, name, description, is_active, created_at, updated_at';

    public function __construct(protected readonly Connection $connection)
    {
    }

    abstract protected function table(): string;

    /**
     * @return class-string
     */
    abstract protected function modelClass(): string;

    /**
     * @return array<int, object>
     */
    public function listAll(bool $activeOnly = true): array
    {
        $sql = 'SELECT ' . static::COLUMNS . ' FROM ' . $this->table();
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';
        $stmt = $this->connection->pdo()->query($sql);
        $class = $this->modelClass();
        return array_map(
            static fn(array $r) => new $class($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findByCode(string $code): ?object
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . static::COLUMNS . ' FROM ' . $this->table() . ' WHERE code = :c LIMIT 1'
        );
        $stmt->execute(['c' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $class = $this->modelClass();
        return new $class($row);
    }

    public function findById(int $id): ?object
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . static::COLUMNS . ' FROM ' . $this->table() . ' WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $class = $this->modelClass();
        return new $class($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): object
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ' . $this->table() . ' (code, name, description, is_active)
             VALUES (:code, :name, :description, :is_active)'
        );
        $stmt->execute([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException($this->table() . ' insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): object
    {
        $fields = [];
        $params = ['id' => $id];
        foreach (['code', 'name', 'description'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int) (bool) $data['is_active'];
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException($this->table() . " {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE ' . $this->table() . ' SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException($this->table() . " {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM ' . $this->table() . ' WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
