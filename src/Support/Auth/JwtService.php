<?php

namespace App\Support\Auth;

use App\Database\Connection;
use App\Models\User;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * JWT Token Service for API authentication.
 *
 * Implements JWT (JSON Web Token) generation and validation using HMAC-SHA256.
 * Tokens are stateless and contain user claims for authentication.
 *
 * ## Security Model
 *
 * This service supports two token delivery mechanisms with different security profiles:
 *
 * ### 1. httpOnly Cookies (Preferred)
 * Tokens are stored in httpOnly, secure, SameSite=Strict cookies.
 * - **XSS Protection**: JavaScript cannot access httpOnly cookies
 * - **CSRF Protection**: SameSite=Strict prevents cross-origin requests from including cookies
 * - **Automatic**: Browser handles token transmission automatically
 * - **Use Case**: Web application frontend (React, browser-based)
 *
 * ### 2. Bearer Token (API Clients)
 * Tokens sent via Authorization header: `Authorization: Bearer <token>`
 * - **XSS Vulnerable**: Tokens stored in localStorage/memory can be stolen via XSS
 * - **CSRF Immune**: Tokens must be explicitly added to requests, preventing CSRF
 * - **Explicit**: Client must manage token storage and transmission
 * - **Use Case**: Mobile apps, third-party API integrations, server-to-server calls
 *
 * ## Security Recommendations
 *
 * 1. **Web Apps**: Always use httpOnly cookie storage. The middleware prioritizes
 *    cookie-based auth over bearer tokens for this reason.
 *
 * 2. **Mobile Apps**: Use bearer tokens, but implement certificate pinning and
 *    secure storage (iOS Keychain, Android Keystore).
 *
 * 3. **API Clients**: Restrict token scope if possible. Use short-lived access
 *    tokens with refresh token rotation.
 *
 * ## Refresh Token Rotation
 *
 * This service implements refresh token rotation with reuse detection:
 * - Each refresh token can only be used once
 * - Tokens are grouped into "families" tracking their lineage
 * - If a token is reused (indicating potential theft), the entire family is revoked
 * - This limits the damage window if a refresh token is compromised
 *
 * @see Middleware::auth() For authentication flow and priority
 */
class JwtService
{
    private const ACCESS_TOKEN_COOKIE = 'jwt_access_token';
    private const REFRESH_TOKEN_COOKIE = 'jwt_refresh_token';

    private Connection $connection;
    private string $secretKey;
    private int $tokenTtlSeconds;
    private int $refreshTtlSeconds;

    /**
     * @param Connection $connection Database connection for user lookup
     * @param string $secretKey Secret key for signing tokens (min 32 chars)
     * @param int $tokenTtlSeconds Access token lifetime (default: 1 hour)
     * @param int $refreshTtlSeconds Refresh token lifetime (default: 7 days)
     */
    public function __construct(
        Connection $connection,
        string $secretKey,
        int $tokenTtlSeconds = 3600,
        int $refreshTtlSeconds = 604800
    ) {
        $this->validateSecretKey($secretKey);

        $this->connection = $connection;
        $this->secretKey = $secretKey;
        $this->tokenTtlSeconds = $tokenTtlSeconds;
        $this->refreshTtlSeconds = $refreshTtlSeconds;
    }

    /**
     * Validate the JWT secret key for security requirements.
     *
     * @param string $secretKey The secret key to validate
     * @throws InvalidArgumentException If the key doesn't meet security requirements
     */
    private function validateSecretKey(string $secretKey): void
    {
        // Minimum length requirement
        if (strlen($secretKey) < 32) {
            throw new InvalidArgumentException('JWT secret key must be at least 32 characters');
        }

        // Entropy check - ensure the key has sufficient randomness
        // A good secret should have at least 128 bits of entropy (for HS256)
        // We estimate entropy by checking character diversity
        $entropy = $this->estimateEntropy($secretKey);
        if ($entropy < 100) {
            throw new InvalidArgumentException(
                'JWT secret key has insufficient entropy. Use a cryptographically random string. ' .
                'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        // Check for obvious weak patterns
        if ($this->hasWeakPattern($secretKey)) {
            throw new InvalidArgumentException(
                'JWT secret key appears to use a weak pattern (repeated characters, sequences, or common words). ' .
                'Use a cryptographically random string.'
            );
        }
    }

    /**
     * Estimate the entropy of a string in bits.
     *
     * @param string $str The string to analyze
     * @return float Estimated entropy in bits
     */
    private function estimateEntropy(string $str): float
    {
        $length = strlen($str);
        if ($length === 0) {
            return 0;
        }

        // Calculate character pool size based on character classes present
        $poolSize = 0;
        if (preg_match('/[a-z]/', $str)) {
            $poolSize += 26;
        }
        if (preg_match('/[A-Z]/', $str)) {
            $poolSize += 26;
        }
        if (preg_match('/[0-9]/', $str)) {
            $poolSize += 10;
        }
        if (preg_match('/[^a-zA-Z0-9]/', $str)) {
            $poolSize += 32; // Conservative estimate for special chars
        }

        if ($poolSize === 0) {
            return 0;
        }

        // Entropy = length * log2(poolSize)
        // Apply a penalty for repeated characters
        $uniqueChars = count(array_unique(str_split($str)));
        $uniquenessRatio = $uniqueChars / $length;

        return $length * log($poolSize, 2) * $uniquenessRatio;
    }

    /**
     * Check if the string has weak patterns.
     *
     * @param string $str The string to check
     * @return bool True if weak patterns detected
     */
    private function hasWeakPattern(string $str): bool
    {
        $lower = strtolower($str);

        // Check for repeated characters (e.g., "aaaaa...")
        if (preg_match('/(.)\1{7,}/', $str)) {
            return true;
        }

        // Check for sequential patterns
        $sequences = ['0123456789', 'abcdefghijklmnopqrstuvwxyz', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm'];
        foreach ($sequences as $seq) {
            if (strpos($lower, $seq) !== false || strpos($lower, strrev($seq)) !== false) {
                return true;
            }
        }

        // Check for common weak passwords/secrets
        $weakPatterns = [
            'password', 'secret', 'changeme', 'default', 'admin', 'test',
            'your-256-bit-secret', 'change-in-production', 'example'
        ];
        foreach ($weakPatterns as $pattern) {
            if (stripos($str, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate an access token for a user.
     *
     * Phase 6.1 of docs/expansion-plan.md — $extraClaims lets the isolated
     * customer-portal flow stamp `scope='portal'` + `portal_account_id` +
     * `company_id` into the JWT so the portal middleware can reject tokens
     * that were issued for a different bounded context (and so the main
     * auth middleware can reject portal tokens). Passing [] yields the
     * original staff-only token shape for backwards-compat.
     *
     * @param array<string, mixed> $extraClaims
     */
    public function generateToken(User $user, array $extraClaims = []): string
    {
        $now = time();
        $payload = [
            'iss' => 'phparm',
            'sub' => $user->id,
            'iat' => $now,
            'exp' => $now + $this->tokenTtlSeconds,
            'type' => 'access',
            'role' => $user->role,
        ];
        foreach ($extraClaims as $key => $value) {
            if (in_array($key, ['iss', 'sub', 'iat', 'exp', 'type'], true)) {
                continue;
            }
            $payload[$key] = $value;
        }

        return $this->encode($payload);
    }

    /**
     * Generate a refresh token for a user.
     *
     * @param User $user The user to generate token for
     * @param string|null $familyId Token family ID for rotation tracking (null = new family)
     * @param string|null $ipAddress Client IP address for audit
     * @param string|null $userAgent Client user agent for audit
     * @return string The encoded JWT refresh token
     */
    public function generateRefreshToken(
        User $user,
        ?string $familyId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): string {
        $now = time();
        $jti = bin2hex(random_bytes(16)); // Unique token ID
        $family = $familyId ?? $this->generateFamilyId();

        $payload = [
            'iss' => 'phparm',
            'sub' => $user->id,
            'iat' => $now,
            'exp' => $now + $this->refreshTtlSeconds,
            'type' => 'refresh',
            'jti' => $jti,
            'fam' => $family, // Token family for rotation tracking
        ];

        $token = $this->encode($payload);

        // Store token hash in database for rotation tracking
        $this->storeRefreshToken($user->id, $token, $family, $now + $this->refreshTtlSeconds, $ipAddress, $userAgent);

        return $token;
    }

    /**
     * Generate a unique family ID for token rotation tracking.
     */
    private function generateFamilyId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Store a refresh token hash in the database.
     */
    private function storeRefreshToken(
        int $userId,
        string $token,
        string $familyId,
        int $expiresAt,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        try {
            $tokenHash = hash('sha256', $token);

            $stmt = $this->connection->pdo()->prepare(
                'INSERT INTO refresh_tokens (user_id, token_hash, family_id, expires_at, ip_address, user_agent)
                VALUES (:user_id, :token_hash, :family_id, FROM_UNIXTIME(:expires_at), :ip_address, :user_agent)'
            );

            $stmt->execute([
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'family_id' => $familyId,
                'expires_at' => $expiresAt,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
            ]);
        } catch (\PDOException $e) {
            // Log but don't fail - token is still valid even if tracking fails
            error_log('Failed to store refresh token: ' . $e->getMessage());
        }
    }

    /**
     * Validate a token and return the user if valid.
     *
     * @return User|null The authenticated user or null if invalid
     */
    public function validateToken(string $token): ?User
    {
        $payload = $this->decode($token);

        if ($payload === null) {
            return null;
        }

        // Check token type
        if (($payload['type'] ?? '') !== 'access') {
            return null;
        }

        // Check expiration
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }

        // Get user from database
        $userId = $payload['sub'] ?? null;
        if ($userId === null) {
            return null;
        }

        return $this->findUserById((int) $userId);
    }

    /**
     * Validate an access token and return BOTH the user and the decoded
     * payload. Used by Phase 6.1 portal middleware to inspect scope
     * claims (scope='portal', company_id, portal_account_id) after the
     * token has already passed signature + type + expiration checks.
     *
     * @return array{user: User, payload: array<string, mixed>}|null
     */
    public function validateTokenWithPayload(string $token): ?array
    {
        $payload = $this->decode($token);
        if ($payload === null) {
            return null;
        }
        if (($payload['type'] ?? '') !== 'access') {
            return null;
        }
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }
        $userId = $payload['sub'] ?? null;
        if ($userId === null) {
            return null;
        }
        $user = $this->findUserById((int) $userId);
        if ($user === null) {
            return null;
        }
        return ['user' => $user, 'payload' => $payload];
    }

    /**
     * Validate a refresh token and return the user if valid.
     */
    public function validateRefreshToken(string $token): ?User
    {
        $payload = $this->decode($token);

        if ($payload === null) {
            return null;
        }

        // Check token type
        if (($payload['type'] ?? '') !== 'refresh') {
            return null;
        }

        // Check expiration
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }

        // Get user from database
        $userId = $payload['sub'] ?? null;
        if ($userId === null) {
            return null;
        }

        return $this->findUserById((int) $userId);
    }

    /**
     * Refresh an access token using a refresh token.
     * Implements refresh token rotation - each token can only be used once.
     *
     * @param string $refreshToken The refresh token
     * @param string|null $ipAddress Client IP address for the new token
     * @param string|null $userAgent Client user agent for the new token
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    public function refreshTokens(
        string $refreshToken,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ?array {
        // First validate the token structure and signature
        $payload = $this->decode($refreshToken);
        if ($payload === null || ($payload['type'] ?? '') !== 'refresh') {
            return null;
        }

        // Check if token has been revoked (token reuse detection)
        $tokenHash = hash('sha256', $refreshToken);
        $familyId = $payload['fam'] ?? null;

        if ($this->isRefreshTokenRevoked($tokenHash)) {
            // Token was already used! This indicates potential token theft.
            // Revoke the entire token family as a security measure.
            if ($familyId) {
                $this->revokeTokenFamily($familyId, 'token_reuse_detected');
                error_log(sprintf(
                    'SECURITY: Refresh token reuse detected for family %s. Entire family revoked.',
                    $familyId
                ));
            }
            return null;
        }

        // Validate user exists
        $userId = $payload['sub'] ?? null;
        if ($userId === null) {
            return null;
        }

        $user = $this->findUserById((int) $userId);
        if ($user === null) {
            return null;
        }

        // Mark the current token as used (revoked)
        $this->revokeRefreshToken($tokenHash, 'rotated');

        // Generate new tokens in the same family
        return [
            'access_token' => $this->generateToken($user),
            'refresh_token' => $this->generateRefreshToken($user, $familyId, $ipAddress, $userAgent),
            'expires_in' => $this->tokenTtlSeconds,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Check if a refresh token has been revoked.
     */
    private function isRefreshTokenRevoked(string $tokenHash): bool
    {
        try {
            $stmt = $this->connection->pdo()->prepare(
                'SELECT is_revoked FROM refresh_tokens WHERE token_hash = :token_hash LIMIT 1'
            );
            $stmt->execute(['token_hash' => $tokenHash]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            // If token not found in DB, it's from before rotation was implemented - allow it
            if (!$row) {
                return false;
            }

            return (bool) $row['is_revoked'];
        } catch (\PDOException $e) {
            error_log('Failed to check refresh token: ' . $e->getMessage());
            return false; // Fail open to not break auth during DB issues
        }
    }

    /**
     * Revoke a specific refresh token.
     */
    private function revokeRefreshToken(string $tokenHash, string $reason): void
    {
        try {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE refresh_tokens
                SET is_revoked = TRUE, revoked_at = NOW(), revoked_reason = :reason
                WHERE token_hash = :token_hash AND is_revoked = FALSE'
            );
            $stmt->execute(['token_hash' => $tokenHash, 'reason' => $reason]);
        } catch (\PDOException $e) {
            error_log('Failed to revoke refresh token: ' . $e->getMessage());
        }
    }

    /**
     * Revoke all tokens in a family (used when token reuse is detected).
     */
    private function revokeTokenFamily(string $familyId, string $reason): void
    {
        try {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE refresh_tokens
                SET is_revoked = TRUE, revoked_at = NOW(), revoked_reason = :reason
                WHERE family_id = :family_id AND is_revoked = FALSE'
            );
            $stmt->execute(['family_id' => $familyId, 'reason' => $reason]);
        } catch (\PDOException $e) {
            error_log('Failed to revoke token family: ' . $e->getMessage());
        }
    }

    /**
     * Revoke all refresh tokens for a user (used on logout or password change).
     */
    public function revokeAllUserTokens(int $userId, string $reason = 'logout'): void
    {
        try {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE refresh_tokens
                SET is_revoked = TRUE, revoked_at = NOW(), revoked_reason = :reason
                WHERE user_id = :user_id AND is_revoked = FALSE'
            );
            $stmt->execute(['user_id' => $userId, 'reason' => $reason]);
        } catch (\PDOException $e) {
            error_log('Failed to revoke user tokens: ' . $e->getMessage());
        }
    }

    /**
     * Decode a token without validation (for debugging).
     *
     * @return array<string, mixed>|null
     */
    public function decodeWithoutValidation(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = $this->base64UrlDecode($parts[1]);
        if ($payload === false) {
            return null;
        }

        $data = json_decode($payload, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Get the token TTL in seconds.
     */
    public function getTokenTtl(): int
    {
        return $this->tokenTtlSeconds;
    }

    /**
     * Encode a payload into a JWT.
     *
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            $headerEncoded . '.' . $payloadEncoded,
            $this->secretKey,
            true
        );

        $signatureEncoded = $this->base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Decode and validate a JWT.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Verify signature
        $expectedSignature = hash_hmac(
            'sha256',
            $headerEncoded . '.' . $payloadEncoded,
            $this->secretKey,
            true
        );

        $signature = $this->base64UrlDecode($signatureEncoded);
        if ($signature === false || !hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $payload = $this->base64UrlDecode($payloadEncoded);
        if ($payload === false) {
            return null;
        }

        $data = json_decode($payload, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Base64 URL encode.
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode.
     */
    private function base64UrlDecode(string $data): string|false
    {
        $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');
        return base64_decode(strtr($padded, '-_', '+/'));
    }

    /**
     * Find a user by ID.
     */
    private function findUserById(int $id): ?User
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM users WHERE id = :id AND active = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new User($row) : null;
    }

    /**
     * Set JWT tokens as httpOnly secure cookies.
     *
     * This method should be called after successful authentication to store
     * tokens in secure cookies instead of returning them in the response body.
     *
     * @param string $accessToken The JWT access token
     * @param string $refreshToken The JWT refresh token
     * @param bool $secure Whether to set Secure flag (should be true in production)
     * @return void
     */
    public function setTokenCookies(string $accessToken, string $refreshToken, bool $secure = true): void
    {
        $sameSite = 'Strict';

        // Access token cookie - short-lived, httpOnly, secure
        // Note: domain key is intentionally omitted to restrict cookie to exact current domain
        // (prevents subdomain access which could be a security risk if a subdomain is compromised)
        setcookie(self::ACCESS_TOKEN_COOKIE, $accessToken, [
            'expires' => time() + $this->tokenTtlSeconds,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);

        // Refresh token cookie - longer-lived, httpOnly, secure
        setcookie(self::REFRESH_TOKEN_COOKIE, $refreshToken, [
            'expires' => time() + $this->refreshTtlSeconds,
            'path' => '/api/auth', // Restrict to auth endpoints
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    /**
     * Clear JWT token cookies (for logout).
     *
     * @return void
     */
    public function clearTokenCookies(): void
    {
        $expireTime = time() - 3600;

        setcookie(self::ACCESS_TOKEN_COOKIE, '', [
            'expires' => $expireTime,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        setcookie(self::REFRESH_TOKEN_COOKIE, '', [
            'expires' => $expireTime,
            'path' => '/api/auth',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Get access token from httpOnly cookie.
     *
     * @return string|null The access token or null if not present
     */
    public function getAccessTokenFromCookie(): ?string
    {
        return $_COOKIE[self::ACCESS_TOKEN_COOKIE] ?? null;
    }

    /**
     * Get refresh token from httpOnly cookie.
     *
     * @return string|null The refresh token or null if not present
     */
    public function getRefreshTokenFromCookie(): ?string
    {
        return $_COOKIE[self::REFRESH_TOKEN_COOKIE] ?? null;
    }

    /**
     * Validate token from cookie and return user.
     *
     * @return User|null The authenticated user or null
     */
    public function validateTokenFromCookie(): ?User
    {
        $token = $this->getAccessTokenFromCookie();

        if ($token === null) {
            return null;
        }

        return $this->validateToken($token);
    }

    /**
     * Get the access token cookie name for external reference.
     *
     * @return string
     */
    public static function getAccessTokenCookieName(): string
    {
        return self::ACCESS_TOKEN_COOKIE;
    }

    /**
     * Get the refresh token cookie name for external reference.
     *
     * @return string
     */
    public static function getRefreshTokenCookieName(): string
    {
        return self::REFRESH_TOKEN_COOKIE;
    }

    /**
     * Check if running in secure context (HTTPS).
     *
     * @return bool True if HTTPS is enabled
     */
    public static function isSecureContext(): bool
    {
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
}
