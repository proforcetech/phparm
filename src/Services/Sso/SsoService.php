<?php

namespace App\Services\Sso;

use App\Models\SsoLoginAttempt;
use App\Models\SsoProvider;
use App\Models\SsoUserLink;
use App\Models\User;
use App\Services\User\UserRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * OIDC-shaped SSO orchestrator.
 *
 * The flow:
 *   1. startLogin($slug) — generate a fresh state+nonce, persist a
 *      pending sso_login_attempts row, build the IdP authorize URL,
 *      hand it back to the caller to issue a 302.
 *   2. handleCallback($state, $code) — look up the attempt by state,
 *      exchange the code for an access token, fetch userinfo,
 *      resolve to a local user (existing link, by email match, or
 *      auto-provisioned), stamp the attempt completed, return the
 *      User for the caller to issue a JWT against.
 *
 * SAML support reuses the same provider table with type='saml'. This
 * service raises LogicException on type=saml to keep this PR bounded
 * to OIDC; SAML can be added later by switching on $provider->type
 * here without changing any callers.
 */
class SsoService
{
    public function __construct(
        private SsoProviderRepository $providers,
        private SsoUserLinkRepository $links,
        private SsoLoginAttemptRepository $attempts,
        private UserRepository $users,
        private OidcHttpClient $http,
        private ?DateTimeImmutable $now = null
    ) {
    }

    private function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable();
    }

    /**
     * @return array{provider: SsoProvider, attempt: SsoLoginAttempt, authorize_url: string, state: string}
     */
    public function startLogin(string $slug, ?string $redirectUri = null): array
    {
        $provider = $this->providers->findBySlug($slug);
        if ($provider === null || !$provider->is_active) {
            throw new InvalidArgumentException('Unknown or inactive SSO provider.');
        }
        if ($provider->type !== SsoProvider::TYPE_OIDC) {
            throw new RuntimeException('Only OIDC SSO is currently supported.');
        }
        if ($provider->client_id === null || $provider->authorize_endpoint === null || $provider->redirect_uri === null) {
            throw new RuntimeException('Provider missing required OIDC config.');
        }

        $state = $this->randomToken();
        $nonce = $this->randomToken();
        $attempt = $this->attempts->create([
            'provider_id' => $provider->id,
            'state' => $state,
            'nonce' => $nonce,
            'redirect_uri' => $redirectUri,
        ]);

        $url = $provider->authorize_endpoint . (str_contains($provider->authorize_endpoint, '?') ? '&' : '?')
            . http_build_query([
                'client_id' => $provider->client_id,
                'redirect_uri' => $provider->redirect_uri,
                'response_type' => 'code',
                'scope' => $provider->scopes,
                'state' => $state,
                'nonce' => $nonce,
            ]);

        return ['provider' => $provider, 'attempt' => $attempt, 'authorize_url' => $url, 'state' => $state];
    }

    /**
     * @return array{user: User, attempt: SsoLoginAttempt, link: SsoUserLink}
     */
    public function handleCallback(string $state, string $code): array
    {
        $attempt = $this->attempts->findByState($state);
        if ($attempt === null) {
            throw new InvalidArgumentException('Unknown SSO state — possible CSRF or expired session.');
        }
        if ($attempt->status !== SsoLoginAttempt::STATUS_PENDING) {
            throw new InvalidArgumentException('SSO state already consumed.');
        }
        $provider = $this->providers->find($attempt->provider_id);
        if ($provider === null) {
            $this->attempts->fail($attempt->id, 'Provider deleted before callback.');
            throw new RuntimeException('Provider deleted before callback.');
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
                : (is_string($userinfo['preferred_username'] ?? null) ? (string) $userinfo['preferred_username'] : null);

            $user = $this->resolveUser($provider, $subject, $email, $displayName);
            $link = $this->upsertLink($provider, $user, $subject, $email, $displayName);

            $this->attempts->complete($attempt->id, $user->id, $this->now()->format('Y-m-d H:i:s'));
            $refreshed = $this->attempts->find($attempt->id) ?? $attempt;

            return ['user' => $user, 'attempt' => $refreshed, 'link' => $link];
        } catch (\Throwable $e) {
            $this->attempts->fail($attempt->id, $e->getMessage());
            throw $e;
        }
    }

    public function unlink(User $user, int $linkId): bool
    {
        $link = $this->links->find($linkId);
        if ($link === null || $link->user_id !== $user->id) {
            return false;
        }
        return $this->links->delete($linkId);
    }

    /**
     * @return array<int, SsoUserLink>
     */
    public function listLinks(User $user): array
    {
        return $this->links->listForUser($user->id);
    }

    private function resolveUser(SsoProvider $provider, string $subject, ?string $email, ?string $displayName): User
    {
        $existingLink = $this->links->findByProviderSubject($provider->id, $subject);
        if ($existingLink !== null) {
            $user = $this->users->find($existingLink->user_id);
            if ($user === null) {
                throw new RuntimeException('Linked user not found — link orphaned.');
            }
            if (!$user->active) {
                throw new RuntimeException('Linked user account is inactive.');
            }
            return $user;
        }

        if ($email !== null) {
            $byEmail = $this->users->findByEmail($email);
            if ($byEmail !== null) {
                if (!$byEmail->active) {
                    throw new RuntimeException('Matching email user is inactive.');
                }
                return $byEmail;
            }
        }

        if ($provider->auto_provision) {
            if ($email === null) {
                throw new RuntimeException('Cannot auto-provision: provider did not return an email claim.');
            }
            $created = $this->users->create([
                'name' => $displayName ?? $email,
                'email' => $email,
                'password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'role' => $provider->default_role ?? 'customer',
                'email_verified' => true,
                'active' => true,
            ]);
            return $created;
        }

        throw new RuntimeException('No matching local user, and auto-provisioning is disabled.');
    }

    private function upsertLink(
        SsoProvider $provider,
        User $user,
        string $subject,
        ?string $email,
        ?string $displayName
    ): SsoUserLink {
        $link = $this->links->findByProviderSubject($provider->id, $subject);
        if ($link === null) {
            $link = $this->links->create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'subject' => $subject,
                'email' => $email,
                'display_name' => $displayName,
                'last_login_at' => $this->now()->format('Y-m-d H:i:s'),
            ]);
            return $link;
        }
        if ($provider->sync_profile_on_login) {
            $this->links->syncProfile($link->id, ['email' => $email, 'display_name' => $displayName]);
        }
        $this->links->touchLogin($link->id, $this->now()->format('Y-m-d H:i:s'));
        return $this->links->find($link->id) ?? $link;
    }

    private function randomToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
