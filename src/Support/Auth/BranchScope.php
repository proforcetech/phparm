<?php

namespace App\Support\Auth;

use App\Models\User;

class BranchScope
{
    private const REGIONAL_MANAGER_ROLES = ['regional_manager', 'regional manager'];

    public static function resolveBranchId(User $user, ?int $requestedBranchId): ?int
    {
        $role = strtolower(trim($user->role));

        if ($role === 'admin' || in_array($role, self::REGIONAL_MANAGER_ROLES, true)) {
            return $requestedBranchId;
        }

        if ($user->branch_id !== null) {
            if ($requestedBranchId !== null && $requestedBranchId !== $user->branch_id) {
                throw new UnauthorizedException('Cannot access branch data outside your scope.');
            }

            return $user->branch_id;
        }

        return $requestedBranchId;
    }

    /**
     * @param array<int, array{id: int, label: string}> $branches
     * @return array<int, array{id: int, label: string}>
     */
    public static function filterBranchesForUser(User $user, array $branches): array
    {
        $role = strtolower(trim($user->role));

        if ($role === 'admin' || in_array($role, self::REGIONAL_MANAGER_ROLES, true)) {
            return $branches;
        }

        if ($user->branch_id === null) {
            return $branches;
        }

        return array_values(array_filter(
            $branches,
            static fn ($branch) => (int) $branch['id'] === $user->branch_id
        ));
    }
}
