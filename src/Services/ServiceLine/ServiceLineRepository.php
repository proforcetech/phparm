<?php

namespace App\Services\ServiceLine;

use App\Database\Connection;
use App\Models\ServiceLine;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Data-access layer for the service_lines / user_service_lines tables and
 * the users.primary_service_line_id column. Mirrors {@see DivisionRepository}.
 *
 * All SQL is parameterized; no string interpolation of caller-supplied values.
 */
class ServiceLineRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, ServiceLine>
     */
    public function listActive(): array
    {
        $sql = 'SELECT id, slug, name, description, icon, sort_order, is_active, created_at, updated_at
                FROM service_lines
                WHERE is_active = 1
                ORDER BY sort_order, name';

        $stmt = $this->connection->pdo()->query($sql);
        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn (array $row) => ServiceLine::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<int, ServiceLine>
     */
    public function listAll(): array
    {
        $sql = 'SELECT id, slug, name, description, icon, sort_order, is_active, created_at, updated_at
                FROM service_lines
                ORDER BY sort_order, name';

        $stmt = $this->connection->pdo()->query($sql);
        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn (array $row) => ServiceLine::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?ServiceLine
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, slug, name, description, icon, sort_order, is_active, created_at, updated_at
             FROM service_lines WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ServiceLine::fromRow($row) : null;
    }

    public function findBySlug(string $slug): ?ServiceLine
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, slug, name, description, icon, sort_order, is_active, created_at, updated_at
             FROM service_lines WHERE slug = :slug LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ServiceLine::fromRow($row) : null;
    }

    /**
     * @param array{
     *     slug: string,
     *     name: string,
     *     description?: ?string,
     *     icon?: ?string,
     *     sort_order?: int,
     *     is_active?: bool
     * } $data
     */
    public function create(array $data): ServiceLine
    {
        if ($this->findBySlug($data['slug']) !== null) {
            throw new InvalidArgumentException("Service line slug '{$data['slug']}' already exists");
        }

        try {
            $stmt = $this->connection->pdo()->prepare(
                'INSERT INTO service_lines (slug, name, description, icon, sort_order, is_active)
                 VALUES (:slug, :name, :description, :icon, :sort_order, :is_active)'
            );
            $stmt->execute([
                'slug' => $data['slug'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (int) ($data['is_active'] ?? true),
            ]);
        } catch (PDOException $e) {
            // 23000 covers MySQL's duplicate-key error on the unique slug index.
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException(
                    "Service line slug '{$data['slug']}' already exists",
                    0,
                    $e
                );
            }
            throw $e;
        }

        $id = (int) $this->connection->pdo()->lastInsertId();
        $serviceLine = $this->findById($id);

        if ($serviceLine === null) {
            throw new RuntimeException('Failed to load newly created service line');
        }

        return $serviceLine;
    }

    /**
     * Partial update. Only keys present in $data are written to the row.
     *
     * @param array{
     *     slug?: string,
     *     name?: string,
     *     description?: ?string,
     *     icon?: ?string,
     *     sort_order?: int,
     *     is_active?: bool
     * } $data
     */
    public function update(int $id, array $data): ServiceLine
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new RuntimeException("Service line {$id} not found");
        }

        $fields = [];
        $params = ['id' => $id];

        foreach (['slug', 'name', 'description', 'icon'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key];
            }
        }
        if (array_key_exists('sort_order', $data)) {
            $fields[] = 'sort_order = :sort_order';
            $params['sort_order'] = (int) $data['sort_order'];
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int) $data['is_active'];
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE service_lines SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $updated = $this->findById($id);
        if ($updated === null) {
            throw new RuntimeException("Service line {$id} not found after update");
        }

        return $updated;
    }

    /**
     * Service lines a user has explicit membership in via user_service_lines.
     *
     * @return array<int, ServiceLine>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT sl.id, sl.slug, sl.name, sl.description, sl.icon, sl.sort_order,
                    sl.is_active, sl.created_at, sl.updated_at
             FROM service_lines sl
             INNER JOIN user_service_lines usl ON usl.service_line_id = sl.id
             WHERE usl.user_id = :user_id
             ORDER BY sl.sort_order, sl.name'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(
            static fn (array $row) => ServiceLine::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function getPrimaryForUser(int $userId): ?ServiceLine
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT sl.id, sl.slug, sl.name, sl.description, sl.icon, sl.sort_order,
                    sl.is_active, sl.created_at, sl.updated_at
             FROM service_lines sl
             INNER JOIN users u ON u.primary_service_line_id = sl.id
             WHERE u.id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ServiceLine::fromRow($row) : null;
    }

    /**
     * Set the user's primary line. Requires the user to already be a member
     * of that line (assignUser must have been called first). This mirrors
     * the FK invariant we want to enforce at the application level.
     */
    public function setPrimaryForUser(int $userId, int $serviceLineId): void
    {
        $check = $this->connection->pdo()->prepare(
            'SELECT 1 FROM user_service_lines
             WHERE user_id = :user_id AND service_line_id = :service_line_id
             LIMIT 1'
        );
        $check->execute([
            'user_id' => $userId,
            'service_line_id' => $serviceLineId,
        ]);

        if ($check->fetchColumn() === false) {
            throw new InvalidArgumentException(
                "User {$userId} is not a member of service line {$serviceLineId}"
            );
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users SET primary_service_line_id = :service_line_id WHERE id = :user_id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'service_line_id' => $serviceLineId,
        ]);
    }

    /**
     * Idempotent membership grant. Safe to call repeatedly.
     */
    public function assignUser(int $userId, int $serviceLineId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT IGNORE INTO user_service_lines (user_id, service_line_id)
             VALUES (:user_id, :service_line_id)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'service_line_id' => $serviceLineId,
        ]);
    }

    /**
     * Remove membership; if the removed line was the user's primary, clear it.
     */
    public function unassignUser(int $userId, int $serviceLineId): void
    {
        $delete = $this->connection->pdo()->prepare(
            'DELETE FROM user_service_lines
             WHERE user_id = :user_id AND service_line_id = :service_line_id'
        );
        $delete->execute([
            'user_id' => $userId,
            'service_line_id' => $serviceLineId,
        ]);

        $clearPrimary = $this->connection->pdo()->prepare(
            'UPDATE users
                SET primary_service_line_id = NULL
              WHERE id = :user_id AND primary_service_line_id = :service_line_id'
        );
        $clearPrimary->execute([
            'user_id' => $userId,
            'service_line_id' => $serviceLineId,
        ]);
    }
}
