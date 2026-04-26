<?php

namespace App\Support\Auth;

use App\Models\User;

class AccessGate
{
    private RolePermissions $permissions;
    private ?PolicyRegistry $policies;

    public function __construct(RolePermissions $permissions, ?PolicyRegistry $policies = null)
    {
        $this->permissions = $permissions;
        $this->policies = $policies;
    }

    /**
     * Resolve a permission decision. Policies run first; if every policy
     * abstains (or none are registered), fall back to the role/permission
     * string match. See docs/expansion-plan.md Phase 0.3.
     *
     * @param mixed $resource Optional resource for contextual policies
     */
    public function can(User $user, string $permission, mixed $resource = null): bool
    {
        if ($this->policies !== null) {
            $decision = $this->policies->decide($user, $permission, $resource);
            if ($decision !== null) {
                return $decision;
            }
        }

        return $this->permissions->hasPermission($user->role, $permission);
    }

    public function check(User $user, string $permission, mixed $resource = null): bool
    {
        return $this->can($user, $permission, $resource);
    }

    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (!$this->can($user, $permission, $resource)) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }

    public function policies(): ?PolicyRegistry
    {
        return $this->policies;
    }
}
