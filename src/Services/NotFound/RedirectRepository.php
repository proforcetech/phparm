<?php

namespace App\Services\NotFound;

use App\Database\Connection;
use App\Models\Redirect;
use PDO;

class RedirectRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Find a redirect by ID
     *
     * @param int $id
     * @return Redirect|null
     */
    public function find(int $id): ?Redirect
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM redirects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapRedirect($row) : null;
    }

    /**
     * Find an active redirect that matches the given path
     *
     * @param string $path
     * @return Redirect|null
     */
    public function findMatch(string $path): ?Redirect
    {
        // Get all active redirects ordered by match type priority (exact > prefix > regex)
        $stmt = $this->connection->pdo()->query(
            "SELECT * FROM redirects
             WHERE is_active = 1
             ORDER BY FIELD(match_type, 'exact', 'prefix', 'regex')"
        );

        $redirects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($redirects as $row) {
            $matchType = $row['match_type'];
            $sourcePath = $row['source_path'];

            $matches = false;

            switch ($matchType) {
                case 'exact':
                    $matches = $path === $sourcePath;
                    break;

                case 'prefix':
                    $matches = str_starts_with($path, $sourcePath);
                    break;

                case 'regex':
                    try {
                        $matches = @preg_match($sourcePath, $path) === 1;
                    } catch (\Throwable $e) {
                        error_log("Invalid regex in redirect {$row['id']}: {$sourcePath}");
                    }
                    break;
            }

            if ($matches) {
                return $this->mapRedirect($row);
            }
        }

        return null;
    }

    /**
     * Create a new redirect
     *
     * @param array<string, mixed> $data
     * @return Redirect
     */
    public function create(array $data): Redirect
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO redirects (source_path, destination_path, redirect_type, is_active, match_type, description, created_by, created_at, updated_at)
             VALUES (:source_path, :destination_path, :redirect_type, :is_active, :match_type, :description, :created_by, NOW(), NOW())'
        );

        $stmt->execute([
            'source_path' => $data['source_path'],
            'destination_path' => $data['destination_path'],
            'redirect_type' => $data['redirect_type'] ?? '301',
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'match_type' => $data['match_type'] ?? 'exact',
            'description' => $data['description'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id);
    }

    /**
     * Update a redirect
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return Redirect|null
     */
    public function update(int $id, array $data): ?Redirect
    {
        $updates = [];
        $bindings = ['id' => $id];

        if (isset($data['source_path'])) {
            $updates[] = 'source_path = :source_path';
            $bindings['source_path'] = $data['source_path'];
        }

        if (isset($data['destination_path'])) {
            $updates[] = 'destination_path = :destination_path';
            $bindings['destination_path'] = $data['destination_path'];
        }

        if (isset($data['redirect_type'])) {
            $updates[] = 'redirect_type = :redirect_type';
            $bindings['redirect_type'] = $data['redirect_type'];
        }

        if (isset($data['is_active'])) {
            $updates[] = 'is_active = :is_active';
            $bindings['is_active'] = (int) $data['is_active'];
        }

        if (isset($data['match_type'])) {
            $updates[] = 'match_type = :match_type';
            $bindings['match_type'] = $data['match_type'];
        }

        if (isset($data['description'])) {
            $updates[] = 'description = :description';
            $bindings['description'] = $data['description'];
        }

        if (empty($updates)) {
            return $this->find($id);
        }

        $updates[] = 'updated_at = NOW()';
        $sql = 'UPDATE redirects SET ' . implode(', ', $updates) . ' WHERE id = :id';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($bindings);

        return $this->find($id);
    }

    /**
     * Delete a redirect
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM redirects WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Increment hit count for a redirect
     *
     * @param int $id
     * @return void
     */
    public function incrementHits(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE redirects SET hits = hits + 1, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * List all redirects with pagination
     *
     * @param array<string, mixed> $filters
     * @param int $limit
     * @param int $offset
     * @return array<int, Redirect>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = [];
        $bindings = [];

        if (isset($filters['is_active'])) {
            $clauses[] = 'is_active = :is_active';
            $bindings['is_active'] = (int) $filters['is_active'];
        }

        if (!empty($filters['search'])) {
            $clauses[] = '(source_path LIKE :search OR destination_path LIKE :search OR description LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $sql = "SELECT * FROM redirects {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->connection->pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($row) => $this->mapRedirect($row), $rows);
    }

    /**
     * Count total redirects
     *
     * @param array<string, mixed> $filters
     * @return int
     */
    public function count(array $filters = []): int
    {
        $clauses = [];
        $bindings = [];

        if (isset($filters['is_active'])) {
            $clauses[] = 'is_active = :is_active';
            $bindings['is_active'] = (int) $filters['is_active'];
        }

        if (!empty($filters['search'])) {
            $clauses[] = '(source_path LIKE :search OR destination_path LIKE :search OR description LIKE :search)';
            $bindings['search'] = '%' . $filters['search'] . '%';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $sql = "SELECT COUNT(*) FROM redirects {$where}";

        $stmt = $this->connection->pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    private function mapRedirect(array $row): Redirect
    {
        return new Redirect([
            'id' => (int) $row['id'],
            'source_path' => (string) $row['source_path'],
            'destination_path' => (string) $row['destination_path'],
            'redirect_type' => (string) $row['redirect_type'],
            'is_active' => (bool) $row['is_active'],
            'match_type' => (string) $row['match_type'],
            'description' => $row['description'],
            'hits' => (int) $row['hits'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
    }
}
