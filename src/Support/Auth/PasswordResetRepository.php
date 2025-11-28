<?php

namespace App\Support\Auth;

use App\Database\Connection;
use App\Models\PasswordResetToken;
use DateInterval;
use DateTimeImmutable;
use PDO;

class PasswordResetRepository
{
    private Connection $connection;
    private int $expiryMinutes;

    public function __construct(Connection $connection, int $expiryMinutes)
    {
        $this->connection = $connection;
        $this->expiryMinutes = $expiryMinutes;
    }

    public function createToken(string $email): PasswordResetToken
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT' . $this->expiryMinutes . 'M'));

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO password_resets (email, token, expires_at, created_at) VALUES (:email, :token, :expires_at, NOW())'
        );
        $stmt->execute([
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return new PasswordResetToken([
            'id' => (int) $this->connection->pdo()->lastInsertId(),
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findValidToken(string $token): ?PasswordResetToken
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM password_resets WHERE token = :token AND used_at IS NULL LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $expiresAt = new DateTimeImmutable($row['expires_at']);
        if ($expiresAt < new DateTimeImmutable()) {
            return null;
        }

        return new PasswordResetToken($row);
    }

    public function markUsed(string $token): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE token = :token'
        );
        $stmt->execute(['token' => $token]);
    }
}
