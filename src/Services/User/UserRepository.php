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
    public function listByRole(string $role, ?int $branchId = null): array
    {
        $conditions = ['role = :role', 'active = 1'];
        $bindings = ['role' => $role];

        if ($branchId !== null) {
            $conditions[] = 'branch_id = :branch_id';
            $bindings['branch_id'] = $branchId;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, email, role, active, branch_id, created_at, updated_at, last_activity_at
             FROM users
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY name ASC'
        );
        $stmt->execute($bindings);

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
    public function searchByRole(string $role, string $query, int $limit = 10, ?int $branchId = null): array
    {
        $conditions = ['role = :role', 'active = 1'];
        if ($branchId !== null) {
            $conditions[] = 'branch_id = :branch_id';
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, email, role, active, branch_id, created_at, updated_at, last_activity_at
             FROM users
             WHERE ' . implode(' AND ', $conditions) . '
             AND (id = :exact_id OR name LIKE :query OR email LIKE :query)
             ORDER BY name ASC
             LIMIT :limit'
        );

        $stmt->bindValue(':role', $role);
        if ($branchId !== null) {
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        }
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
        $query = 'SELECT id, name, email, role, active, branch_id, email_verified, two_factor_enabled, two_factor_type, two_factor_setup_pending, created_at, updated_at, last_activity_at FROM users WHERE 1 = 1';
        $bindings = [];

        $status = $filters['status'] ?? '';
        if ($status === 'inactive') {
            $query .= ' AND active = :active';
            $bindings['active'] = 0;
        } elseif ($status !== 'all') {
            $query .= ' AND active = :active';
            $bindings['active'] = 1;
        }

        if (!empty($filters['role'])) {
            $query .= ' AND role = :role';
            $bindings['role'] = $filters['role'];
        }

        if (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== '' && $filters['branch_id'] !== null) {
            $query .= ' AND branch_id = :branch_id';
            $bindings['branch_id'] = (int) $filters['branch_id'];
        }

        if (!empty($filters['query'])) {
            $query .= ' AND (id = :exact_id OR name LIKE :query OR email LIKE :query)';
            $bindings['exact_id'] = is_numeric($filters['query']) ? (int) $filters['query'] : 0;
            $bindings['query'] = '%' . $filters['query'] . '%';
        }

        if (!empty($filters['two_factor'])) {
            $twoFactorMap = [
                'enabled' => 1,
                'disabled' => 0,
            ];
            if (array_key_exists($filters['two_factor'], $twoFactorMap)) {
                $query .= ' AND two_factor_enabled = :two_factor_enabled';
                $bindings['two_factor_enabled'] = $twoFactorMap[$filters['two_factor']];
            }
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
            'SELECT id, name, email, role, active, branch_id, email_verified, two_factor_enabled, two_factor_type, two_factor_setup_pending, created_at, updated_at, last_activity_at
             FROM users
             WHERE id = :id AND active = 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return new User($row);
    }

    public function findWithPassword(int $id): ?User
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM users WHERE id = :id AND active = 1 LIMIT 1'
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
            'SELECT id, name, email, role, active, branch_id, email_verified, two_factor_enabled, two_factor_type, two_factor_setup_pending, created_at, updated_at, last_activity_at
             FROM users
             WHERE email = :email AND active = 1'
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
            'INSERT INTO users (name, email, password, role, email_verified, active, branch_id, two_factor_enabled, two_factor_type, created_at, updated_at)
             VALUES (:name, :email, :password, :role, :email_verified, :active, :branch_id, :two_factor_enabled, :two_factor_type, NOW(), NOW())'
        );

        $stmt->execute([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // Should be hashed before calling
            'role' => $data['role'] ?? 'customer',
            'email_verified' => $data['email_verified'] ?? false,
            'active' => $data['active'] ?? true,
            'branch_id' => array_key_exists('branch_id', $data) ? $data['branch_id'] : null,
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

        if (array_key_exists('branch_id', $data)) {
            $fields[] = 'branch_id = :branch_id';
            $bindings['branch_id'] = $data['branch_id'];
        }

        if (isset($data['email_verified'])) {
            $fields[] = 'email_verified = :email_verified';
            $bindings['email_verified'] = $data['email_verified'];
        }

        if (isset($data['active'])) {
            $fields[] = 'active = :active';
            $bindings['active'] = $data['active'];
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
        $stmt = $this->connection->pdo()->prepare('UPDATE users SET active = 0, updated_at = NOW() WHERE id = :id');
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
     * @param array<int, string> $recoveryCodes Hashed recovery codes
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

    /**
     * Get recovery code hashes for a user.
     *
     * @return array<int, string> Array of hashed recovery codes
     */
    public function getRecoveryCodeHashes(int $id): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT two_factor_recovery_codes FROM users WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['two_factor_recovery_codes'])) {
            return [];
        }

        $codes = json_decode($row['two_factor_recovery_codes'], true);
        return is_array($codes) ? $codes : [];
    }

    /**
     * Remove a used recovery code by its index.
     *
     * @param int $id User ID
     * @param int $codeIndex Index of the code to remove
     * @return int Number of remaining codes
     */
    public function consumeRecoveryCode(int $id, int $codeIndex): int
    {
        $codes = $this->getRecoveryCodeHashes($id);

        if (!isset($codes[$codeIndex])) {
            return count($codes);
        }

        // Remove the used code
        array_splice($codes, $codeIndex, 1);

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users SET two_factor_recovery_codes = :codes, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'codes' => json_encode(array_values($codes))
        ]);

        return count($codes);
    }

    /**
     * Regenerate recovery codes for a user.
     *
     * @param array<int, string> $newCodeHashes New hashed recovery codes
     */
    public function regenerateRecoveryCodes(int $id, array $newCodeHashes): User
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users SET two_factor_recovery_codes = :codes, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'codes' => json_encode($newCodeHashes)
        ]);

        return $this->find($id);
    }

    /**
     * Get count of remaining recovery codes.
     */
    public function getRecoveryCodeCount(int $id): int
    {
        return count($this->getRecoveryCodeHashes($id));
    }

    /**
     * Bulk deactivate users.
     *
     * @param array<int, int> $ids
     */
    public function bulkDeactivate(array $ids, int $currentUserId): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static fn ($id) => $id > 0 && $id !== $currentUserId));

        if (empty($ids)) {
            return 0;
        }

        $placeholders = [];
        $bindings = [];
        foreach ($ids as $index => $id) {
            $key = 'id' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $id;
        }

        $query = 'UPDATE users SET active = 0, updated_at = NOW() WHERE id IN (' . implode(',', $placeholders) . ')';
        $stmt = $this->connection->pdo()->prepare($query);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    /**
     * Bulk update user roles.
     *
     * @param array<int, int> $ids
     */
    public function bulkUpdateRole(array $ids, string $role): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static fn ($id) => $id > 0));

        if (empty($ids)) {
            return 0;
        }

        $placeholders = [];
        $bindings = ['role' => $role];
        foreach ($ids as $index => $id) {
            $key = 'id' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $id;
        }

        $query = 'UPDATE users SET role = :role, updated_at = NOW() WHERE id IN (' . implode(',', $placeholders) . ') AND active = 1';
        $stmt = $this->connection->pdo()->prepare($query);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }
}
