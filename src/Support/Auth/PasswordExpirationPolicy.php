<?php

namespace App\Support\Auth;

use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Decides whether a user's password is expired or about to expire based on
 * `password_changed_at`, the admin-flipped `must_change_password` bit, and
 * the configured max age + warn window.
 *
 * Centralised so /auth/me, the login flow, and the force-rotation middleware
 * all agree on the same set of derived booleans.
 */
class PasswordExpirationPolicy
{
    private int $maxAgeDays;
    private int $warnBeforeDays;

    public function __construct(int $maxAgeDays, int $warnBeforeDays)
    {
        $this->maxAgeDays = max(0, $maxAgeDays);
        $this->warnBeforeDays = max(0, $warnBeforeDays);
    }

    /**
     * @param array<string, mixed> $config Full auth config (reads passwords.max_age_days etc).
     */
    public static function fromConfig(array $config): self
    {
        $passwords = $config['passwords'] ?? [];
        return new self(
            (int) ($passwords['max_age_days'] ?? 0),
            (int) ($passwords['warn_before_days'] ?? 0)
        );
    }

    /**
     * Returns the password-expiration view for a user. Always returns the same
     * keys so callers can blindly merge it into payloads.
     *
     * @return array{
     *     password_changed_at: ?string,
     *     password_expires_at: ?string,
     *     password_expires_in_days: ?int,
     *     password_must_change: bool,
     *     password_warn_active: bool,
     *     max_age_days: int
     * }
     */
    public function describe(User $user): array
    {
        $payload = [
            'password_changed_at' => $user->password_changed_at,
            'password_expires_at' => null,
            'password_expires_in_days' => null,
            'password_must_change' => $user->must_change_password,
            'password_warn_active' => false,
            'max_age_days' => $this->maxAgeDays,
        ];

        // Aging disabled → only the admin-flipped flag can force a change.
        if ($this->maxAgeDays === 0) {
            return $payload;
        }

        $changedAt = $this->parseTimestamp($user->password_changed_at);
        if ($changedAt === null) {
            // Missing timestamp on a user when aging is enabled is treated as
            // immediately expired — the safe default. Backfill should have
            // populated this for every existing user.
            $payload['password_must_change'] = true;
            $payload['password_expires_in_days'] = 0;
            return $payload;
        }

        $expiresAt = $changedAt->modify('+' . $this->maxAgeDays . ' days');
        $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get() ?: 'UTC'));
        $payload['password_expires_at'] = $expiresAt->format('Y-m-d H:i:s');

        $diffSeconds = $expiresAt->getTimestamp() - $now->getTimestamp();
        $payload['password_expires_in_days'] = (int) floor($diffSeconds / 86400);

        if ($expiresAt <= $now) {
            $payload['password_must_change'] = true;
        } elseif ($this->warnBeforeDays > 0 && $payload['password_expires_in_days'] <= $this->warnBeforeDays) {
            $payload['password_warn_active'] = true;
        }

        return $payload;
    }

    /**
     * Convenience: just the boolean every middleware needs.
     */
    public function isRotationRequired(User $user): bool
    {
        return $this->describe($user)['password_must_change'];
    }

    private function parseTimestamp(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return null;
        }
    }
}
