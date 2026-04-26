<?php

namespace App\Services\Sso;

use App\Models\SsoProvider;
use App\Models\SsoUserLink;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\JwtService;
use InvalidArgumentException;

/**
 * HTTP edge for the SSO surface.
 *
 * Three audiences:
 *   1. Anonymous login screen     — listProviders() lists active providers
 *      without leaking client_secrets, start() returns the IdP authorize URL
 *      to redirect to, callback() finishes the exchange and issues a JWT.
 *   2. Authenticated end users    — listMyLinks() / unlink() so the user can
 *      see and prune their own social/SSO connections.
 *   3. Admins (sso_providers.*)   — listAll/create/update/delete provider
 *      configs. listAll exposes more detail (endpoints + flags) than the
 *      anonymous list since it's behind the manage gate.
 */
class SsoController
{
    public function __construct(
        private SsoService $service,
        private SsoProviderRepository $providers,
        private SsoUserLinkRepository $links,
        private JwtService $jwtService,
        private AccessGate $gate
    ) {
    }

    /**
     * Public: enumerate the providers the login screen can offer. Returns a
     * minimal projection — enough to render a button (slug, name, type) but
     * never the secrets, endpoints, or scopes.
     *
     * @return array<string, mixed>
     */
    public function listActiveProviders(): array
    {
        $items = array_map(static fn (SsoProvider $p): array => [
            'slug' => $p->slug,
            'name' => $p->name,
            'type' => $p->type,
        ], $this->providers->listActive());

        return ['data' => $items];
    }

    /**
     * Public: kick off the OIDC flow for a slug. Returns the URL the caller
     * (browser, mobile app) should redirect to. The state is also returned
     * so a caller that wants to set its own state cookie can — the DB row is
     * already authoritative on the server side, the cookie is defense in
     * depth.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function start(string $slug, array $payload): array
    {
        $redirectUri = isset($payload['redirect_uri']) ? (string) $payload['redirect_uri'] : null;
        $result = $this->service->startLogin($slug, $redirectUri);

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
     * Public: handle the IdP redirect-back. Validates state, exchanges code,
     * resolves user, and issues a fresh JWT pair for the resolved local user.
     * Returns the same envelope as POST /api/auth/login so the React shell can
     * treat SSO and password logins identically.
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

        $accessToken = $this->jwtService->generateToken($user);
        $refreshToken = $this->jwtService->generateRefreshToken($user);

        $isSecure = JwtService::isSecureContext();
        $this->jwtService->setTokenCookies($accessToken, $refreshToken, $isSecure);

        return ['data' => [
            'user' => $user->toArray(),
            'token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->jwtService->getTokenTtl(),
            'token_type' => 'Bearer',
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
    public function listMyLinks(User $actor): array
    {
        $items = array_map(static fn (SsoUserLink $l): array => $l->toArray(), $this->links->listForUser($actor->id));
        return ['data' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function unlinkMyLink(User $actor, int $linkId): array
    {
        $deleted = $this->service->unlink($actor, $linkId);
        return ['data' => ['deleted' => $deleted]];
    }

    /**
     * Admin: full provider list including endpoint config (still no client_secret).
     *
     * @return array<string, mixed>
     */
    public function adminListProviders(User $actor): array
    {
        $this->gate->assert($actor, 'sso_providers.view');
        $items = array_map(
            fn (SsoProvider $p): array => $this->serializeProviderForAdmin($p),
            $this->providers->listAll()
        );
        return ['data' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminGetProvider(User $actor, int $id): array
    {
        $this->gate->assert($actor, 'sso_providers.view');
        $provider = $this->providers->find($id);
        if ($provider === null) {
            throw new InvalidArgumentException('SSO provider not found.');
        }
        return ['data' => $this->serializeProviderForAdmin($provider)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function adminCreateProvider(User $actor, array $payload): array
    {
        $this->gate->assert($actor, 'sso_providers.manage');
        $this->validateProviderPayload($payload, true);

        $provider = $this->providers->create($payload);
        return ['data' => $this->serializeProviderForAdmin($provider)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function adminUpdateProvider(User $actor, int $id, array $payload): array
    {
        $this->gate->assert($actor, 'sso_providers.manage');
        if ($this->providers->find($id) === null) {
            throw new InvalidArgumentException('SSO provider not found.');
        }
        $this->validateProviderPayload($payload, false);

        $provider = $this->providers->update($id, $payload);
        if ($provider === null) {
            throw new InvalidArgumentException('SSO provider not found.');
        }
        return ['data' => $this->serializeProviderForAdmin($provider)];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminDeleteProvider(User $actor, int $id): array
    {
        $this->gate->assert($actor, 'sso_providers.manage');
        $deleted = $this->providers->delete($id);
        return ['data' => ['deleted' => $deleted]];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProviderForAdmin(SsoProvider $p): array
    {
        $arr = $p->toArray();
        // Never round-trip the client secret to admins; show a boolean instead.
        $arr['has_client_secret'] = $p->client_secret !== null && $p->client_secret !== '';
        unset($arr['client_secret']);
        return $arr;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateProviderPayload(array $payload, bool $isCreate): void
    {
        if ($isCreate) {
            foreach (['slug', 'name'] as $required) {
                if (empty($payload[$required])) {
                    throw new InvalidArgumentException($required . ' is required.');
                }
            }
        }
        if (isset($payload['type']) && !in_array($payload['type'], SsoProvider::TYPES, true)) {
            throw new InvalidArgumentException('Invalid provider type: ' . $payload['type']);
        }
        // For OIDC, certain fields are required to actually run a flow. We
        // allow saving a half-configured row (admins may want to stage in
        // pieces), but startLogin() will reject it at runtime.
    }
}
