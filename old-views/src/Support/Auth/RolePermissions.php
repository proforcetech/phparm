<?php

namespace App\Support\Auth;

use InvalidArgumentException;

class RolePermissions
{
    /**
     * @var array<string, array{label: string, description: string, permissions: string[]}>
     */
    private array $roles;

    /**
     * @param array<string, array{label: string, description: string, permissions: string[]}> $roles
     */
    public function __construct(array $roles)
    {
        $this->roles = $roles;
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
}
