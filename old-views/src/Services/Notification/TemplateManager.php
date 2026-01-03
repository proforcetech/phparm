<?php

namespace App\Services\Notification;

use App\Database\Connection;
use App\Support\Notifications\NotificationDispatcher;
use InvalidArgumentException;
use PDO;

class TemplateManager
{
    private Connection $connection;
    private NotificationDispatcher $dispatcher;

    public function __construct(Connection $connection, NotificationDispatcher $dispatcher)
    {
        $this->connection = $connection;
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param array<string, string> $template
     */
    public function save(string $key, array $template): void
    {
        if (!isset($template['subject'], $template['body'])) {
            throw new InvalidArgumentException('Template subject and body required');
        }

        $stmt = $this->connection->pdo()->prepare(
            'REPLACE INTO notification_templates (template_key, subject, body, channel) VALUES (:key, :subject, :body, :channel)'
        );
        $stmt->execute([
            'key' => $key,
            'subject' => $template['subject'],
            'body' => $template['body'],
            'channel' => $template['channel'] ?? 'email',
        ]);
    }

    public function get(string $key): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM notification_templates WHERE template_key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function testSend(string $key, string $channel, string $recipient, array $variables = []): bool
    {
        $template = $this->get($key);
        if ($template === null) {
            return false;
        }

        $rendered = strtr($template['body'], $variables);
        $this->dispatcher->dispatch($channel, $recipient, $template['subject'], $rendered, [
            'template_key' => $key,
            'preview' => true,
        ]);

        return true;
    }
}
