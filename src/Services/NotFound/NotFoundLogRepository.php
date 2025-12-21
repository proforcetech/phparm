<?php

namespace App\Services\NotFound;

use App\Database\Connection;
use App\Models\NotFoundLog;
use PDO;

class NotFoundLogRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Log a 404 error or increment hit count if URI already exists
     *
     * @param string $uri
     * @param string|null $referrer
     * @param string|null $userAgent
     * @param string|null $ipAddress
     * @return NotFoundLog
     */
    public function log(string $uri, ?string $referrer, ?string $userAgent, ?string $ipAddress): NotFoundLog
    {
        // Check if this URI already exists
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM not_found_logs WHERE uri = :uri LIMIT 1'
        );
        $stmt->execute(['uri' => $uri]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing log: increment hits and update last_seen
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE not_found_logs
                 SET hits = hits + 1,
                     last_seen = NOW(),
                     referrer = :referrer,
                     user_agent = :user_agent,
                     ip_address = :ip_address,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $existing['id'],
                'referrer' => $referrer,
                'user_agent' => $userAgent,
                'ip_address' => $ipAddress,
            ]);

            return $this->find((int) $existing['id']);
        }

        // Create new log entry
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO not_found_logs (uri, referrer, user_agent, ip_address, first_seen, last_seen, hits, created_at, updated_at)
             VALUES (:uri, :referrer, :user_agent, :ip_address, NOW(), NOW(), 1, NOW(), NOW())'
        );
        $stmt->execute([
            'uri' => $uri,
            'referrer' => $referrer,
            'user_agent' => $userAgent,
            'ip_address' => $ipAddress,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id);
    }

    /**
     * Find a 404 log by ID
     *
     * @param int $id
     * @return NotFoundLog|null
     */
    public function find(int $id): ?NotFoundLog
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM not_found_logs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapNotFoundLog($row) : null;
    }

    /**
     * List all 404 logs with pagination and sorting
     *
     * @param array<string, mixed> $filters
     * @param int $limit
     * @param int $offset
     * @return array<int, NotFoundLog>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = [];
        $bindings = [];

        if (!empty($filters['uri'])) {
            $clauses[] = 'uri LIKE :uri';
            // Handle case where uri might be an array
            $uri = is_array($filters['uri']) ? implode('', $filters['uri']) : $filters['uri'];
            $bindings['uri'] = '%' . $uri . '%';
        }

        if (!empty($filters['min_hits'])) {
            $clauses[] = 'hits >= :min_hits';
            $bindings['min_hits'] = (int) $filters['min_hits'];
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $orderBy = isset($filters['sort']) && $filters['sort'] === 'uri' ? 'uri ASC' : 'hits DESC, last_seen DESC';

        $sql = "SELECT * FROM not_found_logs {$where} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset";
        $stmt = $this->connection->pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($row) => $this->mapNotFoundLog($row), $rows);
    }

    /**
     * Count total 404 logs
     *
     * @param array<string, mixed> $filters
     * @return int
     */
    public function count(array $filters = []): int
    {
        $clauses = [];
        $bindings = [];

        if (!empty($filters['uri'])) {
            $clauses[] = 'uri LIKE :uri';
            // Handle case where uri might be an array
            $uri = is_array($filters['uri']) ? implode('', $filters['uri']) : $filters['uri'];
            $bindings['uri'] = '%' . $uri . '%';
        }

        if (!empty($filters['min_hits'])) {
            $clauses[] = 'hits >= :min_hits';
            $bindings['min_hits'] = (int) $filters['min_hits'];
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $sql = "SELECT COUNT(*) FROM not_found_logs {$where}";
        $stmt = $this->connection->pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Clear all 404 logs
     *
     * @return int Number of logs deleted
     */
    public function clearAll(): int
    {
        $stmt = $this->connection->pdo()->query('SELECT COUNT(*) FROM not_found_logs');
        $count = (int) $stmt->fetchColumn();

        $this->connection->pdo()->exec('DELETE FROM not_found_logs');

        return $count;
    }

    /**
     * Delete a specific 404 log
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM not_found_logs WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Get statistics about 404 logs
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT
                COUNT(*) as total_unique_uris,
                SUM(hits) as total_hits,
                MAX(hits) as max_hits,
                AVG(hits) as avg_hits
             FROM not_found_logs'
        );

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function mapNotFoundLog(array $row): NotFoundLog
    {
        return new NotFoundLog([
            'id' => (int) $row['id'],
            'uri' => (string) $row['uri'],
            'referrer' => $row['referrer'],
            'user_agent' => $row['user_agent'],
            'ip_address' => $row['ip_address'],
            'first_seen' => $row['first_seen'],
            'last_seen' => $row['last_seen'],
            'hits' => (int) $row['hits'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
    }
}
