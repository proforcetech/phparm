<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;
use App\Support\Auth\JwtService;
use InvalidArgumentException;

/**
 * HTTP edge for portal-side SSO.
 *
 * Mirrors SsoController but lands the resolved identity on a portal_accounts
 * row rather than a staff users row, and issues a portal-scoped JWT (matching
 * what PortalAuthService::login produces) so the React portal shell can treat
 * SSO and password logins identically.
 *
 * No admin/management surface here — provider CRUD stays in SsoController
 * (admin-gated). This controller only exposes the public anon-side login flow.
 */
class PortalSsoController
{
    public function __construct(
        private readonly PortalSsoService $service,
        private readonly JwtService $jwt,
        private readonly ?PortalPermissionService $permissions = null,
    ) {
    }

    /**
     * Public: list portal-enabled providers visible to a given company.
     * Caller passes ?company_id=N (resolved from the white-label host or
     * from the React app's current tenant context).
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listProviders(array $query): array
    {
        $companyId = (int) ($query['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company_id is required.');
        }
        return ['data' => $this->service->listProvidersForCompany($companyId)];
    }

    /**
     * Public: kick off the OIDC flow for a portal user.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function start(string $slug, array $payload): array
    {
        $companyId = (int) ($payload['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company_id is required.');
        }
        $redirectUri = isset($payload['redirect_uri']) ? (string) $payload['redirect_uri'] : null;
        $result = $this->service->startLogin($slug, $companyId, $redirectUri);

        return ['data' => [
            'authorize_url' => $result['authorize_url'],
            'state' => $result['state'],
            'provider' => [
                'slug' => $result['provider']->slug,
                'name' => $result['provider']->name,
            ],
        ]];
    }

    /**
     * Public: handle the IdP redirect-back. On success returns a portal-scoped
     * JWT pair the React shell can drop into the portal auth store.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function callback(array $query): array
    {
        $state = isset($query['state']) ? (string) $query['state'] : '';
        $code = isset($query['code']) ? (string) $query['code'] : '';
        if ($state === '' || $code === '') {
            throw new InvalidArgumentException('Missing state or code in SSO callback.');
        }

        $result = $this->service->handleCallback($state, $code);
        $user = $result['user'];
        $account = $result['portal_account'];

        $claims = [
            'scope' => 'portal',
            'portal_account_id' => $account->id,
            'company_id' => $account->company_id,
            'site_ids' => $account->allowed_site_ids,
            'role_tier' => $account->role_tier,
        ];
        $accessToken = $this->jwt->generateToken($user, $claims);
        $refreshToken = $this->jwt->generateRefreshToken($user);

        return ['data' => [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->jwt->getTokenTtl(),
            'token_type' => 'Bearer',
            'user' => $this->serializeUser($user),
            'portal_account' => $this->serializeAccount($account),
            'sso' => [
                'provider_id' => $result['link']->provider_id,
                'link_id' => $result['link']->id,
                'redirect_uri' => $result['attempt']->redirect_uri,
            ],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAccount(PortalAccount $account): array
    {
        $permissions = $this->permissions ?? new PortalPermissionService();
        return [
            'id' => $account->id,
            'user_id' => $account->user_id,
            'company_id' => $account->company_id,
            'allowed_site_ids' => $account->allowed_site_ids,
            'role_tier' => $account->role_tier,
            'scope' => $account->scope,
            'permissions' => $permissions->effective($account),
            'is_active' => $account->is_active,
            'last_login_at' => $account->last_login_at,
        ];
    }
}
