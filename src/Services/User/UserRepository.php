<?php

namespace App\Services\User;

use App\Database\Connection;
use App\Models\User;
use PDO;

class UserRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * List users by role
     *
     * @return array<int, User>
     */
    public function listByRole(string $role): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, email, role, created_at, updated_at
             FROM users
             WHERE role = :role
             ORDER BY name ASC'
        );
        $stmt->execute(['role' => $role]);

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = new User($row);
        }

        return $results;
    }

    /**
     * Search users by role and query
     *
     * @return array<int, User>
     */
    public function searchByRole(string $role, string $query, int $limit = 10): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, email, role, created_at, updated_at
             FROM users
             WHERE role = :role
             AND (id = :exact_id OR name LIKE :query OR email LIKE :query)
             ORDER BY name ASC
             LIMIT :limit'
        );

        $stmt->bindValue(':role', $role);
        $stmt->bindValue(':exact_id', is_numeric($query) ? (int) $query : 0, PDO::PARAM_INT);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = new User($row);
        }

        return $results;
    }
}
