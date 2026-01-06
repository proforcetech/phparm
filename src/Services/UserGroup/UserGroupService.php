<?php

namespace App\Services\UserGroup;

use App\Database\Connection;
use PDO;

/**
 * Service for managing user groups
 *
 * User groups provide additional permission layering beyond roles,
 * allowing for module restrictions and granular permission grants.
 */
class UserGroupService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * List all user groups
     *
     * @return array<int, array>
     */
    public function list(): array
    {
        $stmt = $this->connection->pdo()->query('
            SELECT g.*,
                   (SELECT COUNT(*) FROM user_group_members WHERE group_id = g.id) as member_count
            FROM user_groups g
            ORDER BY g.is_default DESC, g.name ASC
        ');

        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($group) {
            return $this->formatGroup($group);
        }, $groups);
    }

    /**
     * Get a single group by ID
     */
    public function find(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('
            SELECT g.*,
                   (SELECT COUNT(*) FROM user_group_members WHERE group_id = g.id) as member_count
            FROM user_groups g
            WHERE g.id = ?
        ');
        $stmt->execute([$id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($group === false) {
            return null;
        }

        return $this->formatGroup($group);
    }

    /**
     * Create a new user group
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data, ?int $createdBy = null): array
    {
        $pdo = $this->connection->pdo();

        $stmt = $pdo->prepare('
            INSERT INTO user_groups (name, description, permissions, disabled_modules, is_default, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ');

        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            json_encode($data['permissions'] ?? []),
            json_encode($data['disabled_modules'] ?? []),
            $data['is_default'] ?? false ? 1 : 0,
            $createdBy,
        ]);

        $id = (int) $pdo->lastInsertId();

        $this->logAction('group_create', null, $id, null, $createdBy, null, $data);

        return $this->find($id);
    }

    /**
     * Update a user group
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data, ?int $updatedBy = null): ?array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $pdo = $this->connection->pdo();

        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $params[] = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $fields[] = 'description = ?';
            $params[] = $data['description'];
        }

        if (isset($data['permissions'])) {
            $fields[] = 'permissions = ?';
            $params[] = json_encode($data['permissions']);
        }

        if (isset($data['disabled_modules'])) {
            $fields[] = 'disabled_modules = ?';
            $params[] = json_encode($data['disabled_modules']);
        }

        if (isset($data['is_default'])) {
            $fields[] = 'is_default = ?';
            $params[] = $data['is_default'] ? 1 : 0;
        }

        if (empty($fields)) {
            return $existing;
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $id;

        $stmt = $pdo->prepare('UPDATE user_groups SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);

        $this->logAction('group_update', null, $id, null, $updatedBy, $existing, $data);

        return $this->find($id);
    }

    /**
     * Delete a user group
     */
    public function delete(int $id, ?int $deletedBy = null): bool
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return false;
        }

        $pdo = $this->connection->pdo();

        // Remove all members first (handled by FK cascade, but let's be explicit)
        $stmt = $pdo->prepare('DELETE FROM user_group_members WHERE group_id = ?');
        $stmt->execute([$id]);

        // Delete the group
        $stmt = $pdo->prepare('DELETE FROM user_groups WHERE id = ?');
        $result = $stmt->execute([$id]);

        if ($result) {
            $this->logAction('group_delete', null, $id, null, $deletedBy, $existing, null);
        }

        return $result;
    }

    /**
     * Get members of a group
     *
     * @return array<int, array>
     */
    public function getMembers(int $groupId): array
    {
        $stmt = $this->connection->pdo()->prepare('
            SELECT u.id, u.name, u.email, u.role, m.added_at, m.added_by
            FROM users u
            INNER JOIN user_group_members m ON u.id = m.user_id
            WHERE m.group_id = ?
            ORDER BY u.name ASC
        ');
        $stmt->execute([$groupId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add a user to a group
     */
    public function addMember(int $groupId, int $userId, ?int $addedBy = null): bool
    {
        $pdo = $this->connection->pdo();

        // Check if already a member
        $stmt = $pdo->prepare('SELECT 1 FROM user_group_members WHERE group_id = ? AND user_id = ?');
        $stmt->execute([$groupId, $userId]);
        if ($stmt->fetch()) {
            return true; // Already a member
        }

        $stmt = $pdo->prepare('
            INSERT INTO user_group_members (group_id, user_id, added_by, added_at)
            VALUES (?, ?, ?, NOW())
        ');
        $result = $stmt->execute([$groupId, $userId, $addedBy]);

        if ($result) {
            $this->logAction('member_add', null, $groupId, $userId, $addedBy, null, null);
        }

        return $result;
    }

    /**
     * Remove a user from a group
     */
    public function removeMember(int $groupId, int $userId, ?int $removedBy = null): bool
    {
        $stmt = $this->connection->pdo()->prepare('
            DELETE FROM user_group_members WHERE group_id = ? AND user_id = ?
        ');
        $result = $stmt->execute([$groupId, $userId]);

        if ($result) {
            $this->logAction('member_remove', null, $groupId, $userId, $removedBy, null, null);
        }

        return $result;
    }

    /**
     * Set all members of a group (replaces existing members)
     *
     * @param int[] $userIds
     */
    public function setMembers(int $groupId, array $userIds, ?int $updatedBy = null): bool
    {
        $pdo = $this->connection->pdo();

        // Get current members for logging
        $currentMembers = array_column($this->getMembers($groupId), 'id');

        // Remove all current members
        $stmt = $pdo->prepare('DELETE FROM user_group_members WHERE group_id = ?');
        $stmt->execute([$groupId]);

        // Add new members
        if (!empty($userIds)) {
            $stmt = $pdo->prepare('
                INSERT INTO user_group_members (group_id, user_id, added_by, added_at)
                VALUES (?, ?, ?, NOW())
            ');

            foreach ($userIds as $userId) {
                $stmt->execute([$groupId, $userId, $updatedBy]);
            }
        }

        $this->logAction('group_update', null, $groupId, null, $updatedBy,
            ['members' => $currentMembers],
            ['members' => $userIds]
        );

        return true;
    }

    /**
     * Get groups for a user
     *
     * @return array<int, array>
     */
    public function getGroupsForUser(int $userId): array
    {
        $stmt = $this->connection->pdo()->prepare('
            SELECT g.id, g.name, g.description, g.permissions, g.disabled_modules
            FROM user_groups g
            INNER JOIN user_group_members m ON g.id = m.group_id
            WHERE m.user_id = ?
            ORDER BY g.name ASC
        ');
        $stmt->execute([$userId]);

        return array_map(function ($group) {
            return $this->formatGroup($group);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Set groups for a user (replaces existing group memberships)
     *
     * @param int[] $groupIds
     */
    public function setGroupsForUser(int $userId, array $groupIds, ?int $updatedBy = null): bool
    {
        $pdo = $this->connection->pdo();

        // Get current groups for logging
        $currentGroups = array_column($this->getGroupsForUser($userId), 'id');

        // Remove from all groups
        $stmt = $pdo->prepare('DELETE FROM user_group_members WHERE user_id = ?');
        $stmt->execute([$userId]);

        // Add to new groups
        if (!empty($groupIds)) {
            $stmt = $pdo->prepare('
                INSERT INTO user_group_members (group_id, user_id, added_by, added_at)
                VALUES (?, ?, ?, NOW())
            ');

            foreach ($groupIds as $groupId) {
                $stmt->execute([$groupId, $userId, $updatedBy]);
            }
        }

        return true;
    }

    /**
     * Get users not in a specific group (for adding)
     *
     * @return array<int, array>
     */
    public function getNonMembers(int $groupId, ?string $search = null): array
    {
        $sql = '
            SELECT u.id, u.name, u.email, u.role
            FROM users u
            WHERE u.id NOT IN (SELECT user_id FROM user_group_members WHERE group_id = ?)
              AND u.role != ?
        ';
        $params = [$groupId, 'customer'];

        if ($search !== null && $search !== '') {
            $sql .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $sql .= ' ORDER BY u.name ASC LIMIT 50';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Format a group record for API response
     */
    private function formatGroup(array $group): array
    {
        return [
            'id' => (int) $group['id'],
            'name' => $group['name'],
            'description' => $group['description'],
            'permissions' => is_string($group['permissions'])
                ? json_decode($group['permissions'], true) ?? []
                : ($group['permissions'] ?? []),
            'disabled_modules' => is_string($group['disabled_modules'])
                ? json_decode($group['disabled_modules'], true) ?? []
                : ($group['disabled_modules'] ?? []),
            'is_default' => (bool) ($group['is_default'] ?? false),
            'member_count' => (int) ($group['member_count'] ?? 0),
            'created_by' => $group['created_by'] ?? null,
            'created_at' => $group['created_at'] ?? null,
            'updated_at' => $group['updated_at'] ?? null,
        ];
    }

    /**
     * Log an action to the module access log
     */
    private function logAction(
        string $actionType,
        ?string $moduleKey,
        ?int $groupId,
        ?int $targetUserId,
        ?int $performedBy,
        ?array $oldValue,
        ?array $newValue
    ): void {
        $stmt = $this->connection->pdo()->prepare('
            INSERT INTO module_access_log (action_type, module_key, group_id, target_user_id, performed_by, old_value, new_value, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ');

        $stmt->execute([
            $actionType,
            $moduleKey,
            $groupId,
            $targetUserId,
            $performedBy,
            $oldValue !== null ? json_encode($oldValue) : null,
            $newValue !== null ? json_encode($newValue) : null,
        ]);
    }
}
