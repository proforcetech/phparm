<?php

namespace App\Services\NotFound;

use App\Database\Connection;
use App\Models\Redirect;
use PDO;

class RedirectRepository
{
    private Connection $connection;
    private bool $checkedRedirectTable = false;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->ensureRedirectsTable();
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

        $searchTerm = $this->normalizeSearchTerm($filters['search'] ?? null);
        if ($searchTerm !== null) {
            $clauses[] = '(source_path LIKE :search OR destination_path LIKE :search OR description LIKE :search)';
            $bindings['search'] = '%' . $searchTerm . '%';
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

        $searchTerm = $this->normalizeSearchTerm($filters['search'] ?? null);
        if ($searchTerm !== null) {
            $clauses[] = '(source_path LIKE :search OR destination_path LIKE :search OR description LIKE :search)';
            $bindings['search'] = '%' . $searchTerm . '%';
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

    private function normalizeSearchTerm(mixed $search): ?string
    {
        if (is_array($search)) {
            $search = $search[0] ?? null;
        }

        if ($search === null) {
            return null;
        }

        $search = trim((string) $search);

        return $search === '' ? null : $search;
    }

    private function ensureRedirectsTable(): void
    {
        if ($this->checkedRedirectTable) {
            return;
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->query("SHOW TABLES LIKE 'redirects'");
        $tableExists = $stmt !== false && $stmt->fetchColumn() !== false;

        if (!$tableExists) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS redirects (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    source_path VARCHAR(512) NOT NULL COMMENT 'Original path to redirect from',
                    destination_path VARCHAR(512) NOT NULL COMMENT 'Target path to redirect to',
                    redirect_type ENUM('301', '302', '307', '308') NOT NULL DEFAULT '301' COMMENT 'HTTP redirect status code',
                    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether redirect is enabled',
                    match_type ENUM('exact', 'prefix', 'regex') NOT NULL DEFAULT 'exact' COMMENT 'How to match source path',
                    description VARCHAR(255) NULL COMMENT 'Optional note about this redirect',
                    hits INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of times this redirect has been used',
                    created_by INT UNSIGNED NULL COMMENT 'User ID who created this redirect',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                    UNIQUE KEY unique_source (source_path(255)),
                    INDEX idx_source_path (source_path(255)),
                    INDEX idx_is_active (is_active),
                    INDEX idx_match_type (match_type),

                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='URL redirect rules for SEO and fixing broken links'
            ");
        }

        $this->checkedRedirectTable = true;
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
