<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use InvalidArgumentException;
use PDO;

class DriverPushTokenService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string, mixed>
     */
    public function registerToken(int $driverProfileId, string $token, ?string $platform = null): array
    {
        $token = trim($token);
        if ($driverProfileId <= 0 || $token === '') {
            throw new InvalidArgumentException('driver_profile_id and token are required');
        }

        $existing = $this->connection->pdo()->prepare(
            'SELECT * FROM driver_push_tokens WHERE driver_profile_id = :driver_profile_id AND token = :token LIMIT 1'
        );
        $existing->execute([
            'driver_profile_id' => $driverProfileId,
            'token' => $token,
        ]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $update = $this->connection->pdo()->prepare(
                'UPDATE driver_push_tokens
                 SET platform = :platform, last_seen_at = NOW(), updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'platform' => $platform,
                'id' => $row['id'],
            ]);

            $fetch = $this->connection->pdo()->prepare('SELECT * FROM driver_push_tokens WHERE id = :id');
            $fetch->execute(['id' => $row['id']]);
            return $fetch->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO driver_push_tokens
                (driver_profile_id, token, platform, last_seen_at, created_at)
             VALUES
                (:driver_profile_id, :token, :platform, NOW(), NOW())'
        );
        $insert->execute([
            'driver_profile_id' => $driverProfileId,
            'token' => $token,
            'platform' => $platform,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $fetch = $this->connection->pdo()->prepare('SELECT * FROM driver_push_tokens WHERE id = :id');
        $fetch->execute(['id' => $id]);

        return $fetch->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
