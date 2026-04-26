<?php

namespace App\Support\Auth;

use App\Models\User;

/**
 * Policy contract for contextual permission decisions.
 *
 * Motivation (docs/expansion-plan.md Phase 0.3): the existing string-based
 * `role.action` permission check can't express "this user has access to
 * THIS division" or "this portal user can see ONLY their own sites".
 *
 * Policies are registered with PolicyRegistry against a (module, action)
 * pair and consulted BEFORE the role/permission string is evaluated. A
 * policy returns:
 *   - true   → allow
 *   - false  → deny
 *   - null   → abstain (fall back to the string permission)
 *
 * Policies should abstain (return null) when the question doesn't apply —
 * e.g. a per-site policy getting called for a non-site resource.
 */
interface Policy
{
    /**
     * @param mixed $resource Optional resource being acted on (entity, array, id, etc.)
     */
    public function decide(User $user, string $action, mixed $resource = null): ?bool;
}
