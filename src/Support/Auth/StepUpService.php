<?php

namespace App\Support\Auth;

use App\Database\Connection;
use App\Models\User;
use InvalidArgumentException;

/**
 * Records and checks short-lived TOTP step-up verifications. Step-up is the
 * "prove you still hold the TOTP device" gate used before sensitive actions
 * (credentials vault, ticket category delete, etc.) — it's stronger than a
 * permission check because session theft can grant permission, but only the
 * physical TOTP device can produce a fresh code.
 *
 * Freshness window is intentionally short ({@see self::FRESHNESS_SECONDS});
 * widening it weakens the gate. If a workflow needs longer, prompt again
 * rather than relaxing this constant.
 */
class StepUpService
{
    /**
     * How long a step-up verification stays valid for, in seconds.
     * Decision locked at 5 minutes — see project memory.
     */
    public const FRESHNESS_SECONDS = 300;

    public function __construct(
        private readonly Connection $connection,
        private readonly TotpService $totp,
    ) {
    }

    /**
     * Verify a TOTP code for this user and record a step-up stamp on success.
     * Throws InvalidArgumentException if the user has no TOTP secret enrolled
     * (caller should surface a setup-required message rather than retrying).
     * Returns true on success, false on bad code.
     */
    public function verify(User $user, string $code, ?string $ip, ?string $userAgent): bool
    {
        if (empty($user->two_factor_secret)) {
            throw new InvalidArgumentException('TOTP is not enrolled for this account.');
        }

        if (!$this->totp->verifyCode($user->two_factor_secret, $code)) {
            return false;
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO auth_step_up_verifications (user_id, verified_at, ip_address, user_agent)
             VALUES (:uid, NOW(), :ip, :ua)'
        );
        $stmt->execute([
            'uid' => $user->id,
            'ip' => $ip !== null ? substr($ip, 0, 64) : null,
            'ua' => $userAgent !== null ? substr($userAgent, 0, 255) : null,
        ]);

        return true;
    }

    /**
     * Returns true if the user has a step-up verification newer than the
     * freshness window. Pure read — does not mutate state.
     */
    public function isFresh(int $userId): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT verified_at FROM auth_step_up_verifications
             WHERE user_id = :uid
             ORDER BY verified_at DESC
             LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $verifiedAt = strtotime((string) $row['verified_at']);
        if ($verifiedAt === false) {
            return false;
        }

        return (time() - $verifiedAt) < self::FRESHNESS_SECONDS;
    }

    /**
     * Throws StepUpRequiredException if the user lacks a fresh step-up.
     * Use at the top of route handlers that gate sensitive surfaces.
     */
    public function assertFresh(int $userId): void
    {
        if (!$this->isFresh($userId)) {
            throw new StepUpRequiredException('Step-up verification required.');
        }
    }

    /**
     * Seconds remaining on the user's current step-up, or 0 if not fresh.
     * Useful for the frontend to show a countdown so users aren't surprised
     * by a step-up prompt mid-task.
     */
    public function remainingSeconds(int $userId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT verified_at FROM auth_step_up_verifications
             WHERE user_id = :uid
             ORDER BY verified_at DESC
             LIMIT 1'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return 0;
        }

        $verifiedAt = strtotime((string) $row['verified_at']);
        if ($verifiedAt === false) {
            return 0;
        }

        $remaining = self::FRESHNESS_SECONDS - (time() - $verifiedAt);
        return max(0, $remaining);
    }
}
