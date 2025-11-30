<?php

namespace App\Services\Reminder;

use App\Database\Connection;
use PDO;

class ReminderPreferenceService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function setPreference(int $customerId, string $channel, bool $enabled): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'REPLACE INTO reminder_preferences (customer_id, channel, enabled, updated_at) VALUES (:customer_id, :channel, :enabled, NOW())'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'channel' => $channel,
            'enabled' => $enabled ? 1 : 0,
        ]);
    }

    public function isSubscribed(int $customerId, string $channel): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT enabled FROM reminder_preferences WHERE customer_id = :customer_id AND channel = :channel'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'channel' => $channel,
        ]);

        $result = $stmt->fetchColumn();
        return $result === false ? true : (bool) $result;
    }

    public function unsubscribe(int $customerId, string $channel): void
    {
        $this->setPreference($customerId, $channel, false);
    }
}
