<?php

namespace App\Services\Division;

use App\Database\Connection;
use App\Models\Division;
use PDO;
use RuntimeException;

class DivisionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, Division>
     */
    public function listActive(): array
    {
        $sql = 'SELECT id, code, name, description, is_active, sort_order, created_at, updated_at
                FROM divisions
                WHERE is_active = 1
                ORDER BY sort_order, name';

        $stmt = $this->connection->pdo()->query($sql);
        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn (array $row) => new Division($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<int, Division>
     */
    public function listAll(): array
    {
        $sql = 'SELECT id, code, name, description, is_active, sort_order, created_at, updated_at
                FROM divisions
                ORDER BY sort_order, name';

        $stmt = $this->connection->pdo()->query($sql);
        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn (array $row) => new Division($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?Division
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, code, name, description, is_active, sort_order, created_at, updated_at
             FROM divisions WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new Division($row) : null;
    }

    public function findByCode(string $code): ?Division
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, code, name, description, is_active, sort_order, created_at, updated_at
             FROM divisions WHERE code = :code LIMIT 1'
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new Division($row) : null;
    }

    /**
     * @param array{code: string, name: string, description?: ?string, is_active?: bool, sort_order?: int} $data
     */
    public function create(array $data): Division
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO divisions (code, name, description, is_active, sort_order)
             VALUES (:code, :name, :description, :is_active, :sort_order)'
        );
        $stmt->execute([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => (int) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $division = $this->findById($id);

        if ($division === null) {
            throw new RuntimeException('Failed to load newly created division');
        }

        return $division;
    }

    /**
     * @param array{code?: string, name?: string, description?: ?string, is_active?: bool, sort_order?: int} $data
     */
    public function update(int $id, array $data): Division
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (['code', 'name', 'description'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key];
            }
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int) $data['is_active'];
        }
        if (array_key_exists('sort_order', $data)) {
            $fields[] = 'sort_order = :sort_order';
            $params['sort_order'] = (int) $data['sort_order'];
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE divisions SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $division = $this->findById($id);
        if ($division === null) {
            throw new RuntimeException("Division {$id} not found after update");
        }

        return $division;
    }
}
