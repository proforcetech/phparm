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
            // Estimate events
            'estimate.sent' => 'estimate_sent',

            // Invoice events
            'invoice.created' => 'invoice_created',
            'invoice.paid' => 'invoice_paid',

            // Appointment events
            'appointment.confirmed' => 'appointment_confirmed',

            // Warranty events
            'warranty.updated' => 'warranty_update',

            // Payment events
            'payment.reminder' => 'payment_reminder',

            // Tracking events
            'job_tracking_link' => 'job_tracking_link',

            // Workorder status events
            'workorder.workorder_parts_pending' => 'workorder_parts_pending',
            'workorder.workorder_parts_pending_manager' => 'workorder_parts_pending_manager',
            'workorder.workorder_in_progress' => 'workorder_in_progress',
            'workorder.workorder_on_hold' => 'workorder_on_hold',
            'workorder.workorder_on_hold_tech' => 'workorder_on_hold_tech',
            'workorder.workorder_completed' => 'workorder_completed',
            'workorder.workorder_completed_manager' => 'workorder_completed_manager',
            'workorder.workorder_ready_pickup' => 'workorder_ready_pickup',
            'workorder.workorder_ready_pickup_sms' => 'workorder_ready_pickup_sms',
            'workorder.workorder_awaiting_auth' => 'workorder_awaiting_auth',
            'workorder.workorder_awaiting_auth_sms' => 'workorder_awaiting_auth_sms',
            'workorder.workorder_cancelled' => 'workorder_cancelled',
            'workorder.workorder_cancelled_tech' => 'workorder_cancelled_tech',
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
