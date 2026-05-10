<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\PortalApiToken;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 2f — self-issued portal API tokens.
 *
 * Token format: `pat_<prefix>.<secret>` where:
 *   - `pat_` is a fixed scheme tag the auth middleware uses to route
 *     bearer tokens between JWT and API-token paths.
 *   - `<prefix>` is 12 url-safe random chars stored verbatim in
 *     token_prefix; it's the lookup key (UNIQUE column) so we don't
 *     have to bcrypt-compare every row on every request.
 *   - `<secret>` is 40 url-safe random chars; only its bcrypt hash
 *     lives in the DB. The full secret is shown to the user exactly
 *     once at creation time.
 *
 * Scopes: NULL = "use whatever permissions the issuing portal_account
 * had at request time" (so a tier downgrade still binds the token).
 * A non-null scope array is the explicit set; tokens cannot grant
 * permissions the account doesn't currently hold — verification must
 * still pass through PortalPermissionService::can() in the request
 * handler. We don't enforce subset at issue time because the account's
 * permissions are evaluated server-side and may include grants we
 * can't fully enumerate (filter scopes etc.). Instead we accept any
 * subset of the well-known permission strings here, and let the
 * runtime check fail closed.
 *
 * Expiration is optional. Revocation flips revoked_at; the row stays so
 * the audit trail keeps a fingerprint of which token did what.
 */
class PortalApiTokenService
{
    public const TOKEN_SCHEME = 'pat_';
    private const PREFIX_LEN = 12;
    private const SECRET_LEN = 40;
    private const NAME_MAX = 120;

    public function __construct(
        private readonly PortalApiTokenRepository $tokens,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Issue a new token. Returns both the persisted row AND the plaintext
     * full token so the caller can show it to the user once.
     *
     * @param array<string, mixed> $input
     * @return array{token: PortalApiToken, plaintext: string}
     */
    public function issue(User $user, PortalAccount $account, array $input): array
    {
        $this->assertUsable($account);

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('token name is required');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidArgumentException('token name is too long');
        }

        $scopes = $this->normalizeScopes($input['scopes'] ?? null);
        $expiresAt = $this->normalizeExpiresAt($input['expires_at'] ?? null);

        // Collision-resistant prefix; if the rare birthday collision happens
        // we just retry. UNIQUE on token_prefix gates duplicates at the DB.
        $prefix = $this->randomUrlSafe(self::PREFIX_LEN);
        $secret = $this->randomUrlSafe(self::SECRET_LEN);
        $hash = password_hash($secret, PASSWORD_BCRYPT);
        if ($hash === false) {
            throw new RuntimeException('failed to hash API token secret');
        }

        $row = $this->tokens->create(
            $account->id,
            $name,
            $prefix,
            $hash,
            $scopes,
            $expiresAt,
        );

        $this->audit->log(new AuditEntry(
            'portal.api_token.issued',
            'portal_api_token',
            $row->id,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'token_prefix' => $prefix,
                'name' => $name,
                'scopes' => $scopes,
                'expires_at' => $expiresAt,
            ]
        ));

        return [
            'token' => $row,
            'plaintext' => self::TOKEN_SCHEME . $prefix . '.' . $secret,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAccount(User $user, PortalAccount $account): array
    {
        $this->assertUsable($account);
        return array_map(
            fn(PortalApiToken $t) => $this->serialize($t),
            $this->tokens->listForAccount($account->id)
        );
    }

    public function revoke(User $user, PortalAccount $account, int $tokenId): void
    {
        $this->assertUsable($account);
        $row = $this->tokens->findById($tokenId);
        if ($row === null || $row->portal_account_id !== $account->id) {
            // Don't leak whether the ID exists for someone else's account.
            throw new InvalidArgumentException("api token {$tokenId} not found");
        }
        if ($row->revoked_at !== null) {
            return;
        }
        $this->tokens->revoke($tokenId);

        $this->audit->log(new AuditEntry(
            'portal.api_token.revoked',
            'portal_api_token',
            $tokenId,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'token_prefix' => $row->token_prefix,
            ]
        ));
    }

    /**
     * Auth-side resolver: given a `pat_*` bearer string, return the row or
     * null on any failure (unknown prefix, hash mismatch, revoked, expired).
     * Records last_used_at on success.
     */
    public function authenticate(string $bearer): ?PortalApiToken
    {
        $scheme = self::TOKEN_SCHEME;
        if (!str_starts_with($bearer, $scheme)) {
            return null;
        }
        $rest = substr($bearer, strlen($scheme));
        $dot = strpos($rest, '.');
        if ($dot === false || $dot === 0 || $dot === strlen($rest) - 1) {
            return null;
        }
        $prefix = substr($rest, 0, $dot);
        $secret = substr($rest, $dot + 1);

        $row = $this->tokens->findByPrefix($prefix);
        if ($row === null || !$row->isUsable()) {
            return null;
        }
        if (!password_verify($secret, $row->token_hash)) {
            return null;
        }
        $this->tokens->recordUse($row->id);
        return $row;
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    /**
     * @return array<int, string>|null
     */
    private function normalizeScopes(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_array($raw)) {
            throw new InvalidArgumentException('scopes must be an array of permission strings or null');
        }
        $out = [];
        foreach ($raw as $v) {
            if (!is_string($v) || trim($v) === '') {
                throw new InvalidArgumentException('scope entries must be non-empty strings');
            }
            $out[] = trim($v);
        }
        return array_values(array_unique($out));
    }

    private function normalizeExpiresAt(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            throw new InvalidArgumentException('expires_at must be a date string or null');
        }
        try {
            $dt = new DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new InvalidArgumentException('expires_at could not be parsed as a date');
        }
        if ($dt->getTimestamp() <= time()) {
            throw new InvalidArgumentException('expires_at must be in the future');
        }
        return $dt->format('Y-m-d H:i:s');
    }

    private function randomUrlSafe(int $length): string
    {
        // 4 raw bytes -> ~6.4 base64 chars; pad upward then truncate.
        $bytes = random_bytes((int) ceil($length * 3 / 4) + 4);
        $b64 = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return substr($b64, 0, $length);
    }

    /**
     * Public-shape (no token_hash, never the plaintext).
     *
     * @return array<string, mixed>
     */
    public function serialize(PortalApiToken $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'token_prefix' => $t->token_prefix,
            'scopes' => $t->scopes,
            'last_used_at' => $t->last_used_at,
            'expires_at' => $t->expires_at,
            'revoked_at' => $t->revoked_at,
            'created_at' => $t->created_at,
            'is_usable' => $t->isUsable(),
        ];
    }
}
