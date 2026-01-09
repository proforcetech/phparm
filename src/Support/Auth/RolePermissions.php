<?php

namespace App\Support\Auth;

use App\Database\Connection;
use InvalidArgumentException;

class RolePermissions
{
    /**
     * @var array<string, array{label: string, description: string, permissions: string[], requires_2fa?: bool}>
     */
    private array $roles;

    /**
     * @param array<string, array{label: string, description: string, permissions: string[], requires_2fa?: bool}> $roles
     */
    public function __construct(array $roles)
    {
        $this->roles = $roles;
    }

    /**
     * @param array<string, array{label: string, description: string, permissions: string[], requires_2fa?: bool}> $defaultRoles
     */
    public static function fromDatabase(Connection $connection, array $defaultRoles): self
    {
        $roles = $defaultRoles;

        if (!self::hasCustomRolesTable($connection)) {
            return new self($roles);
        }

        $stmt = $connection->pdo()->prepare(
            'SELECT name, label, description, permissions FROM custom_roles'
        );
        $stmt->execute();

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $permissions = [];
            if (isset($row['permissions']) && is_string($row['permissions'])) {
                $permissions = json_decode($row['permissions'], true) ?? [];
            }

            $roleName = (string) ($row['name'] ?? '');
            if ($roleName === '') {
                continue;
            }

            $roles[$roleName] = [
                'label' => (string) ($row['label'] ?? ($roles[$roleName]['label'] ?? $roleName)),
                'description' => (string) ($row['description'] ?? ($roles[$roleName]['description'] ?? '')),
                'permissions' => is_array($permissions) ? $permissions : [],
                'requires_2fa' => $roles[$roleName]['requires_2fa'] ?? false,
            ];
        }

        return new self($roles);
    }

    public function hasRole(string $role): bool
    {
        return isset($this->roles[$role]);
    }

    /**
     * @return array<string, array{label: string, description: string, permissions: string[], requires_2fa?: bool}>
     */
    public function roleDefinitions(): array
    {
        return $this->roles;
    }

    public function validateRole(string $role): void
    {
        if (!isset($this->roles[$role])) {
            throw new InvalidArgumentException('Unknown role: ' . $role);
        }
    }

    /**
     * @return string[]
     */
    public function permissionsFor(string $role): array
    {
        $this->validateRole($role);

        return $this->roles[$role]['permissions'];
    }

    public function hasPermission(string $role, string $permission): bool
    {
        $granted = $this->permissionsFor($role);
        error_log("RolePermissions::hasPermission - Role: {$role}, Permission: {$permission}, Granted: " . json_encode($granted));

        foreach ($granted as $grantedPermission) {
            if ($grantedPermission === '*') {
                error_log("RolePermissions::hasPermission - Matched wildcard *");
                return true;
            }

            if ($this->permissionMatches($grantedPermission, $permission)) {
                error_log("RolePermissions::hasPermission - Matched: {$grantedPermission}");
                return true;
            }
        }

        error_log("RolePermissions::hasPermission - No match found, DENIED");
        return false;
    }

    /**
     * @return string[]
     */
    public function availablePermissions(): array
    {
        $permissions = [];

        foreach ($this->roles as $role) {
            foreach ($role['permissions'] as $permission) {
                $permissions[$permission] = true;
            }
        }

        $list = array_keys($permissions);
        sort($list);

        return $list;
    }

    private function permissionMatches(string $grantedPermission, string $permission): bool
    {
        if ($grantedPermission === $permission) {
            return true;
        }

        if (str_ends_with($grantedPermission, '.*')) {
            $prefix = substr($grantedPermission, 0, -2);

            return str_starts_with($permission, $prefix . '.');
        }

        return false;
    }

    private static function hasCustomRolesTable(Connection $connection): bool
    {
        $stmt = $connection->pdo()->prepare("SHOW TABLES LIKE 'custom_roles'");
        $stmt->execute();

        return (bool) $stmt->fetchColumn();
    }
}
