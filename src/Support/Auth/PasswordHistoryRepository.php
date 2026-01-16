<?php

namespace App\Support\Auth;

use App\Database\Connection;
use PDO;

class PasswordHistoryRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function wasPasswordUsed(int $userId, string $password, int $limit): bool
    {
        if ($limit <= 0) {
            return false;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT password_hash FROM user_password_history WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $hash) {
            if (is_string($hash) && password_verify($password, $hash)) {
                return true;
            }
        }

        return false;
    }

    public function record(int $userId, string $passwordHash): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO user_password_history (user_id, password_hash, created_at) VALUES (:user_id, :password_hash, NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'password_hash' => $passwordHash,
        ]);
    }

    public function prune(int $userId, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM user_password_history
             WHERE user_id = :user_id
             AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM user_password_history WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT :limit
                ) recent
             )'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    }
}
