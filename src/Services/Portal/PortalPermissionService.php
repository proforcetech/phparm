<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Phase 2d (Decision C in memory) — resolves an effective permission set for
 * a portal_account by combining the role_tier baseline with the per-account
 * scope.permissions overlay.
 *
 * Effective set = baseline(tier) ∪ scope.permissions.grant − scope.permissions.deny
 *
 * "deny wins" so an admin can hand out an approver tier and then explicitly
 * lock down `approve.estimates` for one teammate without inventing a new
 * tier. Unknown permission slugs in grant/deny are silently dropped — the
 * catalog is the source of truth, not the JSON.
 *
 * The service has no DB dependencies on purpose. It's a pure function over
 * the row, so it's safe to call inside hot service methods (every assert()
 * recomputes the set), and it's trivial to unit-test without fixtures.
 */
class PortalPermissionService
{
    /**
     * @return list<string> the effective permissions for this account.
     */
    public function effective(PortalAccount $account): array
    {
        $tier = $account->role_tier !== null && $account->role_tier !== ''
            ? $account->role_tier
            : PortalPermission::TIER_REQUESTER;
        $base = PortalPermission::baselineFor($tier);

        $grants = [];
        $denies = [];
        $scope = $account->scope ?? null;
        if (is_array($scope) && isset($scope['permissions']) && is_array($scope['permissions'])) {
            $grants = $this->normalizeList($scope['permissions']['grant'] ?? []);
            $denies = $this->normalizeList($scope['permissions']['deny'] ?? []);
        }

        $set = array_unique(array_merge($base, $grants));
        if ($denies !== []) {
            $denySet = array_flip($denies);
            $set = array_values(array_filter($set, fn(string $p) => !isset($denySet[$p])));
        }
        return array_values($set);
    }

    public function can(PortalAccount $account, string $permission): bool
    {
        if (!PortalPermission::isValidPermission($permission)) {
            // A typo at the call site is a programmer error, not a user
            // error; refuse loudly so it surfaces in dev/CI.
            throw new InvalidArgumentException("unknown portal permission: {$permission}");
        }
        return in_array($permission, $this->effective($account), true);
    }

    public function assert(PortalAccount $account, string $permission): void
    {
        if (!$this->can($account, $permission)) {
            throw new UnauthorizedException(
                "portal_account is not authorized for {$permission} (tier={$account->role_tier})"
            );
        }
    }

    /**
     * Compact summary suitable for embedding in /api/portal/auth/me so the
     * React layer can gate buttons without re-deriving the matrix client-
     * side. Includes the tier and the effective slug list — keep it small.
     *
     * @return array{tier: string, permissions: list<string>}
     */
    public function summarize(PortalAccount $account): array
    {
        return [
            'tier' => $account->role_tier !== null && $account->role_tier !== ''
                ? $account->role_tier
                : PortalPermission::TIER_REQUESTER,
            'permissions' => $this->effective($account),
        ];
    }

    /**
     * Validate a scope payload before persisting it. Returns a normalized
     * structure (unknown keys dropped, slugs deduplicated) so the column
     * never holds garbage. Returns null when the scope is empty — keep
     * the column NULL rather than storing `{}` so simple "is this account
     * customised?" checks can rely on IS NOT NULL.
     *
     * @param mixed $scope
     * @return array<string, mixed>|null
     */
    public function normalizeScope(mixed $scope): ?array
    {
        if ($scope === null || $scope === '' || $scope === []) {
            return null;
        }
        if (is_string($scope)) {
            $decoded = json_decode($scope, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('scope must be a JSON object or null');
            }
            $scope = $decoded;
        }
        if (!is_array($scope)) {
            throw new InvalidArgumentException('scope must be an associative array, JSON object, or null');
        }
        $normalized = [];
        if (isset($scope['permissions']) && is_array($scope['permissions'])) {
            $perms = [];
            $grants = $this->normalizeList($scope['permissions']['grant'] ?? []);
            if ($grants !== []) {
                $perms['grant'] = $grants;
            }
            $denies = $this->normalizeList($scope['permissions']['deny'] ?? []);
            if ($denies !== []) {
                $perms['deny'] = $denies;
            }
            if ($perms !== []) {
                $normalized['permissions'] = $perms;
            }
        }
        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (!is_string($v) || $v === '') {
                continue;
            }
            if (!PortalPermission::isValidPermission($v)) {
                continue;
            }
            $out[$v] = true;
        }
        return array_keys($out);
    }
}
