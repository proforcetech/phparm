<?php

namespace App\Support\Auth;

/**
 * Secure Session Service for managing PHP sessions with enhanced security.
 *
 * Provides session fixation protection, 2FA challenge cleanup, and
 * proper session expiration handling with race condition prevention.
 */
class SecureSessionService
{
    private const LAST_ACTIVITY_KEY = 'last_activity';
    private const SESSION_CREATED_KEY = 'session_created';
    private const REGENERATE_INTERVAL = 1800; // 30 minutes
    private const TWO_FACTOR_CHALLENGES_KEY = '2fa_challenges';
    private const TWO_FACTOR_CHALLENGE_TTL = 300; // 5 minutes
    private const SESSION_LOCK_KEY = 'session_lock';

    private int $sessionTimeout;
    private int $absoluteTimeout;

    /**
     * @param int $sessionTimeout Idle timeout in seconds (default: 30 minutes)
     * @param int $absoluteTimeout Maximum session lifetime in seconds (default: 24 hours)
     */
    public function __construct(int $sessionTimeout = 1800, int $absoluteTimeout = 86400)
    {
        $this->sessionTimeout = $sessionTimeout;
        $this->absoluteTimeout = $absoluteTimeout;
    }

    /**
     * Initialize a secure session with proper configuration.
     *
     * @return bool True if session started successfully
     */
    public function start(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        // Configure secure session settings before starting
        $this->configureSecureSettings();

        $result = session_start();

        if ($result) {
            $this->initializeSessionMetadata();
            $this->cleanExpiredTwoFactorChallenges();
        }

        return $result;
    }

    /**
     * Configure PHP session with secure settings.
     *
     * @return void
     */
    private function configureSecureSettings(): void
    {
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', 'localhost:8000', 'localhost:3000'], true)
            || str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'localhost:');

        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $isSecure ? '1' : '0');
        // Use Lax on localhost for dev proxy compatibility, Strict in production
        ini_set('session.cookie_samesite', $isLocalhost ? 'Lax' : 'Strict');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->absoluteTimeout);

        // Set session cookie parameters
        session_set_cookie_params([
            'lifetime' => 0, // Session cookie
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => $isLocalhost ? 'Lax' : 'Strict',
        ]);
    }

    /**
     * Initialize session metadata for tracking.
     *
     * @return void
     */
    private function initializeSessionMetadata(): void
    {
        $now = time();

        if (!isset($_SESSION[self::SESSION_CREATED_KEY])) {
            $_SESSION[self::SESSION_CREATED_KEY] = $now;
        }

        $_SESSION[self::LAST_ACTIVITY_KEY] = $now;
    }

    /**
     * Validate session is still active and not expired.
     *
     * @return bool True if session is valid
     */
    public function validate(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $now = time();

        // Check idle timeout
        if (isset($_SESSION[self::LAST_ACTIVITY_KEY])) {
            if ($now - $_SESSION[self::LAST_ACTIVITY_KEY] > $this->sessionTimeout) {
                $this->destroy();
                return false;
            }
        }

        // Check absolute timeout
        if (isset($_SESSION[self::SESSION_CREATED_KEY])) {
            if ($now - $_SESSION[self::SESSION_CREATED_KEY] > $this->absoluteTimeout) {
                $this->destroy();
                return false;
            }
        }

        // Update last activity
        $_SESSION[self::LAST_ACTIVITY_KEY] = $now;

        // Periodically regenerate session ID
        $this->maybeRegenerateId();

        return true;
    }

    /**
     * Regenerate session ID after login to prevent session fixation.
     *
     * @param bool $deleteOldSession Whether to delete the old session data
     * @return bool True if regeneration was successful
     */
    public function regenerateAfterLogin(bool $deleteOldSession = true): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        // Acquire a simple lock to prevent race conditions
        $lockKey = self::SESSION_LOCK_KEY . '_' . session_id();
        $lockAcquired = $this->acquireSessionLock($lockKey);

        // If we can't acquire lock, another request may be regenerating.
        // We still proceed with regeneration to prevent session fixation attacks -
        // the race condition risk is less severe than leaving the session unchanged.
        // In the worst case, one request gets a new session while another continues
        // with the old one briefly, which is acceptable for security.

        try {
            // Regenerate with old session destruction
            $result = session_regenerate_id($deleteOldSession);

            if ($result) {
                // Reset session creation time for the new session
                $_SESSION[self::SESSION_CREATED_KEY] = time();
                $_SESSION[self::LAST_ACTIVITY_KEY] = time();

                // Clear any pending 2FA challenges from the old session context
                unset($_SESSION[self::TWO_FACTOR_CHALLENGES_KEY]);
            }

            return $result;
        } finally {
            if ($lockAcquired) {
                $this->releaseSessionLock($lockKey);
            }
        }
    }

    /**
     * Regenerate session ID periodically for security.
     *
     * @return void
     */
    private function maybeRegenerateId(): void
    {
        if (!isset($_SESSION[self::SESSION_CREATED_KEY])) {
            return;
        }

        $sessionAge = time() - $_SESSION[self::SESSION_CREATED_KEY];

        if ($sessionAge > self::REGENERATE_INTERVAL) {
            // Don't delete old session on periodic regeneration to prevent
            // issues with concurrent requests
            session_regenerate_id(false);
            $_SESSION[self::SESSION_CREATED_KEY] = time();
        }
    }

    /**
     * Create a 2FA challenge token.
     *
     * @param int $userId The user ID for the challenge
     * @param string $type The challenge type (e.g., 'totp')
     * @return string The challenge token
     */
    public function createTwoFactorChallenge(int $userId, string $type = 'totp'): string
    {
        $this->ensureSessionStarted();
        $this->cleanExpiredTwoFactorChallenges();

        $token = bin2hex(random_bytes(32));

        if (!isset($_SESSION[self::TWO_FACTOR_CHALLENGES_KEY])) {
            $_SESSION[self::TWO_FACTOR_CHALLENGES_KEY] = [];
        }

        $_SESSION[self::TWO_FACTOR_CHALLENGES_KEY][$token] = [
            'user_id' => $userId,
            'type' => $type,
            'expires_at' => time() + self::TWO_FACTOR_CHALLENGE_TTL,
            'created_at' => time(),
        ];

        return $token;
    }

    /**
     * Validate and consume a 2FA challenge token.
     *
     * @param string $token The challenge token
     * @return array|null The challenge data if valid, null otherwise
     */
    public function validateTwoFactorChallenge(string $token): ?array
    {
        $this->ensureSessionStarted();

        if (!isset($_SESSION[self::TWO_FACTOR_CHALLENGES_KEY][$token])) {
            return null;
        }

        $challenge = $_SESSION[self::TWO_FACTOR_CHALLENGES_KEY][$token];

        // Check expiration
        if (($challenge['expires_at'] ?? 0) < time()) {
            unset($_SESSION[self::TWO_FACTOR_CHALLENGES_KEY][$token]);
            return null;
        }

        return $challenge;
    }

    /**
     * Consume (use up) a 2FA challenge token.
     *
     * @param string $token The challenge token to consume
     * @return void
     */
    public function consumeTwoFactorChallenge(string $token): void
    {
        $this->ensureSessionStarted();
        unset($_SESSION[self::TWO_FACTOR_CHALLENGES_KEY][$token]);
    }

    /**
     * Clean up expired 2FA challenges from the session.
     *
     * @return int Number of challenges cleaned
     */
    public function cleanExpiredTwoFactorChallenges(): int
    {
        if (!isset($_SESSION[self::TWO_FACTOR_CHALLENGES_KEY])) {
            return 0;
        }

        $now = time();
        $cleaned = 0;

        foreach ($_SESSION[self::TWO_FACTOR_CHALLENGES_KEY] as $token => $challenge) {
            if (($challenge['expires_at'] ?? 0) < $now) {
                unset($_SESSION[self::TWO_FACTOR_CHALLENGES_KEY][$token]);
                $cleaned++;
            }
        }

        // Clean up empty array
        if (empty($_SESSION[self::TWO_FACTOR_CHALLENGES_KEY])) {
            unset($_SESSION[self::TWO_FACTOR_CHALLENGES_KEY]);
        }

        return $cleaned;
    }

    /**
     * Destroy the current session completely.
     *
     * @return bool True if session was destroyed
     */
    public function destroy(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        // Clear all session data
        $_SESSION = [];

        // Delete the session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Strict',
                ]
            );
        }

        // Destroy the session
        return session_destroy();
    }

    /**
     * Ensure PHP session is started.
     *
     * @return void
     */
    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $this->start();
        }
    }

    /**
     * Acquire a simple lock to prevent race conditions during session regeneration.
     *
     * @param string $lockKey The lock key
     * @return bool True if lock acquired
     */
    private function acquireSessionLock(string $lockKey): bool
    {
        $lockFile = sys_get_temp_dir() . '/' . md5($lockKey) . '.lock';
        $fp = @fopen($lockFile, 'c');

        if (!$fp) {
            return true; // If we can't create lock file, proceed anyway
        }

        $locked = flock($fp, LOCK_EX | LOCK_NB);

        if ($locked) {
            // Store file handle for later release
            $GLOBALS['_session_lock_fp'] = $fp;
            return true;
        }

        fclose($fp);
        return false;
    }

    /**
     * Release the session lock.
     *
     * @param string $lockKey The lock key
     * @return void
     */
    private function releaseSessionLock(string $lockKey): void
    {
        if (isset($GLOBALS['_session_lock_fp'])) {
            flock($GLOBALS['_session_lock_fp'], LOCK_UN);
            fclose($GLOBALS['_session_lock_fp']);
            unset($GLOBALS['_session_lock_fp']);
        }

        $lockFile = sys_get_temp_dir() . '/' . md5($lockKey) . '.lock';
        @unlink($lockFile);
    }

    /**
     * Get session timeout value.
     *
     * @return int Session timeout in seconds
     */
    public function getSessionTimeout(): int
    {
        return $this->sessionTimeout;
    }

    /**
     * Get absolute timeout value.
     *
     * @return int Absolute timeout in seconds
     */
    public function getAbsoluteTimeout(): int
    {
        return $this->absoluteTimeout;
    }
}
