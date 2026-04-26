<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Company;
use App\Models\PortalAccount;
use App\Models\Site;
use App\Models\User;
use App\Services\Crm\CompanyRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Portal\PortalAccountRepository;
use App\Services\Portal\PortalAuthService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\JwtService;
use App\Support\Auth\PasswordPolicy;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 6.1 of docs/expansion-plan.md — isolated customer-portal auth.
 *
 * Covers the three auth boundaries the portal depends on:
 *   1. portal-scoped login path is strictly gated on role=portal_user with
 *      an active portal_accounts row (missing role / missing account / bad
 *      password / revoked account all fail silently);
 *   2. JWTs minted by the portal path carry scope='portal' + company_id +
 *      portal_account_id + site_ids, and the session re-validator rejects
 *      any mismatch with the authoritative portal_accounts row at request
 *      time (i.e. revocation takes effect without waiting for TTL);
 *   3. provisioning is admin-only and cross-company site scoping is
 *      rejected at the service layer (so an admin cannot accidentally
 *      grant a user sites belonging to a different tenant).
 */

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class PortalFakeGate extends AccessGate
{
    /** @var string[] */
    public array $denied = [];
    public function __construct()
    {
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (in_array($permission, $this->denied, true)) {
            throw new RuntimeException("denied: {$permission}");
        }
    }
}

class PortalFakeAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public function __construct()
    {
    }
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class PortalFakeCompanies extends CompanyRepository
{
    /** @var array<int, Company> */
    public array $store = [];
    public function __construct()
    {
    }
    public function findById(int $id): ?Company
    {
        return $this->store[$id] ?? null;
    }
}

class PortalFakeSites extends SiteRepository
{
    /** @var array<int, Site> */
    public array $store = [];
    public function __construct()
    {
    }
    public function findById(int $id): ?Site
    {
        return $this->store[$id] ?? null;
    }
}

/**
 * In-memory user store. Keeps every row keyed by id so we can re-hydrate
 * by email OR id without touching PDO. The FakePortalAuthService below
 * overrides the 3 protected user-ops methods on PortalAuthService so the
 * test can run without a real DB (pdo_sqlite is not guaranteed present).
 */
class PortalFakeUsers
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $nextId = 1000;
}

class FakePortalAuthService extends PortalAuthService
{
    public function __construct(
        private readonly PortalFakeUsers $userStore,
        \App\Database\Connection $connection,
        PortalAccountRepository $accounts,
        \App\Services\Crm\CompanyRepository $companies,
        \App\Services\Crm\SiteRepository $sites,
        JwtService $jwt,
        PasswordPolicy $passwords,
        AccessGate $gate,
        AuditLogger $audit,
        array $config,
    ) {
        parent::__construct(
            $connection, $accounts, $companies, $sites,
            $jwt, $passwords, $gate, $audit, $config,
        );
    }

    protected function findUserByEmail(string $email): ?User
    {
        foreach ($this->userStore->rows as $row) {
            if ($row['email'] === $email && ((int) ($row['active'] ?? 1)) === 1) {
                return new User($row);
            }
        }
        return null;
    }

    protected function findUserById(int $id): ?User
    {
        $row = $this->userStore->rows[$id] ?? null;
        if ($row === null || ((int) ($row['active'] ?? 1)) !== 1) {
            return null;
        }
        return new User($row);
    }

    protected function createPortalUser(string $name, string $email, string $password): User
    {
        $id = $this->userStore->nextId++;
        $this->userStore->rows[$id] = [
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'role' => 'portal_user',
            'active' => 1,
            'email_verified' => 1,
        ];
        return new User($this->userStore->rows[$id]);
    }
}

/**
 * Connection stub — we never call pdo() in test paths because the fake
 * auth service overrides every user-op. Using reflection to bypass the
 * parent ctor avoids requiring a DB config array.
 */
function pMakeConnection(): \App\Database\Connection
{
    $ref = new ReflectionClass(\App\Database\Connection::class);
    /** @var \App\Database\Connection $conn */
    $conn = $ref->newInstanceWithoutConstructor();
    return $conn;
}

/**
 * JwtService variant that bypasses Connection. The tests do not exercise
 * refresh-token rotation paths (that's covered by JwtService's own tests),
 * so we neuter storeRefreshToken and findUserById to avoid needing a DB.
 */
class PortalTestJwtService extends JwtService
{
    public function __construct(string $secret)
    {
        $ref = new ReflectionClass(JwtService::class);
        $instance = $ref->newInstanceWithoutConstructor();
        // Populate the private fields by reflection.
        foreach (['secretKey' => $secret, 'tokenTtlSeconds' => 3600, 'refreshTtlSeconds' => 3600] as $field => $value) {
            $prop = $ref->getProperty($field);
            $prop->setAccessible(true);
            $prop->setValue($instance, $value);
        }
        // Reassign this instance's state to ours.
        foreach (['secretKey', 'tokenTtlSeconds', 'refreshTtlSeconds'] as $field) {
            $prop = $ref->getProperty($field);
            $prop->setAccessible(true);
            $prop->setValue($this, $prop->getValue($instance));
        }
    }

    // Store/validate refresh token paths touch the DB — skip them entirely.
    public function generateRefreshToken(
        User $user,
        ?string $familyId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): string {
        // Return a stub JWT-ish string without the DB write.
        $now = time();
        $payload = [
            'iss' => 'phparm',
            'sub' => $user->id,
            'iat' => $now,
            'exp' => $now + 3600,
            'type' => 'refresh',
            'jti' => bin2hex(random_bytes(8)),
            'fam' => $familyId ?? 'test-family',
        ];
        // Use reflection to call the private encode method.
        $ref = new ReflectionClass(JwtService::class);
        $encode = $ref->getMethod('encode');
        $encode->setAccessible(true);
        return $encode->invoke($this, $payload);
    }
}

/**
 * In-memory portal_accounts repository so tests do not need MySQL.
 * Semantics mirror the real repo so production wiring remains honest.
 */
class PortalFakeAccounts extends PortalAccountRepository
{
    /** @var array<int, PortalAccount> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function findById(int $id): ?PortalAccount
    {
        return $this->store[$id] ?? null;
    }
    public function findActiveByUserId(int $userId): ?PortalAccount
    {
        foreach ($this->store as $a) {
            if ($a->user_id === $userId && $a->is_active && $a->revoked_at === null) {
                return $a;
            }
        }
        return null;
    }
    public function findByUserAndCompany(int $userId, int $companyId): ?PortalAccount
    {
        foreach ($this->store as $a) {
            if ($a->user_id === $userId && $a->company_id === $companyId) {
                return $a;
            }
        }
        return null;
    }
    public function listByCompany(int $companyId, bool $activeOnly = false): array
    {
        $out = [];
        foreach ($this->store as $a) {
            if ($a->company_id !== $companyId) {
                continue;
            }
            if ($activeOnly && (!$a->is_active || $a->revoked_at !== null)) {
                continue;
            }
            $out[] = $a;
        }
        return $out;
    }
    public function provision(
        int $userId,
        int $companyId,
        ?array $allowedSiteIds,
        ?int $provisionedByUserId,
        ?string $notes = null,
    ): PortalAccount {
        if ($this->findByUserAndCompany($userId, $companyId) !== null) {
            throw new InvalidArgumentException("already provisioned");
        }
        $a = new PortalAccount();
        $a->id = $this->nextId++;
        $a->user_id = $userId;
        $a->company_id = $companyId;
        $a->allowed_site_ids = $allowedSiteIds;
        $a->is_active = true;
        $a->provisioned_by_user_id = $provisionedByUserId;
        $a->provisioned_at = '2026-04-23 10:00:00';
        $a->notes = $notes;
        $this->store[$a->id] = $a;
        return $a;
    }
    public function updateScope(int $id, ?array $allowedSiteIds, ?string $notes): PortalAccount
    {
        $this->store[$id]->allowed_site_ids = $allowedSiteIds;
        $this->store[$id]->notes = $notes;
        return $this->store[$id];
    }
    public function revoke(int $id, string $reason): void
    {
        if (!isset($this->store[$id]) || $this->store[$id]->revoked_at !== null) {
            return;
        }
        $this->store[$id]->is_active = false;
        $this->store[$id]->revoked_at = '2026-04-23 10:00:00';
        $this->store[$id]->revoked_reason = $reason;
    }
    public function recordLogin(int $id): void
    {
        if (isset($this->store[$id])) {
            $this->store[$id]->last_login_at = '2026-04-23 10:00:00';
        }
    }
}

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function pTestSecret(): string
{
    // Mixed-class high-entropy string (avoids the weak-pattern check that
    // flags hex-only strings via the 0123456789 substring rule).
    return 'aB#kR7$nZ2!vX9&pQ5*mL8@dF3^jY6%wC1(hT4)eG0+sK8-rH2=uJ7';
}

function pMakeEnv(): array
{
    $users = new PortalFakeUsers();
    $conn = pMakeConnection();
    $accounts = new PortalFakeAccounts();
    $companies = new PortalFakeCompanies();
    $sites = new PortalFakeSites();
    // JwtService constructor requires a real PDO — but since the tests
    // never exercise the refresh-token store via validateToken path, we
    // pass a minimal JwtService that short-circuits the Connection. The
    // storeRefreshToken() writes fail silently (error_log) so tests can
    // still run without a DB. validateToken + generateToken work
    // purely via HMAC and don't touch the DB.
    $jwt = new PortalTestJwtService(pTestSecret());
    $passwords = new PasswordPolicy([
        'passwords' => ['min_length' => 8, 'min_entropy' => 10, 'min_categories' => 1],
    ]);
    $gate = new PortalFakeGate();
    $audit = new PortalFakeAudit();
    $svc = new FakePortalAuthService(
        $users,
        $conn,
        $accounts,
        $companies,
        $sites,
        $jwt,
        $passwords,
        $gate,
        $audit,
        ['customer_portal' => ['login_enabled' => true, 'allow_registration' => false]],
    );
    return compact('users', 'conn', 'accounts', 'companies', 'sites', 'jwt', 'gate', 'audit', 'svc');
}

function pSeedUser(PortalFakeUsers $users, int $id, string $email, string $password, string $role): void
{
    $users->rows[$id] = [
        'id' => $id,
        'name' => ucfirst($role) . ' User',
        'email' => $email,
        'password' => password_hash($password, PASSWORD_BCRYPT),
        'role' => $role,
        'active' => 1,
        'email_verified' => 1,
    ];
}

function pSeedCompany(PortalFakeCompanies $companies, int $id, string $name = 'Acme Corp'): void
{
    $c = new Company();
    $c->id = $id;
    $c->name = $name;
    $companies->store[$id] = $c;
}

function pSeedSite(PortalFakeSites $sites, int $id, int $companyId): void
{
    $s = new Site();
    $s->id = $id;
    $s->company_id = $companyId;
    $s->name = 'Site ' . $id;
    $sites->store[$id] = $s;
}

function pAdminUser(): User
{
    $u = new User();
    $u->id = 1;
    $u->role = 'admin';
    return $u;
}

function pCheck(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $ex) {
        echo "  FAIL {$label}: " . $ex->getMessage() . "\n";
        exit(1);
    }
}

function pExpectThrow(callable $fn, string $exceptionClass, string $needle, string $label): void
{
    try {
        $fn();
        echo "  FAIL {$label}: expected {$exceptionClass} containing '{$needle}'\n";
        exit(1);
    } catch (Throwable $ex) {
        if (!($ex instanceof $exceptionClass)) {
            echo "  FAIL {$label}: expected {$exceptionClass}, got " . get_class($ex) . "\n";
            exit(1);
        }
        if ($needle !== '' && !str_contains($ex->getMessage(), $needle)) {
            echo "  FAIL {$label}: wrong message — '" . $ex->getMessage() . "' vs '{$needle}'\n";
            exit(1);
        }
        echo "  PASS {$label}\n";
    }
}

echo "Phase 6.1 — isolated portal auth\n";

// ---------------------------------------------------------------------------
// 1. Login happy path issues scoped token
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedCompany($env['companies'], 42);
pSeedSite($env['sites'], 500, 42);
pSeedUser($env['users'], 200, 'client@acme.test', 'SecretPass!9', 'portal_user');
$env['accounts']->provision(200, 42, [500], 1, 'provisioned by admin');

pCheck(function () use ($env) {
    $result = $env['svc']->login('client@acme.test', 'SecretPass!9', '127.0.0.1', 'test-ua');
    if ($result === null) {
        throw new RuntimeException('login returned null for valid creds');
    }
    if ($result['user']->role !== 'portal_user') {
        throw new RuntimeException('wrong role');
    }
    if ($result['portal_account']->company_id !== 42) {
        throw new RuntimeException('wrong company_id on account');
    }
    // Decode the token and check scope claims.
    $payload = $env['jwt']->decodeWithoutValidation($result['access_token']);
    if ($payload === null) {
        throw new RuntimeException('token did not decode');
    }
    if (($payload['scope'] ?? null) !== 'portal') {
        throw new RuntimeException('scope claim missing');
    }
    if (($payload['company_id'] ?? null) !== 42) {
        throw new RuntimeException('company_id claim missing');
    }
    if (($payload['portal_account_id'] ?? null) !== $result['portal_account']->id) {
        throw new RuntimeException('portal_account_id claim missing');
    }
    if ($payload['site_ids'] !== [500]) {
        throw new RuntimeException('site_ids claim missing');
    }
    // last_login_at should be stamped.
    if ($env['accounts']->store[$result['portal_account']->id]->last_login_at === null) {
        throw new RuntimeException('last_login_at should be stamped');
    }
    $events = array_map(fn($e) => $e->event, $env['audit']->entries);
    if (!in_array('portal.auth.login', $events, true)) {
        throw new RuntimeException('login audit missing');
    }
}, 'login issues scoped token + stamps last_login_at');

// ---------------------------------------------------------------------------
// 2. Wrong password fails silently (returns null)
// ---------------------------------------------------------------------------
pCheck(function () use ($env) {
    $result = $env['svc']->login('client@acme.test', 'wrong-password', null, null);
    if ($result !== null) {
        throw new RuntimeException('login should return null for wrong password');
    }
}, 'wrong password returns null (no info leak)');

// ---------------------------------------------------------------------------
// 3. Non-portal_user role rejected even with correct password
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedUser($env['users'], 300, 'staff@acme.test', 'StaffPass!1', 'manager');
pCheck(function () use ($env) {
    $result = $env['svc']->login('staff@acme.test', 'StaffPass!1', null, null);
    if ($result !== null) {
        throw new RuntimeException('manager login to portal should fail');
    }
}, 'staff role cannot use portal login');

// ---------------------------------------------------------------------------
// 4. User with no portal_account row rejected
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedUser($env['users'], 400, 'unprovisioned@acme.test', 'ValidPass!1', 'portal_user');
pCheck(function () use ($env) {
    $result = $env['svc']->login('unprovisioned@acme.test', 'ValidPass!1', null, null);
    if ($result !== null) {
        throw new RuntimeException('login without portal_account must fail');
    }
}, 'portal_user without account row rejected');

// ---------------------------------------------------------------------------
// 5. Revoked portal_account rejected
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedCompany($env['companies'], 42);
pSeedUser($env['users'], 500, 'revoked@acme.test', 'ValidPass!1', 'portal_user');
$acct = $env['accounts']->provision(500, 42, null, 1);
$env['accounts']->revoke($acct->id, 'customer_termed');
pCheck(function () use ($env) {
    $result = $env['svc']->login('revoked@acme.test', 'ValidPass!1', null, null);
    if ($result !== null) {
        throw new RuntimeException('revoked account should fail login');
    }
}, 'revoked portal_account rejected at login');

// ---------------------------------------------------------------------------
// 6. assertValidSession accepts fresh token
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedCompany($env['companies'], 42);
pSeedUser($env['users'], 600, 'session@acme.test', 'ValidPass!1', 'portal_user');
$acct = $env['accounts']->provision(600, 42, [77], 1);
pCheck(function () use ($env, $acct) {
    $user = new User(['id' => 600, 'role' => 'portal_user', 'email' => 'session@acme.test']);
    $payload = [
        'scope' => 'portal',
        'portal_account_id' => $acct->id,
        'company_id' => 42,
        'site_ids' => [77],
    ];
    $back = $env['svc']->assertValidSession($user, $payload);
    if ($back->id !== $acct->id) {
        throw new RuntimeException('assertValidSession returned wrong account');
    }
}, 'assertValidSession accepts matching claims');

// ---------------------------------------------------------------------------
// 7. assertValidSession rejects staff-scope tokens
// ---------------------------------------------------------------------------
pExpectThrow(function () use ($env, $acct) {
    $user = new User(['id' => 600, 'role' => 'portal_user']);
    $env['svc']->assertValidSession($user, [
        'scope' => 'staff',
        'portal_account_id' => $acct->id,
        'company_id' => 42,
    ]);
}, UnauthorizedException::class, 'portal scope required', 'staff-scope token rejected on portal');

// ---------------------------------------------------------------------------
// 8. assertValidSession rejects account belonging to another user
// ---------------------------------------------------------------------------
pExpectThrow(function () use ($env, $acct) {
    $wrong = new User(['id' => 999, 'role' => 'portal_user']);
    $env['svc']->assertValidSession($wrong, [
        'scope' => 'portal',
        'portal_account_id' => $acct->id,
        'company_id' => 42,
    ]);
}, UnauthorizedException::class, 'does not belong', 'portal_account ownership mismatch rejected');

// ---------------------------------------------------------------------------
// 9. assertValidSession rejects post-revocation even with valid token
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedCompany($env['companies'], 42);
pSeedUser($env['users'], 700, 'revoked-live@acme.test', 'ValidPass!1', 'portal_user');
$acct = $env['accounts']->provision(700, 42, null, 1);
$env['accounts']->revoke($acct->id, 'terminated');
pExpectThrow(function () use ($env, $acct) {
    $user = new User(['id' => 700, 'role' => 'portal_user']);
    $env['svc']->assertValidSession($user, [
        'scope' => 'portal',
        'portal_account_id' => $acct->id,
        'company_id' => 42,
    ]);
}, UnauthorizedException::class, 'inactive or revoked', 'live revocation rejected');

// ---------------------------------------------------------------------------
// 10. Company-id claim mismatch rejected (cross-tenant leak)
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedCompany($env['companies'], 42);
pSeedUser($env['users'], 800, 'mismatch@acme.test', 'ValidPass!1', 'portal_user');
$acct = $env['accounts']->provision(800, 42, null, 1);
pExpectThrow(function () use ($env, $acct) {
    $user = new User(['id' => 800, 'role' => 'portal_user']);
    $env['svc']->assertValidSession($user, [
        'scope' => 'portal',
        'portal_account_id' => $acct->id,
        'company_id' => 99,
    ]);
}, UnauthorizedException::class, 'company_id does not match', 'company_id mismatch rejected');

// ---------------------------------------------------------------------------
// 11. Provisioning: creates user + account atomically
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedCompany($env['companies'], 42);
pSeedSite($env['sites'], 500, 42);
pSeedSite($env['sites'], 501, 42);
pCheck(function () use ($env) {
    $account = $env['svc']->provisionAccount(pAdminUser(), [
        'name' => 'New Client',
        'email' => 'new@acme.test',
        'password' => 'Valid1Pass!',
        'company_id' => 42,
        'site_ids' => [500, 501],
        'notes' => 'SLA gold tier',
    ]);
    if ($account->company_id !== 42) {
        throw new RuntimeException('wrong company');
    }
    if ($account->allowed_site_ids !== [500, 501]) {
        throw new RuntimeException('site_ids not stored');
    }
    if ($account->provisioned_by_user_id !== 1) {
        throw new RuntimeException('provisioned_by_user_id not stamped');
    }
    $events = array_map(fn($e) => $e->event, $env['audit']->entries);
    if (!in_array('portal.account.provisioned', $events, true)) {
        throw new RuntimeException('provisioning audit missing');
    }
}, 'provisioning creates user + account with scoped sites + audit');

// ---------------------------------------------------------------------------
// 12. Provisioning rejects cross-company sites (tenant boundary enforced)
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedCompany($env['companies'], 42);
pSeedCompany($env['companies'], 43);
pSeedSite($env['sites'], 500, 42);
pSeedSite($env['sites'], 777, 43); // belongs to different company
pExpectThrow(function () use ($env) {
    $env['svc']->provisionAccount(pAdminUser(), [
        'name' => 'Tenant Crosser',
        'email' => 'cross@acme.test',
        'password' => 'Valid1Pass!',
        'company_id' => 42,
        'site_ids' => [500, 777],
    ]);
}, InvalidArgumentException::class, 'different company', 'cross-company site scoping rejected');

// ---------------------------------------------------------------------------
// 13. Provisioning requires users.create
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedCompany($env['companies'], 42);
$env['gate']->denied = ['users.create'];
pExpectThrow(function () use ($env) {
    $env['svc']->provisionAccount(pAdminUser(), [
        'name' => 'Not Allowed',
        'email' => 'nope@acme.test',
        'password' => 'Valid1Pass!',
        'company_id' => 42,
    ]);
}, RuntimeException::class, 'denied: users.create', 'provisioning gate enforced');

// ---------------------------------------------------------------------------
// 14. Unknown company_id rejected
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pExpectThrow(function () use ($env) {
    $env['svc']->provisionAccount(pAdminUser(), [
        'name' => 'Phantom',
        'email' => 'phantom@acme.test',
        'password' => 'Valid1Pass!',
        'company_id' => 9999,
    ]);
}, InvalidArgumentException::class, 'company_id is required', 'unknown company_id rejected');

// ---------------------------------------------------------------------------
// 15. Binding existing user: must be role=portal_user
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedCompany($env['companies'], 42);
pSeedUser($env['users'], 123, 'manager@acme.test', 'anything', 'manager');
pExpectThrow(function () use ($env) {
    $env['svc']->provisionAccount(pAdminUser(), [
        'user_id' => 123,
        'company_id' => 42,
    ]);
}, InvalidArgumentException::class, 'only role=portal_user can be bound', 'cannot bind non-portal user to portal_account');

// ---------------------------------------------------------------------------
// 16. assertSiteAccess enforces allowed_site_ids
// ---------------------------------------------------------------------------
$env = pMakeEnv();
$account = new PortalAccount();
$account->id = 1;
$account->company_id = 42;
$account->allowed_site_ids = [100, 200];
$account->is_active = true;
pCheck(function () use ($env, $account) {
    $env['svc']->assertSiteAccess($account, 100); // allowed
    $env['svc']->assertSiteAccess($account, 200); // allowed
}, 'assertSiteAccess allows whitelisted sites');
pExpectThrow(
    fn() => $env['svc']->assertSiteAccess($account, 999),
    UnauthorizedException::class,
    'cannot access site 999',
    'assertSiteAccess blocks non-whitelisted site',
);

// ---------------------------------------------------------------------------
// 17. assertSiteAccess with null allowed_site_ids = all sites in company
// ---------------------------------------------------------------------------
$account->allowed_site_ids = null;
pCheck(function () use ($env, $account) {
    $env['svc']->assertSiteAccess($account, 12345);
    $env['svc']->assertSiteAccess($account, 1);
}, 'null allowed_site_ids grants access to all sites');

// ---------------------------------------------------------------------------
// 18. Revocation removes access and emits audit
// ---------------------------------------------------------------------------
$env = pMakeEnv();
pSeedCompany($env['companies'], 42);
pSeedUser($env['users'], 900, 'to-revoke@acme.test', 'ValidPass!1', 'portal_user');
$acct = $env['accounts']->provision(900, 42, null, 1);
pCheck(function () use ($env, $acct) {
    $env['svc']->revokeAccount(pAdminUser(), $acct->id, 'contract_terminated');
    if ($env['accounts']->store[$acct->id]->revoked_at === null) {
        throw new RuntimeException('revoked_at not stamped');
    }
    if ($env['accounts']->store[$acct->id]->is_active !== false) {
        throw new RuntimeException('is_active should be false after revoke');
    }
    $events = array_map(fn($e) => $e->event, $env['audit']->entries);
    if (!in_array('portal.account.revoked', $events, true)) {
        throw new RuntimeException('revocation audit missing');
    }
    // Second revoke is a no-op (no duplicate audit with different reason).
    $env['svc']->revokeAccount(pAdminUser(), $acct->id, 'already_revoked');
    if ($env['accounts']->store[$acct->id]->revoked_reason !== 'contract_terminated') {
        throw new RuntimeException('second revoke should not overwrite original reason');
    }
}, 'revocation flips flags + audits, second call is idempotent');

echo "OK\n";
