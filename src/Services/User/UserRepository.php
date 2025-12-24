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

    /**
     * List all users with optional filters
     *
     * @param array<string, mixed> $filters
     * @return array<int, User>
     */
    public function list(array $filters = []): array
    {
        $query = 'SELECT id, name, email, role, email_verified, two_factor_enabled, two_factor_type, two_factor_setup_pending, created_at, updated_at FROM users WHERE 1=1';
        $bindings = [];

        if (!empty($filters['role'])) {
            $query .= ' AND role = :role';
            $bindings['role'] = $filters['role'];
        }

        if (!empty($filters['query'])) {
            $query .= ' AND (id = :exact_id OR name LIKE :query OR email LIKE :query)';
            $bindings['exact_id'] = is_numeric($filters['query']) ? (int) $filters['query'] : 0;
            $bindings['query'] = '%' . $filters['query'] . '%';
        }

        $query .= ' ORDER BY created_at DESC';

        $stmt = $this->connection->pdo()->prepare($query);
        $stmt->execute($bindings);

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = new User($row);
        }

        return $results;
    }

    /**
     * Find a user by ID
     */
    public function find(int $id): ?User
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, email, role, email_verified, two_factor_enabled, two_factor_type, two_factor_setup_pending, created_at, updated_at
             FROM users
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return new User($row);
    }

    /**
     * Find a user by email
     */
    public function findByEmail(string $email): ?User
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, email, role, email_verified, two_factor_enabled, two_factor_type, two_factor_setup_pending, created_at, updated_at
             FROM users
             WHERE email = :email'
        );
        $stmt->execute(['email' => $email]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return new User($row);
    }

    /**
     * Create a new user
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): User
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO users (name, email, password, role, email_verified, two_factor_enabled, two_factor_type, created_at, updated_at)
             VALUES (:name, :email, :password, :role, :email_verified, :two_factor_enabled, :two_factor_type, NOW(), NOW())'
        );

        $stmt->execute([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // Should be hashed before calling
            'role' => $data['role'] ?? 'customer',
            'email_verified' => $data['email_verified'] ?? false,
            'two_factor_enabled' => $data['two_factor_enabled'] ?? false,
            'two_factor_type' => $data['two_factor_type'] ?? 'none',
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id);
    }

    /**
     * Update a user
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): User
    {
        $fields = [];
        $bindings = ['id' => $id];

        if (isset($data['name'])) {
            $fields[] = 'name = :name';
            $bindings['name'] = $data['name'];
        }

        if (isset($data['email'])) {
            $fields[] = 'email = :email';
            $bindings['email'] = $data['email'];
        }

        if (isset($data['password'])) {
            $fields[] = 'password = :password';
            $bindings['password'] = $data['password']; // Should be hashed before calling
        }

        if (isset($data['role'])) {
            $fields[] = 'role = :role';
            $bindings['role'] = $data['role'];
        }

        if (isset($data['email_verified'])) {
            $fields[] = 'email_verified = :email_verified';
            $bindings['email_verified'] = $data['email_verified'];
        }

if (isset($data['two_factor_enabled'])) {
        $data['two_factor_enabled'] = $data['two_factor_enabled'] ? 1 : 0;
    } else {
        // If it's missing from the request (common with unchecked checkboxes), default to 0
        $data['two_factor_enabled'] = 0;
    }
        if (isset($data['two_factor_type'])) {
            $fields[] = 'two_factor_type = :two_factor_type';
            $bindings['two_factor_type'] = $data['two_factor_type'];
        }

        if (isset($data['two_factor_setup_pending'])) {
            $fields[] = 'two_factor_setup_pending = :two_factor_setup_pending';
            $bindings['two_factor_setup_pending'] = $data['two_factor_setup_pending'];
        }

        if (empty($fields)) {
            return $this->find($id);
        }

        $fields[] = 'updated_at = NOW()';

        $query = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($query);
        $stmt->execute($bindings);

        return $this->find($id);
    }

    /**
     * Delete a user
     */
    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Reset 2FA for a user
     */
    public function reset2FA(int $id): User
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users SET two_factor_enabled = FALSE, two_factor_secret = NULL, two_factor_recovery_codes = NULL, two_factor_setup_pending = FALSE, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return $this->find($id);
    }

    /**
     * Mark 2FA setup as pending for a user
     */
    public function requireTwoFactorSetup(int $id): User
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users SET two_factor_setup_pending = TRUE, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return $this->find($id);
    }

    /**
     * Complete 2FA setup for a user
     *
     * @param array<int, string> $recoveryCodes
     */
    public function completeTwoFactorSetup(int $id, string $secret, array $recoveryCodes): User
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users
             SET two_factor_enabled = TRUE,
                 two_factor_type = \'totp\',
                 two_factor_secret = :secret,
                 two_factor_recovery_codes = :recovery_codes,
                 two_factor_setup_pending = FALSE,
                 updated_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'secret' => $secret,
            'recovery_codes' => json_encode($recoveryCodes)
        ]);

        return $this->find($id);
    }
}
