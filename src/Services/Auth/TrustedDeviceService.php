<?php

namespace App\Services\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Issues + verifies "remember this device for N days" trust tokens.
 *
 * Storage model: cookie carries an opaque random token; the DB stores
 * only its sha256 hash. This means a DB compromise cannot forge cookies
 * (an attacker would need to reverse the hash) and a stolen cookie is
 * the only authoritative credential. Tokens are 32 random bytes (256
 * bits) so brute-force is infeasible.
 *
 * Lifecycle:
 *   issue()   — called after a successful 2FA challenge if the user
 *               opted into "remember this device". Returns the raw
 *               token to be set in a cookie.
 *   verify()  — called by the auth middleware on subsequent logins.
 *               Returns the trust row if valid, null if not. Side
 *               effect: stamps last_used_at.
 *   revoke()  — user-initiated "log out this device" from settings.
 *   revokeAll — emergency flush, e.g., on password change or after a
 *               security event.
 */
class TrustedDeviceService
{
    private const TOKEN_BYTES = 32;
    private const DEFAULT_TTL_DAYS = 30;

    public function __construct(
        private TrustedDeviceRepository $repo,
        private AccessGate $gate,
        private ?DateTimeImmutable $now = null
    ) {
    }

    private function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable();
    }

    /**
     * Issue a new trust token. Returns ['token' => raw, 'device' => TrustedDevice]
     * — the raw token MUST be sent to the cookie immediately and never
     * persisted server-side again.
     *
     * @return array{token: string, device: TrustedDevice}
     */
    public function issue(User $user, ?string $label = null, ?string $userAgent = null, ?string $ip = null, int $ttlDays = self::DEFAULT_TTL_DAYS): array
    {
        if ($ttlDays < 1) {
            throw new InvalidArgumentException('ttlDays must be positive.');
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hash = hash('sha256', $token);
        $expires = $this->now()->modify('+' . $ttlDays . ' days')->format('Y-m-d H:i:s');

        $device = $this->repo->create([
            'user_id' => $user->id,
            'token_hash' => $hash,
            'label' => $label,
            'user_agent' => $userAgent,
            'ip_address' => $ip,
            'expires_at' => $expires,
        ]);

        return ['token' => $token, 'device' => $device];
    }

    /**
     * Verify a raw token presented by a client. Returns the matching
     * trust row when valid (active, not revoked, not expired, owned by
     * the given user). Returns null otherwise. Stamps last_used_at as a
     * side effect on success.
     */
    public function verify(User $user, string $rawToken): ?TrustedDevice
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        $device = $this->repo->findByHash($hash);
        if ($device === null) {
            return null;
        }
        if ($device->user_id !== $user->id) {
            return null;
        }
        if ($device->revoked_at !== null) {
            return null;
        }
        if ($device->expires_at !== '' && $device->expires_at < $this->now()->format('Y-m-d H:i:s')) {
            return null;
        }

        if ($device->id !== null) {
            $this->repo->touch($device->id, $this->now()->format('Y-m-d H:i:s'));
        }
        return $device;
    }

    /**
     * @return array<int, TrustedDevice>
     */
    public function listForUser(User $actor, int $userId): array
    {
        if ($actor->id !== $userId) {
            $this->gate->assert($actor, 'users.update');
        }
        return $this->repo->listForUser($userId);
    }

    public function revoke(User $actor, int $deviceId): bool
    {
        $device = $this->repo->find($deviceId);
        if ($device === null) {
            return false;
        }
        if ($device->user_id !== $actor->id) {
            $this->gate->assert($actor, 'users.update');
        }
        return $this->repo->revoke($deviceId);
    }

    public function revokeAllForUser(User $actor, int $userId): int
    {
        if ($actor->id !== $userId) {
            $this->gate->assert($actor, 'users.update');
        }
        return $this->repo->revokeAllForUser($userId);
    }
}
