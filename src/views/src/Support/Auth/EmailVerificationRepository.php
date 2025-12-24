<?php

namespace App\Support\Auth;

use App\Database\Connection;
use App\Models\EmailVerificationToken;
use DateInterval;
use DateTimeImmutable;
use PDO;

class EmailVerificationRepository
{
    private Connection $connection;
    private int $expiryHours;

    public function __construct(Connection $connection, int $expiryHours)
    {
        $this->connection = $connection;
        $this->expiryHours = $expiryHours;
    }

    public function createToken(int $userId): EmailVerificationToken
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT' . $this->expiryHours . 'H'));

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO email_verifications (user_id, token, expires_at, created_at) VALUES (:user_id, :token, :expires_at, NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return new EmailVerificationToken([
            'id' => (int) $this->connection->pdo()->lastInsertId(),
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findValidToken(string $token): ?EmailVerificationToken
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM email_verifications WHERE token = :token AND used_at IS NULL LIMIT 1'
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

        return new EmailVerificationToken($row);
    }

    public function markUsed(string $token): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE email_verifications SET used_at = NOW() WHERE token = :token'
        );
        $stmt->execute(['token' => $token]);
    }
}
