<?php

namespace App\Support\Auth;

/**
 * CSRF Token Service for generating and validating anti-CSRF tokens.
 *
 * Implements double-submit cookie pattern combined with session storage
 * for defense-in-depth against CSRF attacks.
 */
class CsrfTokenService
{
    private const TOKEN_LENGTH = 32;
    private const SESSION_KEY = 'csrf_token';
    private const COOKIE_NAME = 'XSRF-TOKEN';

    /**
     * Generate a new CSRF token and store it in the session.
     *
     * @return string The generated token
     */
    public function generateToken(): string
    {
        $this->ensureSessionStarted();

        // Generate a cryptographically secure random token
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::SESSION_KEY] = $token;

        return $token;
    }

    /**
     * Get the current CSRF token, generating one if it doesn't exist.
     *
     * @return string The current token
     */
    public function getToken(): string
    {
        $this->ensureSessionStarted();

        if (!isset($_SESSION[self::SESSION_KEY]) || empty($_SESSION[self::SESSION_KEY])) {
            return $this->generateToken();
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Regenerate the CSRF token (call after sensitive actions).
     *
     * @return string The new token
     */
    public function regenerateToken(): string
    {
        return $this->generateToken();
    }

    /**
     * Validate a token against the stored session token.
     *
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param string $token The token to validate
     * @return bool True if valid, false otherwise
     */
    public function validateToken(string $token): bool
    {
        $this->ensureSessionStarted();

        if (!isset($_SESSION[self::SESSION_KEY]) || empty($token)) {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $token);
    }

    /**
     * Set the CSRF token as a cookie for JavaScript access.
     *
     * The cookie is NOT httpOnly so JavaScript can read it.
     * The actual validation happens against the session-stored token.
     *
     * @param string $token The token to set
     * @param bool $secure Whether to set Secure flag (should be true in production)
     * @return void
     */
    public function setCookie(string $token, bool $secure = true): void
    {
        // Note: domain key is intentionally omitted to restrict cookie to exact current domain
        $options = [
            'expires' => 0, // Session cookie
            'path' => '/',
            'secure' => $secure,
            'httponly' => false, // JavaScript needs to read this
            'samesite' => 'Strict',
        ];

        setcookie(self::COOKIE_NAME, $token, $options);
    }

    /**
     * Clear the CSRF token from session and cookie.
     *
     * @return void
     */
    public function clearToken(): void
    {
        $this->ensureSessionStarted();
        unset($_SESSION[self::SESSION_KEY]);

        // Clear the cookie by setting it to empty with past expiration
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => false,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Get the cookie name for external reference.
     *
     * @return string
     */
    public static function getCookieName(): string
    {
        return self::COOKIE_NAME;
    }

    /**
     * Ensure PHP session is started.
     *
     * @return void
     */
    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
