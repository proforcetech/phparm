<?php

namespace App\Support\Notifications;

use App\Database\Connection;
use PDO;

class NotificationLogRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function log(NotificationLogEntry $entry): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO notification_logs (channel, recipient, template, payload, status, created_at) VALUES (:channel, :recipient, :template, :payload, :status, NOW())'
        );

        $stmt->execute([
            'channel' => $entry->channel,
            'recipient' => $entry->recipient,
            'template' => $entry->template,
            'payload' => json_encode($entry->payload, JSON_THROW_ON_ERROR),
            'status' => $entry->status,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM notification_logs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
