<?php

namespace App\Services\Crm;

use App\Models\User;
use App\Support\Auth\Policy;

/**
 * Bridges portal.* permission checks to the per-contact scope system
 * (Phase 1.3 of docs/expansion-plan.md).
 *
 * Behavior:
 *   - For portal_user role: consult ContactScopeResolver. If any linked
 *     contact grants the entitlement (and covers the optional site_id in
 *     the $resource), allow. Otherwise deny.
 *   - For the legacy `customer` role and all staff roles: abstain (null),
 *     leaving the existing role/permission string logic in charge.
 *
 * The $resource may be an array `['site_id' => N, 'amount' => X]` to refine
 * the decision; if absent, the policy only checks the entitlement presence.
 */
class PortalScopePolicy implements Policy
{
    /**
     * Actions this policy is responsible for. Registered under the wildcard
     * `portal.*` so any future portal.* permission falls under it.
     */
    public function __construct(private readonly ContactScopeResolver $resolver)
    {
    }

    public function decide(User $user, string $action, mixed $resource = null): ?bool
    {
        // Only engage for the new portal_user role. Legacy 'customer' and all
        // staff roles are untouched — they keep their string-permission path.
        if (($user->role ?? '') !== 'portal_user') {
            return null;
        }

        if (!str_starts_with($action, 'portal.')) {
            return null;
        }

        $siteId = null;
        $amount = null;
        if (is_array($resource)) {
            if (isset($resource['site_id']) && is_numeric($resource['site_id'])) {
                $siteId = (int) $resource['site_id'];
            }
            if (isset($resource['amount']) && is_numeric($resource['amount'])) {
                $amount = (float) $resource['amount'];
            }
        }

        return $this->resolver->can($user, $action, $siteId, $amount);
    }
}
