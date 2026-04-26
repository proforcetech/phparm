<?php

namespace App\Support\Auth;

use App\Models\User;

/**
 * Registry of Policy instances keyed by the exact permission string they
 * handle (e.g. "customers.view", "sites.update"). Policies may also opt
 * into a prefix ("customers.*") by registering at multiple keys or by
 * implementing abstain logic inside decide().
 *
 * Registered policies are consulted by AccessGate before the underlying
 * RolePermissions string match. The first policy that returns a non-null
 * result wins; if all policies abstain, we fall back to the string
 * permission check.
 */
final class PolicyRegistry
{
    /**
     * @var array<string, list<Policy>>
     */
    private array $policies = [];

    public function register(string $permission, Policy $policy): void
    {
        $this->policies[$permission][] = $policy;
    }

    /**
     * Consult policies for $permission. Returns the first non-null decision,
     * or null if every policy abstains (meaning the caller should fall back
     * to the string permission check).
     */
    public function decide(User $user, string $permission, mixed $resource = null): ?bool
    {
        foreach ($this->matchingPolicies($permission) as $policy) {
            $decision = $policy->decide($user, $permission, $resource);
            if ($decision !== null) {
                return $decision;
            }
        }

        return null;
    }

    /**
     * @return iterable<Policy>
     */
    private function matchingPolicies(string $permission): iterable
    {
        if (isset($this->policies[$permission])) {
            foreach ($this->policies[$permission] as $policy) {
                yield $policy;
            }
        }

        // Wildcard match: policies registered under "customers.*" also fire
        // for "customers.view", "customers.update", etc.
        $segments = explode('.', $permission);
        while (count($segments) > 1) {
            array_pop($segments);
            $wildcardKey = implode('.', $segments) . '.*';
            if (isset($this->policies[$wildcardKey])) {
                foreach ($this->policies[$wildcardKey] as $policy) {
                    yield $policy;
                }
            }
        }
    }
}
