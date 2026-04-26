<?php

/**
 * Smoke test for the Phase 0.3 policy layer.
 *
 * Verifies:
 *   1. AccessGate without policies preserves legacy string-permission behavior
 *   2. A policy that abstains (returns null) falls back to the string check
 *   3. A policy that allows overrides a missing role permission
 *   4. A policy that denies overrides a granted role permission
 *   5. Wildcard registration ("customers.*") matches specific actions
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\Policy;
use App\Support\Auth\PolicyRegistry;
use App\Support\Auth\RolePermissions;

function makeUser(string $role): User
{
    $u = new User();
    $u->role = $role;
    return $u;
}

function assertTrue(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "OK  : $message\n";
}

$roles = new RolePermissions([
    'manager' => ['permissions' => ['customers.view', 'customers.update']],
    'viewer' => ['permissions' => ['customers.view']],
    'nobody' => ['permissions' => []],
]);

// --- 1. Legacy behavior: no policies attached
$legacyGate = new AccessGate($roles);
assertTrue($legacyGate->can(makeUser('manager'), 'customers.view'), 'legacy: manager has customers.view');
assertTrue(!$legacyGate->can(makeUser('nobody'), 'customers.view'), 'legacy: nobody does not have customers.view');

// --- 2. Abstaining policy falls back to string check
$abstainRegistry = new PolicyRegistry();
$abstainRegistry->register('customers.view', new class() implements Policy {
    public function decide(User $user, string $action, mixed $resource = null): ?bool
    {
        return null;
    }
});
$abstainGate = new AccessGate($roles, $abstainRegistry);
assertTrue($abstainGate->can(makeUser('manager'), 'customers.view'), 'abstain: manager still granted via role');
assertTrue(!$abstainGate->can(makeUser('nobody'), 'customers.view'), 'abstain: nobody still denied via role');

// --- 3. Allowing policy overrides missing role permission
$allowRegistry = new PolicyRegistry();
$allowRegistry->register('customers.view', new class() implements Policy {
    public function decide(User $user, string $action, mixed $resource = null): ?bool
    {
        return true;
    }
});
$allowGate = new AccessGate($roles, $allowRegistry);
assertTrue($allowGate->can(makeUser('nobody'), 'customers.view'), 'allow: policy grants nobody');

// --- 4. Denying policy overrides granted role permission
$denyRegistry = new PolicyRegistry();
$denyRegistry->register('customers.view', new class() implements Policy {
    public function decide(User $user, string $action, mixed $resource = null): ?bool
    {
        return false;
    }
});
$denyGate = new AccessGate($roles, $denyRegistry);
assertTrue(!$denyGate->can(makeUser('manager'), 'customers.view'), 'deny: policy revokes manager');

// --- 5. Wildcard registration
$wildcardRegistry = new PolicyRegistry();
$wildcardRegistry->register('customers.*', new class() implements Policy {
    public function decide(User $user, string $action, mixed $resource = null): ?bool
    {
        return $action === 'customers.update' ? false : null;
    }
});
$wildcardGate = new AccessGate($roles, $wildcardRegistry);
assertTrue($wildcardGate->can(makeUser('manager'), 'customers.view'), 'wildcard: view still allowed via role');
assertTrue(!$wildcardGate->can(makeUser('manager'), 'customers.update'), 'wildcard: update blocked by policy');

echo "\nAll AccessGate policy tests passed.\n";
