<?php

namespace App\Support\Auth;

use App\Models\User;

class AccessGate
{
    private RolePermissions $permissions;

    public function __construct(RolePermissions $permissions)
    {
        $this->permissions = $permissions;
    }

    public function can(User $user, string $permission): bool
    {
        return $this->permissions->hasPermission($user->role, $permission);
    }

    public function assert(User $user, string $permission): void
    {
        if (!$this->can($user, $permission)) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }
}
