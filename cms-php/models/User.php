<?php
/**
 * User Model
 * Manages user authentication and administration
 * FixItForUs CMS
 */

namespace CMS\Models;

use CMS\Config\Database;

class User
{
    private Database $db;
    private string $table;

    // User roles
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_VIEWER = 'viewer';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->table = $this->db->prefix('users');
    }

    /**
     * Get all users
     */
    public function getAll(): array
    {
        $sql = "SELECT id, username, email, role, is_active, last_login, created_at
                FROM {$this->table}
                ORDER BY username";
        return $this->db->query($sql);
    }

    /**
     * Get user by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT id, username, email, role, is_active, last_login, created_at, updated_at
                FROM {$this->table}
                WHERE id = ?";
        return $this->db->queryOne($sql, [$id]);
    }

    /**
     * Get user by username
     */
    public function getByUsername(string $username): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE username = ?";
        return $this->db->queryOne($sql, [$username]);
    }

    /**
     * Get user by email
     */
    public function getByEmail(string $email): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE email = ?";
        return $this->db->queryOne($sql, [$email]);
    }

    /**
     * Authenticate user
     */
    public function authenticate(string $username, string $password): ?array
    {
        $user = $this->getByUsername($username);

        if (!$user) {
            return null;
        }

        if (!$user['is_active']) {
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Update last login
        $this->updateLastLogin($user['id']);

        // Remove sensitive data before returning
        unset($user['password_hash']);

        return $user;
    }

    /**
     * Login user and start session
     */
    public function login(string $username, string $password): bool
    {
        $user = $this->authenticate($username, $password);

        if (!$user) {
            $this->logActivity(null, 'login_failed', 'user', null, null, ['username' => $username]);
            return false;
        }

        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['logged_in_at'] = time();

        // Regenerate session ID for security
        session_regenerate_id(true);

        // Log activity
        $this->logActivity($user['id'], 'login', 'user', $user['id']);

        return true;
    }

    /**
     * Logout user
     */
    public function logout(): void
    {
        $userId = $_SESSION['user_id'] ?? null;

        if ($userId) {
            $this->logActivity($userId, 'logout', 'user', $userId);
        }

        // Clear session
        $_SESSION = [];

        // Destroy session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }

        // Destroy session
        session_destroy();
    }

    /**
     * Create new user
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO {$this->table}
                (username, email, password_hash, role, is_active)
                VALUES (?, ?, ?, ?, ?)";

        $params = [
            $data['username'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['role'] ?? self::ROLE_EDITOR,
            $data['is_active'] ?? 1,
        ];

        $this->db->execute($sql, $params);
        $userId = (int) $this->db->lastInsertId();

        $this->logActivity(currentUserId(), 'create', 'user', $userId);

        return $userId;
    }

    /**
     * Update user
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];
        $oldValues = $this->getById($id);

        if (isset($data['username'])) {
            $fields[] = "username = ?";
            $params[] = $data['username'];
        }

        if (isset($data['email'])) {
            $fields[] = "email = ?";
            $params[] = $data['email'];
        }

        if (isset($data['password']) && !empty($data['password'])) {
            $fields[] = "password_hash = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        if (isset($data['role'])) {
            $fields[] = "role = ?";
            $params[] = $data['role'];
        }

        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $params[] = $data['is_active'];
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = ?";
        $result = $this->db->execute($sql, $params) > 0;

        if ($result) {
            $this->logActivity(currentUserId(), 'update', 'user', $id, $oldValues, $data);
        }

        return $result;
    }

    /**
     * Delete user
     */
    public function delete(int $id): bool
    {
        // Cannot delete the last admin
        if ($this->isLastAdmin($id)) {
            return false;
        }

        $oldValues = $this->getById($id);

        $sql = "DELETE FROM {$this->table} WHERE id = ?";
        $result = $this->db->execute($sql, [$id]) > 0;

        if ($result) {
            $this->logActivity(currentUserId(), 'delete', 'user', $id, $oldValues);
        }

        return $result;
    }

    /**
     * Check if user is the last admin
     */
    private function isLastAdmin(int $userId): bool
    {
        $user = $this->getById($userId);

        if (!$user || $user['role'] !== self::ROLE_ADMIN) {
            return false;
        }

        $result = $this->db->queryOne(
            "SELECT COUNT(*) as count FROM {$this->table} WHERE role = 'admin' AND id != ?",
            [$userId]
        );

        return ($result['count'] ?? 0) == 0;
    }

    /**
     * Toggle active status
     */
    public function toggleActive(int $id): bool
    {
        // Cannot deactivate the last admin
        if ($this->isLastAdmin($id)) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET is_active = NOT is_active WHERE id = ?";
        return $this->db->execute($sql, [$id]) > 0;
    }

    /**
     * Update last login time
     */
    private function updateLastLogin(int $id): void
    {
        $sql = "UPDATE {$this->table} SET last_login = NOW() WHERE id = ?";
        $this->db->execute($sql, [$id]);
    }

    /**
     * Change password
     */
    public function changePassword(int $id, string $currentPassword, string $newPassword): bool
    {
        $user = $this->db->queryOne(
            "SELECT password_hash FROM {$this->table} WHERE id = ?",
            [$id]
        );

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET password_hash = ? WHERE id = ?";
        return $this->db->execute($sql, [password_hash($newPassword, PASSWORD_DEFAULT), $id]) > 0;
    }

    /**
     * Reset password (admin only)
     */
    public function resetPassword(int $id, string $newPassword): bool
    {
        $sql = "UPDATE {$this->table} SET password_hash = ? WHERE id = ?";
        $result = $this->db->execute($sql, [password_hash($newPassword, PASSWORD_DEFAULT), $id]) > 0;

        if ($result) {
            $this->logActivity(currentUserId(), 'password_reset', 'user', $id);
        }

        return $result;
    }

    /**
     * Check if username exists
     */
    public function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE username = ?";
        $params = [$username];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->queryOne($sql, $params);
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Check if email exists
     */
    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE email = ?";
        $params = [$email];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->queryOne($sql, $params);
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Get available roles
     */
    public static function getRoles(): array
    {
        return [
            self::ROLE_ADMIN => 'Administrator',
            self::ROLE_EDITOR => 'Editor',
            self::ROLE_VIEWER => 'Viewer',
        ];
    }

    /**
     * Count users
     */
    public function count(bool $activeOnly = false): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $result = $this->db->queryOne($sql);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Log user activity
     */
    private function logActivity(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $sql = "INSERT INTO activity_log
                (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        // Remove sensitive data from values
        if ($oldValues) {
            unset($oldValues['password_hash']);
        }
        if ($newValues) {
            unset($newValues['password'], $newValues['password_hash']);
        }

        $this->db->execute($sql, [
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    /**
     * Get activity log for a user
     */
    public function getActivityLog(int $userId, int $limit = 50): array
    {
        $sql = "SELECT * FROM activity_log
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?";
        return $this->db->query($sql, [$userId, $limit]);
    }
}
