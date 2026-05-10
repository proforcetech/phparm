<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalAccount;
use App\Models\SsoLoginAttempt;
use App\Models\SsoProvider;
use App\Models\SsoUserLink;
use App\Models\User;
use App\Services\Crm\CompanyRepository;
use App\Services\Sso\OidcHttpClient;
use App\Services\Sso\SsoLoginAttemptRepository;
use App\Services\Sso\SsoProviderRepository;
use App\Services\Sso\SsoUserLinkRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Decision D — portal-side OIDC orchestrator.
 *
 * Mirrors SsoService for portal accounts instead of staff users. A separate
 * service (rather than extending SsoService) keeps the resolution invariants
 * local: portal SSO must always land on a portal_accounts row whose
 * company_id matches the IdP's intended_company, and must never resolve to a
 * staff users row even if the email collides.
 *
 * Provider scoping (set on sso_providers):
 *   * company_id IS NULL  → "global" — any company may use it; intended_company
 *     must be supplied by the caller (start endpoint param).
 *   * company_id IS NOT NULL → company-scoped — intended_company is implied.
 *
 * Resolution order at callback:
 *   1. existing sso_user_links row by (provider, subject) with portal_account_id
 *      set → use that portal_account.
 *   2. find a users row with role=portal_user matching the email claim,
 *      then find their active portal_account in intended_company. If found,
 *      create the link. If not found, refuse — there is NO auto-provisioning
 *      of portal accounts via SSO (provisioning is admin-gated; SSO is for
 *      authentication of already-provisioned accounts).
 *
 * On success the caller (PortalSsoController) issues a portal-scoped JWT via
 * PortalAuthService just like a password login would.
 */
class PortalSsoService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SsoProviderRepository $providers,
        private readonly SsoUserLinkRepository $links,
        private readonly SsoLoginAttemptRepository $attempts,
        private readonly PortalAccountRepository $accounts,
        private readonly CompanyRepository $companies,
        private readonly OidcHttpClient $http,
        private readonly AuditLogger $audit,
        private readonly ?DateTimeImmutable $now = null,
    ) {
    }

    private function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable();
    }

    /**
     * Public projection of providers a portal login screen can offer for
     * a given company. Strips secrets/endpoints/scopes — anonymous data only.
     *
     * @return array<int, array{slug: string, name: string, type: string}>
     */
    public function listProvidersForCompany(int $companyId): array
    {
        return array_map(
            static fn (SsoProvider $p): array => [
                'slug' => $p->slug,
                'name' => $p->name,
                'type' => $p->type,
            ],
            $this->providers->listForPortal($companyId)
        );
    }

    /**
     * Begin the OIDC dance for a portal login.
     *
     * @return array{provider: SsoProvider, attempt: SsoLoginAttempt, authorize_url: string, state: string}
     */
    public function startLogin(string $slug, int $intendedCompanyId, ?string $redirectUri = null): array
    {
        if ($intendedCompanyId <= 0) {
            throw new InvalidArgumentException('intended_company_id is required for portal SSO.');
        }
        if ($this->companies->findById($intendedCompanyId) === null) {
            throw new InvalidArgumentException('Unknown company.');
        }

        $provider = $this->providers->findActiveBySlugForPortal($slug, $intendedCompanyId);
        if ($provider === null) {
            throw new InvalidArgumentException('Unknown or inactive SSO provider for this company.');
        }
        if ($provider->type !== SsoProvider::TYPE_OIDC) {
            throw new RuntimeException('Only OIDC SSO is currently supported.');
        }
        if (
            $provider->client_id === null
            || $provider->authorize_endpoint === null
            || $provider->redirect_uri === null
        ) {
            throw new RuntimeException('Provider missing required OIDC config.');
        }

        $state = $this->randomToken();
        $nonce = $this->randomToken();
        $attempt = $this->attempts->create([
            'provider_id' => $provider->id,
            'side' => SsoLoginAttempt::SIDE_PORTAL,
            'state' => $state,
            'nonce' => $nonce,
            'redirect_uri' => $redirectUri,
            'intended_company_id' => $intendedCompanyId,
        ]);

        $url = $provider->authorize_endpoint
            . (str_contains($provider->authorize_endpoint, '?') ? '&' : '?')
            . http_build_query([
                'client_id' => $provider->client_id,
                'redirect_uri' => $provider->redirect_uri,
                'response_type' => 'code',
                'scope' => $provider->scopes,
                'state' => $state,
                'nonce' => $nonce,
            ]);

        return [
            'provider' => $provider,
            'attempt' => $attempt,
            'authorize_url' => $url,
            'state' => $state,
        ];
    }

    /**
     * Finalize the OIDC dance and resolve to a portal_account.
     *
     * @return array{user: User, portal_account: PortalAccount, attempt: SsoLoginAttempt, link: SsoUserLink}
     */
    public function handleCallback(string $state, string $code): array
    {
        $attempt = $this->attempts->findByState($state);
        if ($attempt === null) {
            throw new InvalidArgumentException('Unknown SSO state — possible CSRF or expired session.');
        }
        if ($attempt->side !== SsoLoginAttempt::SIDE_PORTAL) {
            throw new InvalidArgumentException('SSO state is not a portal attempt.');
        }
        if ($attempt->status !== SsoLoginAttempt::STATUS_PENDING) {
            throw new InvalidArgumentException('SSO state already consumed.');
        }
        $intendedCompanyId = $attempt->intended_company_id;
        if ($intendedCompanyId === null || $intendedCompanyId <= 0) {
            $this->attempts->fail($attempt->id, 'Portal attempt missing intended_company_id.');
            throw new RuntimeException('Portal attempt missing intended_company_id.');
        }
        $provider = $this->providers->find($attempt->provider_id);
        if ($provider === null) {
            $this->attempts->fail($attempt->id, 'Provider deleted before callback.');
            throw new RuntimeException('Provider deleted before callback.');
        }
        if (!$provider->is_active || !$provider->portal_enabled) {
            $this->attempts->fail($attempt->id, 'Provider no longer enabled for portal.');
            throw new RuntimeException('Provider no longer enabled for portal.');
        }
        if ($provider->company_id !== null && $provider->company_id !== $intendedCompanyId) {
            $this->attempts->fail($attempt->id, 'Provider scope changed mid-flow.');
            throw new RuntimeException('Provider scope changed mid-flow.');
        }

        try {
            $tokenResponse = $this->http->postForm(
                (string) $provider->token_endpoint,
                [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => (string) $provider->redirect_uri,
                    'client_id' => (string) $provider->client_id,
                    'client_secret' => (string) ($provider->client_secret ?? ''),
                ]
            );

            $accessToken = $tokenResponse['access_token'] ?? null;
            if (!is_string($accessToken) || $accessToken === '') {
                throw new RuntimeException('Token response missing access_token.');
            }

            $userinfo = $this->http->getJson((string) $provider->userinfo_endpoint, $accessToken);
            $subject = $userinfo['sub'] ?? null;
            if (!is_string($subject) || $subject === '') {
                throw new RuntimeException('Userinfo missing subject.');
            }

            $email = is_string($userinfo['email'] ?? null) ? (string) $userinfo['email'] : null;
            $displayName = is_string($userinfo['name'] ?? null)
                ? (string) $userinfo['name']
                : (is_string($userinfo['preferred_username'] ?? null)
                    ? (string) $userinfo['preferred_username']
                    : null);

            [$user, $account] = $this->resolvePortalAccount(
                $provider,
                $intendedCompanyId,
                $subject,
                $email
            );
            $link = $this->upsertLink($provider, $account, $subject, $email, $displayName);

            $this->accounts->recordLogin($account->id);
            $this->attempts->completePortal($attempt->id, $account->id, $this->now()->format('Y-m-d H:i:s'));
            $refreshed = $this->attempts->find($attempt->id) ?? $attempt;

            $this->audit->log(new AuditEntry(
                'portal.sso.login',
                'portal_account',
                $account->id,
                $user->id,
                [
                    'provider_id' => $provider->id,
                    'provider_slug' => $provider->slug,
                    'company_id' => $account->company_id,
                ]
            ));

            return [
                'user' => $user,
                'portal_account' => $account,
                'attempt' => $refreshed,
                'link' => $link,
            ];
        } catch (\Throwable $e) {
            $this->attempts->fail($attempt->id, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{0: User, 1: PortalAccount}
     */
    private function resolvePortalAccount(
        SsoProvider $provider,
        int $intendedCompanyId,
        string $subject,
        ?string $email
    ): array {
        $existingLink = $this->links->findByProviderSubject($provider->id, $subject);
        if ($existingLink !== null && $existingLink->portal_account_id !== null) {
            $account = $this->accounts->findById($existingLink->portal_account_id);
            if ($account === null || !$account->isUsable()) {
                throw new RuntimeException('Linked portal account is unavailable.');
            }
            if ($account->company_id !== $intendedCompanyId) {
                throw new RuntimeException(
                    'Linked portal account belongs to a different company than the intended target.'
                );
            }
            $user = $this->loadPortalUser($account->user_id);
            return [$user, $account];
        }

        if ($email === null) {
            throw new RuntimeException(
                'No existing link, and provider did not return an email claim — cannot resolve portal account.'
            );
        }
        $user = $this->findPortalUserByEmail($email);
        if ($user === null) {
            throw new RuntimeException(
                'No portal user with that email — portal accounts must be provisioned by an admin before SSO can log them in.'
            );
        }
        $account = $this->accounts->findByUserAndCompany($user->id, $intendedCompanyId);
        if ($account === null || !$account->isUsable()) {
            throw new RuntimeException(
                'No active portal account for this user in the intended company.'
            );
        }
        return [$user, $account];
    }

    private function upsertLink(
        SsoProvider $provider,
        PortalAccount $account,
        string $subject,
        ?string $email,
        ?string $displayName
    ): SsoUserLink {
        $link = $this->links->findByProviderSubject($provider->id, $subject);
        if ($link === null) {
            return $this->links->create([
                'portal_account_id' => $account->id,
                'provider_id' => $provider->id,
                'subject' => $subject,
                'email' => $email,
                'display_name' => $displayName,
                'last_login_at' => $this->now()->format('Y-m-d H:i:s'),
            ]);
        }
        if ($link->portal_account_id !== null && $link->portal_account_id !== $account->id) {
            throw new RuntimeException('SSO subject already linked to a different portal account.');
        }
        if ($link->portal_account_id === null) {
            // Subject existed as a staff link only — refuse to silently bind it to a portal
            // account, as that would let a staff SSO link bypass the portal provisioning gate.
            throw new RuntimeException('SSO subject is already linked to a staff user — unlink it before reusing for portal SSO.');
        }
        if ($provider->sync_profile_on_login) {
            $this->links->syncProfile($link->id, ['email' => $email, 'display_name' => $displayName]);
        }
        $this->links->touchLogin($link->id, $this->now()->format('Y-m-d H:i:s'));
        return $this->links->find($link->id) ?? $link;
    }

    private function loadPortalUser(int $userId): User
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT * FROM users WHERE id = :id AND role = 'portal_user' AND active = 1 LIMIT 1"
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Portal user record not found or inactive.');
        }
        return new User($row);
    }

    private function findPortalUserByEmail(string $email): ?User
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT * FROM users WHERE email = :email AND role = 'portal_user' AND active = 1 LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new User($row) : null;
    }

    private function randomToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
