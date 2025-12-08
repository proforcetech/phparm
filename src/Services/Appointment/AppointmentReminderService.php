<?php

namespace App\Services\Appointment;

use App\Database\Connection;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Service for sending appointment reminders to customers.
 *
 * Sends reminders for upcoming appointments based on configured lead time.
 */
class AppointmentReminderService
{
    private Connection $connection;
    private NotificationDispatcher $notifications;
    private ?AuditLogger $audit;
    private int $defaultLeadHours;

    public function __construct(
        Connection $connection,
        NotificationDispatcher $notifications,
        ?AuditLogger $audit = null,
        int $defaultLeadHours = 24
    ) {
        $this->connection = $connection;
        $this->notifications = $notifications;
        $this->audit = $audit;
        $this->defaultLeadHours = $defaultLeadHours;
    }

    /**
     * Send reminders for appointments due within the configured lead time.
     *
     * @param int $actorId User ID for audit logging
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function sendDueReminders(int $actorId): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        $appointments = $this->getUpcomingAppointments();

        foreach ($appointments as $appointment) {
            $result = $this->sendReminder($appointment, $actorId);

            if ($result === 'sent') {
                $stats['sent']++;
            } elseif ($result === 'failed') {
                $stats['failed']++;
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * Get appointments that need reminders sent.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getUpcomingAppointments(): array
    {
        $now = new DateTimeImmutable();
        $leadTime = $now->modify('+' . $this->defaultLeadHours . ' hours');

        $sql = <<<SQL
            SELECT
                a.id,
                a.customer_id,
                a.scheduled_at,
                a.notes,
                a.service_type_id,
                a.reminder_sent_at,
                c.first_name,
                c.last_name,
                c.email,
                c.phone,
                st.name as service_type_name
            FROM appointments a
            JOIN customers c ON c.id = a.customer_id
            LEFT JOIN service_types st ON st.id = a.service_type_id
            WHERE a.status IN ('scheduled', 'confirmed')
              AND a.scheduled_at BETWEEN :now AND :lead_time
              AND a.reminder_sent_at IS NULL
              AND c.deleted_at IS NULL
            ORDER BY a.scheduled_at ASC
        SQL;

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'now' => $now->format('Y-m-d H:i:s'),
            'lead_time' => $leadTime->format('Y-m-d H:i:s'),
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Send a reminder for a single appointment.
     *
     * @param array<string, mixed> $appointment
     * @return string 'sent', 'failed', or 'skipped'
     */
    private function sendReminder(array $appointment, int $actorId): string
    {
        $email = $appointment['email'] ?? null;

        if (empty($email)) {
            return 'skipped';
        }

        $customerName = trim(($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? ''));
        if ($customerName === '') {
            $customerName = 'Customer';
        }

        $scheduledAt = new DateTimeImmutable($appointment['scheduled_at']);
        $serviceName = $appointment['service_type_name'] ?? 'your appointment';

        $context = [
            'customer_name' => $customerName,
            'appointment_date' => $scheduledAt->format('l, F j, Y'),
            'appointment_time' => $scheduledAt->format('g:i A'),
            'service_type' => $serviceName,
            'notes' => $appointment['notes'] ?? '',
        ];

        $subject = sprintf(
            'Appointment Reminder: %s on %s',
            $serviceName,
            $scheduledAt->format('M j')
        );

        try {
            $this->notifications->sendMail(
                'appointment.reminder',
                (string) $email,
                $context,
                $subject
            );

            $this->markReminderSent((int) $appointment['id']);

            if ($this->audit !== null) {
                $this->audit->log(new AuditEntry(
                    'appointment.reminder_sent',
                    'appointment',
                    (string) $appointment['id'],
                    $actorId,
                    ['email' => $email, 'scheduled_at' => $appointment['scheduled_at']]
                ));
            }

            return 'sent';
        } catch (Throwable $e) {
            error_log(sprintf(
                'Failed to send appointment reminder for appointment %d: %s',
                $appointment['id'],
                $e->getMessage()
            ));
            return 'failed';
        }
    }

    /**
     * Mark an appointment as having had its reminder sent.
     */
    private function markReminderSent(int $appointmentId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE appointments SET reminder_sent_at = NOW(), updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $appointmentId]);
    }
}
