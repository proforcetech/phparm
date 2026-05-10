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
     * Verify a TOTP code for this user and record a step-up stamp bound to
     * the supplied session fingerprint. The fingerprint is normally the value
     * the auth middleware stamps onto the request as `auth_session_id` —
     * binding step-up to the session that performed it (AUD-069) means a
     * stolen token from a *different* session can't piggyback on the real
     * user's freshness.
     *
     * Throws InvalidArgumentException if the user has no TOTP secret enrolled
     * (caller should surface a setup-required message rather than retrying).
     * Returns true on success, false on bad code.
     *
     * Passing null for $sessionFingerprint is allowed for the few internal
     * callers (e.g., tests, admin tools) that don't operate inside a request
     * context. Such verifications still satisfy isFresh(null) lookups but not
     * isFresh('jwt:...') — i.e., they're not usable from a real client.
     */
    public function verify(
        User $user,
        string $code,
        ?string $ip,
        ?string $userAgent,
        ?string $sessionFingerprint = null,
    ): bool {
        if (empty($user->two_factor_secret)) {
            throw new InvalidArgumentException('TOTP is not enrolled for this account.');
        }

        $counter = $this->totp->matchCounter($user->two_factor_secret, $code);
        if ($counter === null) {
            return false;
        }

        // Reject replay of an already-consumed TOTP slot (AUD-068). The
        // (user_id, totp_counter) UNIQUE index added in migration 183 makes
        // this a single-statement check at the DB layer.
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO auth_step_up_verifications
                (user_id, verified_at, ip_address, user_agent, session_fingerprint, totp_counter)
             VALUES (:uid, NOW(), :ip, :ua, :fp, :counter)'
        );
        try {
            $stmt->execute([
                'uid' => $user->id,
                'ip' => $ip !== null ? substr($ip, 0, 64) : null,
                'ua' => $userAgent !== null ? substr($userAgent, 0, 255) : null,
                'fp' => $sessionFingerprint !== null ? substr($sessionFingerprint, 0, 64) : null,
                'counter' => $counter,
            ]);
        } catch (\PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    private function isUniqueViolation(\PDOException $e): bool
    {
        // 23000 = SQL integrity constraint violation (covers MySQL 1062 and
        // SQLite UNIQUE constraint failures).
        return $e->getCode() === '23000';
    }

    /**
     * Returns true if the user has a step-up verification newer than the
     * freshness window AND bound to the supplied session fingerprint. Passing
     * a null fingerprint deliberately matches only legacy/backfill rows
     * (created before AUD-069) so this overload should only be used by
     * one-off scripts, never from a request handler.
     */
    public function isFresh(int $userId, ?string $sessionFingerprint = null): bool
    {
        $row = $this->latestVerification($userId, $sessionFingerprint);
        if ($row === null) {
            return false;
        }

        $verifiedAt = strtotime((string) $row['verified_at']);
        if ($verifiedAt === false) {
            return false;
        }

        return (time() - $verifiedAt) < self::FRESHNESS_SECONDS;
    }

    /**
     * Throws StepUpRequiredException if the user lacks a fresh step-up bound
     * to the active session. Use at the top of route handlers that gate
     * sensitive surfaces — pass `$request->getAttribute('auth_session_id')`
     * so the gate is per-session, not per-user.
     */
    public function assertFresh(int $userId, ?string $sessionFingerprint = null): void
    {
        if (!$this->isFresh($userId, $sessionFingerprint)) {
            throw new StepUpRequiredException('Step-up verification required.');
        }
    }

    /**
     * Seconds remaining on the user's current step-up for the given session
     * fingerprint, or 0 if not fresh. Useful for the frontend to show a
     * countdown so users aren't surprised by a step-up prompt mid-task.
     */
    public function remainingSeconds(int $userId, ?string $sessionFingerprint = null): int
    {
        $row = $this->latestVerification($userId, $sessionFingerprint);
        if ($row === null) {
            return 0;
        }

        $verifiedAt = strtotime((string) $row['verified_at']);
        if ($verifiedAt === false) {
            return 0;
        }

        $remaining = self::FRESHNESS_SECONDS - (time() - $verifiedAt);
        return max(0, $remaining);
    }

    /**
     * Single source of truth for "latest step-up row matching this
     * (user, fingerprint)". The fingerprint match is exact — null only
     * matches null rows, non-null only matches its own value — which is
     * how AUD-069 binds freshness to the session that earned it.
     *
     * @return array{verified_at: string}|null
     */
    private function latestVerification(int $userId, ?string $sessionFingerprint): ?array
    {
        if ($sessionFingerprint === null) {
            $stmt = $this->connection->pdo()->prepare(
                'SELECT verified_at FROM auth_step_up_verifications
                 WHERE user_id = :uid AND session_fingerprint IS NULL
                 ORDER BY verified_at DESC
                 LIMIT 1'
            );
            $stmt->execute(['uid' => $userId]);
        } else {
            $stmt = $this->connection->pdo()->prepare(
                'SELECT verified_at FROM auth_step_up_verifications
                 WHERE user_id = :uid AND session_fingerprint = :fp
                 ORDER BY verified_at DESC
                 LIMIT 1'
            );
            $stmt->execute([
                'uid' => $userId,
                'fp' => substr($sessionFingerprint, 0, 64),
            ]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
