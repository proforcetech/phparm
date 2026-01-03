<?php

namespace App\Services\Notification;

use App\Database\Connection;
use App\Support\Notifications\NotificationDispatcher;
use PDO;

class NotificationEventService
{
    private Connection $connection;
    private NotificationDispatcher $dispatcher;

    public function __construct(Connection $connection, NotificationDispatcher $dispatcher)
    {
        $this->connection = $connection;
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function trigger(string $event, array $context): void
    {
        $templateKey = $this->resolveTemplate($event);
        if ($templateKey === null) {
            return;
        }

        $template = $this->fetchTemplate($templateKey);
        if ($template === null) {
            return;
        }

        $channel = $template['channel'] ?? 'email';
        $recipient = $context['recipient'] ?? null;
        if ($recipient === null) {
            return;
        }

        $body = strtr($template['body'], $context);
        $subject = strtr($template['subject'], $context);
        $this->dispatcher->dispatch($channel, $recipient, $subject, $body, [
            'event' => $event,
            'template' => $templateKey,
        ]);
    }

    private function resolveTemplate(string $event): ?string
    {
        $map = [
            'estimate.sent' => 'estimate_sent',
            'invoice.created' => 'invoice_created',
            'invoice.paid' => 'invoice_paid',
            'appointment.confirmed' => 'appointment_confirmed',
            'warranty.updated' => 'warranty_update',
            'payment.reminder' => 'payment_reminder',
        ];

        return $map[$event] ?? null;
    }

    private function fetchTemplate(string $key): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM notification_templates WHERE template_key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
