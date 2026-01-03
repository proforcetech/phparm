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
 */
class JwtService
{
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
        if (strlen($secretKey) < 32) {
            throw new InvalidArgumentException('JWT secret key must be at least 32 characters');
        }

        $this->connection = $connection;
        $this->secretKey = $secretKey;
        $this->tokenTtlSeconds = $tokenTtlSeconds;
        $this->refreshTtlSeconds = $refreshTtlSeconds;
    }

    /**
     * Generate an access token for a user.
     */
    public function generateToken(User $user): string
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

        return $this->encode($payload);
    }

    /**
     * Generate a refresh token for a user.
     */
    public function generateRefreshToken(User $user): string
    {
        $now = time();
        $payload = [
            'iss' => 'phparm',
            'sub' => $user->id,
            'iat' => $now,
            'exp' => $now + $this->refreshTtlSeconds,
            'type' => 'refresh',
            'jti' => bin2hex(random_bytes(16)), // Unique token ID
        ];

        return $this->encode($payload);
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
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     */
    public function refreshTokens(string $refreshToken): ?array
    {
        $user = $this->validateRefreshToken($refreshToken);

        if ($user === null) {
            return null;
        }

        return [
            'access_token' => $this->generateToken($user),
            'refresh_token' => $this->generateRefreshToken($user),
            'expires_in' => $this->tokenTtlSeconds,
            'token_type' => 'Bearer',
        ];
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
            'SELECT * FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new User($row) : null;
    }
}
