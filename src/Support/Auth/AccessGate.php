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
        error_log("AccessGate::assert - User role: {$user->role}, Permission: {$permission}");
        $canAccess = $this->can($user, $permission);
        error_log("AccessGate::assert - Result: " . ($canAccess ? 'GRANTED' : 'DENIED'));

        if (!$canAccess) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }
}
