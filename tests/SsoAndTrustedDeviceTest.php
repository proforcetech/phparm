<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SsoAndTrustedDeviceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\SsoLoginAttempt;
use App\Models\SsoProvider;
use App\Models\User;
use App\Services\Auth\TrustedDeviceRepository;
use App\Services\Auth\TrustedDeviceService;
use App\Services\Sso\OidcHttpClient;
use App\Services\Sso\SsoLoginAttemptRepository;
use App\Services\Sso\SsoProviderRepository;
use App\Services\Sso\SsoService;
use App\Services\Sso\SsoUserLinkRepository;
use App\Services\User\UserRepository;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Cross-cutting SSO + Trusted-Device tests.
 *
 * Covers:
 *   - SsoProviderRepository CRUD: create/find/findBySlug/listAll/listActive/update/delete + metadata JSON.
 *   - SsoLoginAttemptRepository: create/findByState/complete/fail + expireStale.
 *   - SsoUserLinkRepository: create/find/findByProviderSubject/listForUser/touchLogin/syncProfile/delete.
 *   - SsoService.startLogin: persists attempt with state, builds authorize URL with all required params.
 *   - SsoService.handleCallback (existing-link path): resolves link → user, no profile sync if disabled.
 *   - SsoService.handleCallback (email-match path): resolves user by email when no link yet, creates link.
 *   - SsoService.handleCallback (auto-provision path): creates user + link when allowed, default role applied.
 *   - SsoService.handleCallback (sync_profile_on_login=true): updates link email + display_name on subsequent login.
 *   - SsoService.handleCallback rejection: no email + no auto_provision → RuntimeException.
 *   - SsoService.handleCallback rejection: state not pending → InvalidArgumentException.
 *   - SsoService.unlink: only owner can unlink.
 *   - TrustedDeviceService.issue: returns raw token, hashes in DB, sets expires_at correctly.
 *   - TrustedDeviceService.verify (happy path): valid token returns row + stamps last_used_at.
 *   - TrustedDeviceService.verify (wrong user): rejects.
 *   - TrustedDeviceService.verify (expired): rejects.
 *   - TrustedDeviceService.verify (revoked): rejects.
 *   - TrustedDeviceService.revoke: only owner OR users.update gate.
 *   - TrustedDeviceService.revokeAllForUser: revokes all.
 *   - TrustedDeviceRepository.purgeExpired: hard deletes past-expiry rows.
 */

class SsoInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

class SsoPermissiveGate extends AccessGate
{
    /** @var array<string, bool> */
    public array $denials = [];
    public function __construct()
    {
    }
    public function can(User $user, string $permission, mixed $resource = null): bool
    {
        return empty($this->denials[$permission]);
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (!empty($this->denials[$permission])) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }
}

/**
 * Test stub: in-memory UserRepository compatible with SQLite (the real
 * one uses MySQL NOW() in INSERT). We override only the methods SsoService
 * actually calls: find, findByEmail, create.
 */
class SsoStubUserRepository extends UserRepository
{
    /** @var array<int, User> */
    private array $usersById = [];
    private int $nextId = 1;

    public function __construct(private Connection $conn)
    {
        parent::__construct($conn);
    }

    public function find(int $id): ?User
    {
        return $this->usersById[$id] ?? null;
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->usersById as $u) {
            if ($u->email === $email) {
                return $u;
            }
        }
        return null;
    }

    public function create(array $data): User
    {
        $u = new User();
        $u->id = $this->nextId++;
        $u->name = (string) ($data['name'] ?? '');
        $u->email = (string) ($data['email'] ?? '');
        $u->role = (string) ($data['role'] ?? 'customer');
        $u->active = (bool) ($data['active'] ?? true);
        $u->email_verified = (bool) ($data['email_verified'] ?? false);
        $this->usersById[$u->id] = $u;
        return $u;
    }

    public function seed(User $u): User
    {
        if ($u->id === null) {
            $u->id = $this->nextId++;
        } else {
            $this->nextId = max($this->nextId, $u->id + 1);
        }
        $this->usersById[$u->id] = $u;
        return $u;
    }
}

/**
 * Test stub: in-memory OIDC HTTP client returning fixture token + userinfo.
 */
class SsoStubHttpClient extends OidcHttpClient
{
    /** @var array<string, mixed> */
    public array $tokenResponse = ['access_token' => 'fake-access-token', 'token_type' => 'Bearer'];
    /** @var array<string, mixed> */
    public array $userinfoResponse = [];
    public bool $shouldFailToken = false;

    public function postForm(string $url, array $form, ?string $basicAuth = null): array
    {
        if ($this->shouldFailToken) {
            throw new RuntimeException('Stub: simulated token failure.');
        }
        return $this->tokenResponse;
    }

    public function getJson(string $url, string $bearerToken): array
    {
        return $this->userinfoResponse;
    }
}

function ssoAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function ssoAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function ssoAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new RuntimeException("FAIL {$msg}: got " . get_class($e) . " expected {$expectedClass}");
        }
        return;
    }
    throw new RuntimeException("FAIL {$msg}: no exception thrown (expected {$expectedClass})");
}

function ssoMakeUser(int $id, string $email, string $role = 'customer', bool $active = true): User
{
    $u = new User();
    $u->id = $id;
    $u->name = 'User-' . $id;
    $u->email = $email;
    $u->role = $role;
    $u->active = $active;
    return $u;
}

function ssoBuildSchema(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(<<<'SQL'
        CREATE TABLE sso_providers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'oidc',
            issuer_url TEXT NULL,
            client_id TEXT NULL,
            client_secret TEXT NULL,
            redirect_uri TEXT NULL,
            authorize_endpoint TEXT NULL,
            token_endpoint TEXT NULL,
            userinfo_endpoint TEXT NULL,
            jwks_uri TEXT NULL,
            scopes TEXT NOT NULL DEFAULT 'openid email profile',
            is_active INTEGER NOT NULL DEFAULT 1,
            auto_provision INTEGER NOT NULL DEFAULT 0,
            default_role TEXT NULL,
            sync_profile_on_login INTEGER NOT NULL DEFAULT 1,
            metadata TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE sso_user_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            provider_id INTEGER NOT NULL,
            subject TEXT NOT NULL,
            email TEXT NULL,
            display_name TEXT NULL,
            last_login_at TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(provider_id, subject),
            FOREIGN KEY (provider_id) REFERENCES sso_providers(id) ON DELETE CASCADE
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE sso_login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_id INTEGER NOT NULL,
            state TEXT NOT NULL UNIQUE,
            nonce TEXT NULL,
            redirect_uri TEXT NULL,
            user_id INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            error_message TEXT NULL,
            completed_at TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (provider_id) REFERENCES sso_providers(id) ON DELETE CASCADE
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE trusted_devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            label TEXT NULL,
            user_agent TEXT NULL,
            ip_address TEXT NULL,
            last_used_at TEXT NULL,
            expires_at TEXT NOT NULL,
            revoked_at TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    SQL);
}

/**
 * @return array<string, mixed>
 */
function ssoFixture(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ssoBuildSchema($pdo);

    $conn = new SsoInMemoryConnection($pdo);
    $gate = new SsoPermissiveGate();
    $providerRepo = new SsoProviderRepository($conn);
    $linkRepo = new SsoUserLinkRepository($conn);
    $attemptRepo = new SsoLoginAttemptRepository($conn);
    $userRepo = new SsoStubUserRepository($conn);
    $http = new SsoStubHttpClient();

    $service = new SsoService($providerRepo, $linkRepo, $attemptRepo, $userRepo, $http);

    $deviceRepo = new TrustedDeviceRepository($conn);
    $deviceService = new TrustedDeviceService($deviceRepo, $gate);

    return [
        'pdo' => $pdo,
        'connection' => $conn,
        'gate' => $gate,
        'providerRepo' => $providerRepo,
        'linkRepo' => $linkRepo,
        'attemptRepo' => $attemptRepo,
        'userRepo' => $userRepo,
        'http' => $http,
        'service' => $service,
        'deviceRepo' => $deviceRepo,
        'deviceService' => $deviceService,
    ];
}

function ssoMakeProvider(SsoProviderRepository $repo, array $overrides = []): SsoProvider
{
    return $repo->create(array_merge([
        'slug' => 'okta',
        'name' => 'Okta',
        'type' => 'oidc',
        'issuer_url' => 'https://example.okta.com',
        'client_id' => 'client-abc',
        'client_secret' => 'secret-xyz',
        'redirect_uri' => 'https://app.example.com/api/auth/sso/callback',
        'authorize_endpoint' => 'https://example.okta.com/oauth2/authorize',
        'token_endpoint' => 'https://example.okta.com/oauth2/token',
        'userinfo_endpoint' => 'https://example.okta.com/oauth2/userinfo',
        'scopes' => 'openid email profile',
        'is_active' => true,
        'auto_provision' => false,
        'sync_profile_on_login' => false,
    ], $overrides));
}

// ------------- TESTS -------------

$tests = [];

$tests[] = function (): void {
    // SsoProviderRepository CRUD
    $f = ssoFixture();
    /** @var SsoProviderRepository $repo */
    $repo = $f['providerRepo'];
    $p = ssoMakeProvider($repo, ['metadata' => ['team' => 'platform']]);
    ssoAssertTrue($p->id !== null, 'provider id assigned');
    $found = $repo->findBySlug('okta');
    ssoAssertSame('Okta', $found?->name, 'findBySlug.name');
    ssoAssertSame(['team' => 'platform'], $found?->metadata, 'metadata round-trip');

    $repo->update($p->id, ['is_active' => false]);
    $listed = $repo->listAll();
    ssoAssertSame(1, count($listed), 'listAll count');
    ssoAssertSame(0, count($repo->listActive()), 'listActive excludes inactive');

    ssoAssertTrue($repo->delete($p->id), 'delete returns true');
    ssoAssertTrue($repo->find($p->id) === null, 'deleted row gone');
};

$tests[] = function (): void {
    // SsoLoginAttempt repository: create + findByState + complete + fail + expireStale
    $f = ssoFixture();
    /** @var SsoLoginAttemptRepository $aRepo */
    $aRepo = $f['attemptRepo'];
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    $provider = ssoMakeProvider($pRepo);

    $a1 = $aRepo->create(['provider_id' => $provider->id, 'state' => 'st1', 'nonce' => 'n1']);
    ssoAssertSame(SsoLoginAttempt::STATUS_PENDING, $a1->status, 'pending status default');
    ssoAssertSame($a1->id, $aRepo->findByState('st1')?->id, 'findByState');

    $aRepo->complete($a1->id, 99, '2026-01-01 00:00:00');
    ssoAssertSame(SsoLoginAttempt::STATUS_COMPLETED, $aRepo->find($a1->id)?->status, 'completed');
    ssoAssertSame(99, $aRepo->find($a1->id)?->user_id, 'user_id stamped');

    $a2 = $aRepo->create(['provider_id' => $provider->id, 'state' => 'st2']);
    $aRepo->fail($a2->id, 'something broke');
    $reload = $aRepo->find($a2->id);
    ssoAssertSame(SsoLoginAttempt::STATUS_FAILED, $reload?->status, 'failed status');
    ssoAssertSame('something broke', $reload?->error_message, 'failure message');

    // Backdate a3 to test expireStale
    $a3 = $aRepo->create(['provider_id' => $provider->id, 'state' => 'st3']);
    $f['pdo']->exec("UPDATE sso_login_attempts SET created_at = '2020-01-01 00:00:00' WHERE id = " . (int) $a3->id);
    $expired = $aRepo->expireStale('2025-01-01 00:00:00');
    ssoAssertSame(1, $expired, 'expireStale counts only stale pending');
    ssoAssertSame(SsoLoginAttempt::STATUS_EXPIRED, $aRepo->find($a3->id)?->status, 'a3 marked expired');
};

$tests[] = function (): void {
    // SsoUserLinkRepository
    $f = ssoFixture();
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    /** @var SsoUserLinkRepository $lRepo */
    $lRepo = $f['linkRepo'];
    $provider = ssoMakeProvider($pRepo);

    $link = $lRepo->create([
        'user_id' => 5,
        'provider_id' => $provider->id,
        'subject' => 'sub|abc',
        'email' => 'a@example.com',
        'display_name' => 'Alice',
    ]);
    ssoAssertTrue($link->id !== null, 'link id');
    ssoAssertSame($link->id, $lRepo->findByProviderSubject($provider->id, 'sub|abc')?->id, 'findByProviderSubject');
    ssoAssertSame(1, count($lRepo->listForUser(5)), 'listForUser');

    $lRepo->syncProfile($link->id, ['email' => 'a2@example.com', 'display_name' => 'Alice Smith']);
    $lRepo->touchLogin($link->id, '2026-01-01 00:00:00');
    $reload = $lRepo->find($link->id);
    ssoAssertSame('a2@example.com', $reload?->email, 'syncProfile email');
    ssoAssertSame('Alice Smith', $reload?->display_name, 'syncProfile display_name');
    ssoAssertSame('2026-01-01 00:00:00', $reload?->last_login_at, 'touchLogin');

    ssoAssertTrue($lRepo->delete($link->id), 'delete link');
};

$tests[] = function (): void {
    // SsoService.startLogin builds authorize URL with state+nonce
    $f = ssoFixture();
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    /** @var SsoService $service */
    $service = $f['service'];
    ssoMakeProvider($pRepo);

    $r = $service->startLogin('okta', 'https://app.example.com/post-login');
    $url = $r['authorize_url'];
    ssoAssertTrue(str_contains($url, 'client_id=client-abc'), 'authorize URL has client_id');
    ssoAssertTrue(str_contains($url, 'response_type=code'), 'authorize URL has response_type=code');
    ssoAssertTrue(str_contains($url, 'state=' . $r['state']), 'authorize URL embeds state');
    ssoAssertTrue(str_contains($url, 'scope=openid'), 'authorize URL has scopes');
    ssoAssertSame(SsoLoginAttempt::STATUS_PENDING, $r['attempt']->status, 'attempt is pending');
    ssoAssertSame('https://app.example.com/post-login', $r['attempt']->redirect_uri, 'redirect_uri persisted');
};

$tests[] = function (): void {
    // SsoService.startLogin rejects unknown slug
    $f = ssoFixture();
    /** @var SsoService $service */
    $service = $f['service'];
    ssoAssertThrows(function () use ($service) {
        $service->startLogin('does-not-exist');
    }, InvalidArgumentException::class, 'unknown slug throws');
};

$tests[] = function (): void {
    // SsoService.startLogin rejects SAML
    $f = ssoFixture();
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    /** @var SsoService $service */
    $service = $f['service'];
    ssoMakeProvider($pRepo, ['type' => 'saml']);
    ssoAssertThrows(function () use ($service) {
        $service->startLogin('okta');
    }, RuntimeException::class, 'SAML not yet supported');
};

$tests[] = function (): void {
    // SsoService.handleCallback — existing-link path
    $f = ssoFixture();
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    /** @var SsoUserLinkRepository $lRepo */
    $lRepo = $f['linkRepo'];
    /** @var SsoStubUserRepository $userRepo */
    $userRepo = $f['userRepo'];
    /** @var SsoStubHttpClient $http */
    $http = $f['http'];
    /** @var SsoService $service */
    $service = $f['service'];

    $provider = ssoMakeProvider($pRepo);
    $existing = $userRepo->seed(ssoMakeUser(42, 'existing@example.com'));
    $lRepo->create([
        'user_id' => $existing->id,
        'provider_id' => $provider->id,
        'subject' => 'sub-001',
    ]);

    // Prep startLogin to produce a state we can then call back against.
    $start = $service->startLogin('okta');
    $http->userinfoResponse = ['sub' => 'sub-001', 'email' => 'existing@example.com'];

    $cb = $service->handleCallback($start['state'], 'auth-code-1');
    ssoAssertSame(42, $cb['user']->id, 'existing user resolved');
    ssoAssertSame(SsoLoginAttempt::STATUS_COMPLETED, $cb['attempt']->status, 'attempt completed');
};

$tests[] = function (): void {
    // SsoService.handleCallback — email-match path links to existing local user
    $f = ssoFixture();
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    /** @var SsoStubUserRepository $userRepo */
    $userRepo = $f['userRepo'];
    /** @var SsoStubHttpClient $http */
    $http = $f['http'];
    /** @var SsoService $service */
    $service = $f['service'];
    /** @var SsoUserLinkRepository $lRepo */
    $lRepo = $f['linkRepo'];

    $provider = ssoMakeProvider($pRepo);
    $userRepo->seed(ssoMakeUser(7, 'match@example.com'));

    $start = $service->startLogin('okta');
    $http->userinfoResponse = ['sub' => 'sub-007', 'email' => 'match@example.com', 'name' => 'Matt'];

    $cb = $service->handleCallback($start['state'], 'auth-code-2');
    ssoAssertSame(7, $cb['user']->id, 'email-matched user');
    $link = $lRepo->findByProviderSubject($provider->id, 'sub-007');
    ssoAssertTrue($link !== null, 'link auto-created');
    ssoAssertSame(7, $link?->user_id, 'link points at matched user');
};

$tests[] = function (): void {
    // SsoService.handleCallback — auto-provision creates new user with default_role
    $f = ssoFixture();
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    /** @var SsoStubHttpClient $http */
    $http = $f['http'];
    /** @var SsoService $service */
    $service = $f['service'];

    ssoMakeProvider($pRepo, ['auto_provision' => true, 'default_role' => 'technician']);

    $start = $service->startLogin('okta');
    $http->userinfoResponse = ['sub' => 'sub-new', 'email' => 'new@example.com', 'name' => 'New User'];

    $cb = $service->handleCallback($start['state'], 'auth-code-3');
    ssoAssertSame('new@example.com', $cb['user']->email, 'new user email');
    ssoAssertSame('technician', $cb['user']->role, 'default_role applied');
};

$tests[] = function (): void {
    // SsoService.handleCallback — sync_profile_on_login=true updates link on subsequent login
    $f = ssoFixture();
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    /** @var SsoStubUserRepository $userRepo */
    $userRepo = $f['userRepo'];
    /** @var SsoStubHttpClient $http */
    $http = $f['http'];
    /** @var SsoService $service */
    $service = $f['service'];
    /** @var SsoUserLinkRepository $lRepo */
    $lRepo = $f['linkRepo'];

    $provider = ssoMakeProvider($pRepo, ['sync_profile_on_login' => true]);
    $u = $userRepo->seed(ssoMakeUser(9, 'sync@example.com'));
    $lRepo->create([
        'user_id' => $u->id,
        'provider_id' => $provider->id,
        'subject' => 'sub-sync',
        'email' => 'old@example.com',
        'display_name' => 'Old',
    ]);

    $start = $service->startLogin('okta');
    $http->userinfoResponse = ['sub' => 'sub-sync', 'email' => 'sync@example.com', 'name' => 'New Name'];

    $service->handleCallback($start['state'], 'code');
    $link = $lRepo->findByProviderSubject($provider->id, 'sub-sync');
    ssoAssertSame('sync@example.com', $link?->email, 'link.email synced');
    ssoAssertSame('New Name', $link?->display_name, 'link.display_name synced');
};

$tests[] = function (): void {
    // SsoService.handleCallback — no-email + no auto_provision → RuntimeException
    $f = ssoFixture();
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    /** @var SsoStubHttpClient $http */
    $http = $f['http'];
    /** @var SsoService $service */
    $service = $f['service'];

    ssoMakeProvider($pRepo, ['auto_provision' => false]);
    $start = $service->startLogin('okta');
    $http->userinfoResponse = ['sub' => 'orphan-sub']; // no email, no match

    ssoAssertThrows(function () use ($service, $start) {
        $service->handleCallback($start['state'], 'code');
    }, RuntimeException::class, 'no match + no auto_provision rejects');
};

$tests[] = function (): void {
    // SsoService.handleCallback — already-consumed state rejects
    $f = ssoFixture();
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    /** @var SsoStubUserRepository $userRepo */
    $userRepo = $f['userRepo'];
    /** @var SsoStubHttpClient $http */
    $http = $f['http'];
    /** @var SsoService $service */
    $service = $f['service'];

    $provider = ssoMakeProvider($pRepo);
    $userRepo->seed(ssoMakeUser(11, 'replay@example.com'));

    $start = $service->startLogin('okta');
    $http->userinfoResponse = ['sub' => 'sub-replay', 'email' => 'replay@example.com'];

    $service->handleCallback($start['state'], 'first-code');
    // second call with same state must fail - state is already 'completed'
    ssoAssertThrows(function () use ($service, $start) {
        $service->handleCallback($start['state'], 'second-code');
    }, InvalidArgumentException::class, 'state replay rejected');
};

$tests[] = function (): void {
    // SsoService.unlink — only owner
    $f = ssoFixture();
    /** @var SsoProviderRepository $pRepo */
    $pRepo = $f['providerRepo'];
    /** @var SsoUserLinkRepository $lRepo */
    $lRepo = $f['linkRepo'];
    /** @var SsoService $service */
    $service = $f['service'];
    $provider = ssoMakeProvider($pRepo);
    $owner = ssoMakeUser(50, 'owner@example.com');
    $other = ssoMakeUser(51, 'other@example.com');
    $link = $lRepo->create(['user_id' => 50, 'provider_id' => $provider->id, 'subject' => 'sub-u']);

    ssoAssertSame(false, $service->unlink($other, $link->id), 'non-owner cannot unlink');
    ssoAssertSame(true, $service->unlink($owner, $link->id), 'owner can unlink');
    ssoAssertTrue($lRepo->find($link->id) === null, 'link removed');
};

$tests[] = function (): void {
    // TrustedDeviceService.issue + verify happy path
    $f = ssoFixture();
    /** @var TrustedDeviceService $svc */
    $svc = $f['deviceService'];
    /** @var TrustedDeviceRepository $repo */
    $repo = $f['deviceRepo'];

    $u = ssoMakeUser(101, 'trust@example.com');
    $issued = $svc->issue($u, 'My Mac', 'TestUA', '1.2.3.4', 30);
    ssoAssertTrue(strlen($issued['token']) === 64, 'token is 64 hex chars');
    ssoAssertSame(hash('sha256', $issued['token']), $issued['device']->token_hash, 'DB stores hash');

    $verified = $svc->verify($u, $issued['token']);
    ssoAssertTrue($verified !== null, 'verify ok');
    ssoAssertSame($issued['device']->id, $verified?->id, 'same row');

    // Stamps last_used_at
    $reload = $repo->find((int) $issued['device']->id);
    ssoAssertTrue($reload?->last_used_at !== null, 'last_used_at stamped');
};

$tests[] = function (): void {
    // verify rejects wrong user
    $f = ssoFixture();
    /** @var TrustedDeviceService $svc */
    $svc = $f['deviceService'];
    $owner = ssoMakeUser(201, 'a@example.com');
    $other = ssoMakeUser(202, 'b@example.com');
    $issued = $svc->issue($owner);
    ssoAssertTrue($svc->verify($other, $issued['token']) === null, 'wrong user → null');
    ssoAssertTrue($svc->verify($owner, 'not-the-real-token') === null, 'unknown token → null');
};

$tests[] = function (): void {
    // verify rejects expired
    $f = ssoFixture();
    /** @var TrustedDeviceService $svc */
    $svc = $f['deviceService'];
    /** @var PDO $pdo */
    $pdo = $f['pdo'];
    $u = ssoMakeUser(301, 'exp@example.com');
    $issued = $svc->issue($u);
    // Backdate expiry
    $pdo->exec("UPDATE trusted_devices SET expires_at = '2000-01-01 00:00:00' WHERE id = " . (int) $issued['device']->id);
    ssoAssertTrue($svc->verify($u, $issued['token']) === null, 'expired → null');
};

$tests[] = function (): void {
    // revoke + verify rejects revoked
    $f = ssoFixture();
    /** @var TrustedDeviceService $svc */
    $svc = $f['deviceService'];
    $u = ssoMakeUser(401, 'rev@example.com');
    $issued = $svc->issue($u);
    ssoAssertTrue($svc->revoke($u, (int) $issued['device']->id), 'owner can revoke');
    ssoAssertTrue($svc->verify($u, $issued['token']) === null, 'revoked → null');
};

$tests[] = function (): void {
    // revoke by non-owner needs users.update gate; default permissive gate allows
    $f = ssoFixture();
    /** @var TrustedDeviceService $svc */
    $svc = $f['deviceService'];
    /** @var SsoPermissiveGate $gate */
    $gate = $f['gate'];

    $owner = ssoMakeUser(501, 'o@example.com');
    $admin = ssoMakeUser(502, 'admin@example.com', 'manager');
    $issued = $svc->issue($owner);

    // permissive gate: admin succeeds
    ssoAssertTrue($svc->revoke($admin, (int) $issued['device']->id), 'admin with users.update allowed');

    // deny users.update to demonstrate gate fires for non-owners
    $issued2 = $svc->issue($owner);
    $gate->denials['users.update'] = true;
    ssoAssertThrows(function () use ($svc, $admin, $issued2) {
        $svc->revoke($admin, (int) $issued2['device']->id);
    }, UnauthorizedException::class, 'gate denied → throws');
};

$tests[] = function (): void {
    // revokeAllForUser
    $f = ssoFixture();
    /** @var TrustedDeviceService $svc */
    $svc = $f['deviceService'];
    $u = ssoMakeUser(601, 'all@example.com');
    $svc->issue($u, 'a');
    $svc->issue($u, 'b');
    $svc->issue($u, 'c');
    $count = $svc->revokeAllForUser($u, $u->id);
    ssoAssertSame(3, $count, 'revokes all 3');
    ssoAssertSame(0, count($svc->listForUser($u, $u->id)), 'list shows zero active');
};

$tests[] = function (): void {
    // purgeExpired
    $f = ssoFixture();
    /** @var TrustedDeviceService $svc */
    $svc = $f['deviceService'];
    /** @var TrustedDeviceRepository $repo */
    $repo = $f['deviceRepo'];
    /** @var PDO $pdo */
    $pdo = $f['pdo'];

    $u = ssoMakeUser(701, 'purge@example.com');
    $a = $svc->issue($u, 'past');
    $b = $svc->issue($u, 'future');
    $pdo->exec("UPDATE trusted_devices SET expires_at = '2000-01-01 00:00:00' WHERE id = " . (int) $a['device']->id);

    $purged = $repo->purgeExpired();
    ssoAssertSame(1, $purged, 'one row purged');
    ssoAssertTrue($repo->find((int) $a['device']->id) === null, 'past row gone');
    ssoAssertTrue($repo->find((int) $b['device']->id) !== null, 'future row kept');
};

// ---------------- runner ----------------

$pass = 0;
$fail = 0;
$errors = [];
foreach ($tests as $i => $t) {
    try {
        $t();
        $pass++;
    } catch (Throwable $e) {
        $fail++;
        $errors[] = sprintf('test #%d: %s', $i + 1, $e->getMessage());
    }
}

echo "SsoAndTrustedDeviceTest\n";
echo sprintf("  %d passed, %d failed\n", $pass, $fail);
foreach ($errors as $err) {
    echo "  - " . $err . "\n";
}
exit($fail > 0 ? 1 : 0);
