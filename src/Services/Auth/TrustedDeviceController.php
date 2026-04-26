<?php

namespace App\Services\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP edge for the trusted-device surface.
 *
 * All endpoints assume Middleware::auth() has resolved $actor; the
 * service layer enforces ownership/admin semantics. Self-service is the
 * common path — admins fall back to trusted_devices.manage to act on
 * behalf of a user (e.g., force-revoke after a phishing report).
 *
 * Note: token issuance is NOT in the controller. Tokens are minted only
 * during the post-2FA login flow (see routes/api.php /api/auth/2fa/verify
 * — which calls TrustedDeviceService::issue() and sets the cookie). This
 * controller is for inspection + revocation only.
 */
class TrustedDeviceController
{
    public function __construct(
        private TrustedDeviceService $service,
        private TrustedDeviceRepository $repo,
        private AccessGate $gate
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listMine(User $actor): array
    {
        $items = array_map(
            static fn (TrustedDevice $d): array => self::serialize($d),
            $this->service->listForUser($actor, $actor->id)
        );
        return ['data' => $items];
    }

    /**
     * Admin: list trust rows for any user. Gated by trusted_devices.manage.
     *
     * @return array<string, mixed>
     */
    public function listForUser(User $actor, int $userId): array
    {
        if ($actor->id !== $userId) {
            $this->gate->assert($actor, 'trusted_devices.manage');
        }
        $items = array_map(
            static fn (TrustedDevice $d): array => self::serialize($d),
            $this->repo->listForUser($userId)
        );
        return ['data' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function revoke(User $actor, int $deviceId): array
    {
        $revoked = $this->service->revoke($actor, $deviceId);
        if (!$revoked) {
            throw new InvalidArgumentException('Trusted device not found or already revoked.');
        }
        return ['data' => ['revoked' => true, 'id' => $deviceId]];
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeAllMine(User $actor): array
    {
        $count = $this->service->revokeAllForUser($actor, $actor->id);
        return ['data' => ['revoked_count' => $count]];
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeAllForUser(User $actor, int $userId): array
    {
        $count = $this->service->revokeAllForUser($actor, $userId);
        return ['data' => ['revoked_count' => $count]];
    }

    /**
     * Project a TrustedDevice for the wire — drop the token_hash so we
     * never accidentally leak it to clients (it's the credential, hashed
     * but still sensitive — exposing it would let an attacker correlate
     * cookie theft against a DB dump).
     *
     * @return array<string, mixed>
     */
    private static function serialize(TrustedDevice $d): array
    {
        $arr = $d->toArray();
        unset($arr['token_hash']);
        return $arr;
    }
}
