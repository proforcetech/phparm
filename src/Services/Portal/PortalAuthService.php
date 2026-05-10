<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalAccount;
use App\Models\User;
use App\Services\Crm\CompanyRepository;
use App\Services\Crm\SiteRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\JwtService;
use App\Support\Auth\PasswordPolicy;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Phase 6.1 of docs/expansion-plan.md — isolated customer-portal auth.
 *
 * Why a second auth service instead of extending AuthService::staffLogin:
 *   * Portal users must carry scope (company_id + optional site list) into
 *     every downstream check; staff tokens are global. Threading scope
 *     through the existing staffLogin would force every caller to handle a
 *     nullable scope, creating "forgot to check" bugs across modules.
 *   * Portal provisioning is gated on users.create (admin) and driven by
 *     portal_accounts rows — distinct from staff registerStaff which issues
 *     roles with different 2FA requirements. Two entry points keep each
 *     flow's invariants local.
 *   * allow_registration=false means there is no public signup for portal
 *     users — provision() is the ONLY create path and it asserts an admin
 *     actor.
 *
 * Token shape:
 *   generateToken(user, [
 *       'scope' => 'portal',
 *       'portal_account_id' => $account->id,
 *       'company_id' => $account->company_id,
 *       'site_ids' => $account->allowed_site_ids,  // null = all
 *   ])
 * Portal middleware re-reads the claims and rejects any mismatch with the
 * portal_accounts row (so revocation takes effect within one request even
 * if the JWT hasn't expired).
 */
class PortalAuthService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PortalAccountRepository $accounts,
        private readonly CompanyRepository $companies,
        private readonly SiteRepository $sites,
        private readonly JwtService $jwt,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
        /** @var array<string, mixed> */
        private readonly array $config,
        private readonly ?PortalPermissionService $permissions = null,
    ) {
    }

    /**
     * Authenticate a portal user. Returns tokens + the scope row.
     *
     * Failures return null (caller issues a generic 401) to avoid leaking
     * whether the email, role, password, or scope was the disqualifier.
     *
     * @return array{
     *   access_token: string,
     *   refresh_token: string,
     *   expires_in: int,
     *   user: User,
     *   portal_account: PortalAccount
     * }|null
     */
    public function login(
        string $email,
        string $password,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ?array {
        if (($this->config['customer_portal']['login_enabled'] ?? true) !== true) {
            throw new RuntimeException('Customer portal login is disabled.');
        }

        $user = $this->findUserByEmail($email);
        if ($user === null || $user->role !== 'portal_user') {
            return null;
        }
        if (!password_verify($password, $user->password)) {
            return null;
        }
        if (($this->config['verification']['require_customer_verification'] ?? false)
            && !$user->email_verified
        ) {
            return null;
        }

        $account = $this->accounts->findActiveByUserId($user->id);
        if ($account === null || !$account->isUsable()) {
            return null;
        }

        $this->accounts->recordLogin($account->id);

        // role_tier + scope ride along inside the JWT for transparency in
        // logs/inspectors, but the authoritative source is the row —
        // assertValidSession() re-reads the row on every request so a
        // tier downgrade / scope rewrite takes effect within one request.
        $claims = [
            'scope' => 'portal',
            'portal_account_id' => $account->id,
            'company_id' => $account->company_id,
            'site_ids' => $account->allowed_site_ids,
            'role_tier' => $account->role_tier,
        ];
        $accessToken = $this->jwt->generateToken($user, $claims);
        $refreshToken = $this->jwt->generateRefreshToken($user, null, $ipAddress, $userAgent);

        $this->audit->log(new AuditEntry(
            'portal.auth.login',
            'portal_account',
            $account->id,
            $user->id,
            [
                'company_id' => $account->company_id,
                'site_ids' => $account->allowed_site_ids,
                'ip_address' => $ipAddress,
            ]
        ));

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->jwt->getTokenTtl(),
            'user' => $user,
            'portal_account' => $account,
        ];
    }

    /**
     * Provision a new portal account (admin-only path).
     *
     * Two shapes supported:
     *   (a) create a fresh user+account from {name, email, password, company_id, site_ids}
     *   (b) bind an existing user to a company via {user_id, company_id, site_ids}
     *
     * allow_registration=false guards PUBLIC signup; this is the staff
     * provisioning path and is always enabled when an admin actor has
     * users.create.
     *
     * @param array<string, mixed> $input
     */
    public function provisionAccount(User $actor, array $input): PortalAccount
    {
        $this->gate->assert($actor, 'users.create');

        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId <= 0 || $this->companies->findById($companyId) === null) {
            throw new InvalidArgumentException('company_id is required and must reference an existing company');
        }

        $siteIds = $this->normalizeSiteIds($input['site_ids'] ?? null, $companyId);

        if (!empty($input['user_id'])) {
            $user = $this->findUserById((int) $input['user_id']);
            if ($user === null) {
                throw new InvalidArgumentException("user id={$input['user_id']} not found");
            }
            if ($user->role !== 'portal_user') {
                throw new InvalidArgumentException(
                    "user id={$user->id} has role {$user->role}; only role=portal_user can be bound to a portal_account"
                );
            }
        } else {
            $email = trim((string) ($input['email'] ?? ''));
            $password = (string) ($input['password'] ?? '');
            $name = trim((string) ($input['name'] ?? $email));
            if ($email === '' || $password === '') {
                throw new InvalidArgumentException('email and password are required when user_id is not supplied');
            }
            $this->passwordPolicy->assertPasswordStrength($password);
            if ($this->findUserByEmail($email) !== null) {
                throw new InvalidArgumentException("a user with email {$email} already exists — pass user_id to bind instead");
            }
            $user = $this->createPortalUser($name, $email, $password);
        }

        $roleTier = $this->normalizeTier($input['role_tier'] ?? null) ?? PortalPermission::TIER_REQUESTER;
        $scope = $this->normalizeScope($input['scope'] ?? null);

        $account = $this->accounts->provision(
            $user->id,
            $companyId,
            $siteIds,
            $actor->id ?? null,
            isset($input['notes']) ? (string) $input['notes'] : null,
            $roleTier,
            $scope,
        );

        $this->audit->log(new AuditEntry(
            'portal.account.provisioned',
            'portal_account',
            $account->id,
            $actor->id ?? null,
            [
                'user_id' => $user->id,
                'company_id' => $companyId,
                'site_ids' => $siteIds,
                'role_tier' => $roleTier,
                'scope' => $scope,
            ]
        ));

        return $account;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateAccount(User $actor, int $accountId, array $input): PortalAccount
    {
        $this->gate->assert($actor, 'users.create');
        $existing = $this->accounts->findById($accountId);
        if ($existing === null) {
            throw new InvalidArgumentException("portal_account id={$accountId} not found");
        }
        $siteIds = array_key_exists('site_ids', $input)
            ? $this->normalizeSiteIds($input['site_ids'], $existing->company_id)
            : $existing->allowed_site_ids;
        $notes = array_key_exists('notes', $input) ? (string) $input['notes'] : $existing->notes;
        $roleTier = array_key_exists('role_tier', $input)
            ? $this->normalizeTier($input['role_tier'])
            : null;
        $touchScope = array_key_exists('scope', $input);
        $scope = $touchScope ? $this->normalizeScope($input['scope']) : null;

        $updated = $this->accounts->updateScope(
            $existing->id,
            $siteIds,
            $notes,
            $roleTier,
            $scope,
            $touchScope,
        );

        $this->audit->log(new AuditEntry(
            'portal.account.updated',
            'portal_account',
            $updated->id,
            $actor->id ?? null,
            [
                'site_ids' => $siteIds,
                'role_tier' => $roleTier,
                'scope_touched' => $touchScope,
                'scope' => $touchScope ? $scope : null,
            ]
        ));

        return $updated;
    }

    public function revokeAccount(User $actor, int $accountId, string $reason): void
    {
        $this->gate->assert($actor, 'users.create');
        $existing = $this->accounts->findById($accountId);
        if ($existing === null) {
            throw new InvalidArgumentException("portal_account id={$accountId} not found");
        }
        if ($existing->revoked_at !== null) {
            return;
        }
        $this->accounts->revoke($accountId, $reason);

        $this->audit->log(new AuditEntry(
            'portal.account.revoked',
            'portal_account',
            $accountId,
            $actor->id ?? null,
            ['reason' => $reason]
        ));
    }

    /**
     * Re-validate an already-decoded portal JWT against the authoritative
     * portal_accounts row. Enforces live revocation regardless of token
     * TTL. Called by the portal middleware on every request.
     *
     * @param array<string, mixed> $payload
     */
    public function assertValidSession(User $user, array $payload): PortalAccount
    {
        if (($payload['scope'] ?? null) !== 'portal') {
            throw new UnauthorizedException('portal scope required');
        }
        if ($user->role !== 'portal_user') {
            throw new UnauthorizedException('portal scope rejected: user is not portal_user');
        }
        $accountId = (int) ($payload['portal_account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new UnauthorizedException('portal_account_id claim missing');
        }
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new UnauthorizedException('portal_account not found');
        }
        if ($account->user_id !== $user->id) {
            throw new UnauthorizedException('portal_account does not belong to authenticated user');
        }
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is inactive or revoked');
        }
        if ($account->company_id !== (int) ($payload['company_id'] ?? 0)) {
            throw new UnauthorizedException('portal token company_id does not match portal_account');
        }
        return $account;
    }

    public function assertSiteAccess(PortalAccount $account, int $siteId): void
    {
        if (!$account->allowsSite($siteId)) {
            throw new UnauthorizedException("portal_account cannot access site {$siteId}");
        }
    }

    /**
     * Phase 2f — resolve {user, account} for a successfully-authenticated
     * portal_api_token. The token has already been validated for
     * presence/expiry/secret-match by PortalApiTokenService::authenticate;
     * here we only enforce the same liveness checks the JWT path runs
     * (active account, active user, role still portal_user). Returning
     * null lets the middleware reject with a generic 401 rather than
     * leaking which check failed.
     *
     * @return array{user: User, account: PortalAccount}|null
     */
    public function resolveByApiToken(int $portalAccountId): ?array
    {
        if ($portalAccountId <= 0) {
            return null;
        }
        $account = $this->accounts->findById($portalAccountId);
        if ($account === null || !$account->isUsable()) {
            return null;
        }
        $user = $this->findUserById($account->user_id);
        if ($user === null || $user->role !== 'portal_user') {
            return null;
        }
        return ['user' => $user, 'account' => $account];
    }

    /**
     * Gate for the portal-account admin list endpoint. Same permission as
     * provisioning — viewing who has portal access is staff-only material.
     */
    public function assertListAccess(User $actor): void
    {
        $this->gate->assert($actor, 'users.create');
    }

    private function normalizeTier(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw new InvalidArgumentException('role_tier must be a string');
        }
        $tier = strtolower(trim($raw));
        if (!PortalPermission::isValidTier($tier)) {
            throw new InvalidArgumentException(
                'role_tier must be one of: ' . implode(', ', PortalPermission::TIERS)
            );
        }
        return $tier;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeScope(mixed $raw): ?array
    {
        $service = $this->permissions ?? new PortalPermissionService();
        return $service->normalizeScope($raw);
    }

    /**
     * @param mixed $raw
     * @return array<int, int>|null
     */
    private function normalizeSiteIds(mixed $raw, int $companyId): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        if (!is_array($raw)) {
            throw new InvalidArgumentException('site_ids must be an array or null (null = all sites in company)');
        }
        $normalized = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id <= 0) {
                throw new InvalidArgumentException('site_ids must contain positive integers');
            }
            $site = $this->sites->findById($id);
            if ($site === null) {
                throw new InvalidArgumentException("site id={$id} not found");
            }
            if ((int) ($site->company_id ?? 0) !== $companyId) {
                throw new InvalidArgumentException(
                    "site id={$id} belongs to a different company — cannot scope a portal account to cross-company sites"
                );
            }
            $normalized[] = $id;
        }
        return array_values(array_unique($normalized));
    }

    // User CRUD is done inline (not through UserRepository) because the
    // portal auth flow needs precise control over the role and active/
    // email_verified columns. These three methods are protected so tests
    // can override them with an in-memory store without needing sqlite.
    protected function createPortalUser(string $name, string $email, string $password): User
    {
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO users (name, email, password, password_changed_at, must_change_password, role, active, email_verified, created_at, updated_at)
             VALUES (:name, :email, :password, NOW(), 0, :role, 1, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password' => $passwordHash,
            'role' => 'portal_user',
        ]);
        $userId = (int) $this->connection->pdo()->lastInsertId();
        $user = $this->findUserById($userId);
        if ($user === null) {
            throw new RuntimeException("failed to reload user id={$userId} after insert");
        }
        return $user;
    }

    protected function findUserByEmail(string $email): ?User
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM users WHERE email = :email AND active = 1 LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new User($row) : null;
    }

    protected function findUserById(int $id): ?User
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM users WHERE id = :id AND active = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new User($row) : null;
    }
}
