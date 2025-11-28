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

        foreach ($granted as $grantedPermission) {
            if ($grantedPermission === '*') {
                return true;
            }

            if ($this->permissionMatches($grantedPermission, $permission)) {
                return true;
            }
        }

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
