<?php

/**
 * Appointment Reminder Cron Job
 *
 * Sends reminders to customers for upcoming appointments.
 *
 * Recommended cron schedule: every hour
 * Example: 0 * * * * php /path/to/bin/cron/appointment-reminders.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Appointment\AppointmentReminderService;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditLogRepository;
use App\Support\Env;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationLogRepository;
use App\Support\Notifications\TemplateEngine;
use App\Support\SettingsRepository;

$env = new Env(__DIR__ . '/../../.env');

$dbConfig = [
    'driver' => $env->get('DB_DRIVER', 'mysql'),
    'host' => $env->get('DB_HOST', '127.0.0.1'),
    'port' => (int) $env->get('DB_PORT', 3306),
    'database' => $env->get('DB_DATABASE', 'phparm'),
    'username' => $env->get('DB_USERNAME', 'root'),
    'password' => $env->get('DB_PASSWORD', ''),
    'charset' => $env->get('DB_CHARSET', 'utf8mb4'),
];

$notificationsConfig = require __DIR__ . '/../../config/notifications.php';
$notificationsConfig['mail']['from_name'] = $env->get('MAIL_FROM_NAME', $notificationsConfig['mail']['from_name'] ?? null);
$notificationsConfig['mail']['from_address'] = $env->get('MAIL_FROM_ADDRESS', $notificationsConfig['mail']['from_address'] ?? null);
$notificationsConfig['mail']['default'] = $env->get('MAIL_DRIVER', $notificationsConfig['mail']['default'] ?? 'log');
$notificationsConfig['sms']['default'] = $env->get('SMS_DRIVER', $notificationsConfig['sms']['default'] ?? 'log');

$connection = new Connection($dbConfig);
$settings = new SettingsRepository($connection);

// Get lead time from settings or use default (24 hours)
$leadHours = (int) $settings->get('appointments.reminder_lead_hours', $env->get('APPOINTMENT_REMINDER_HOURS', 24));

// Initialize services
$notificationDispatcher = new NotificationDispatcher(
    $notificationsConfig,
    new TemplateEngine(),
    new NotificationLogRepository($connection)
);

$auditLogger = new AuditLogger(new AuditLogRepository($connection));

$reminderService = new AppointmentReminderService(
    $connection,
    $notificationDispatcher,
    $auditLogger,
    $leadHours
);

// Use system user ID for audit logging (1 = admin)
$systemActorId = 1;

try {
    $stats = $reminderService->sendDueReminders($systemActorId);

    echo sprintf(
        "[%s] Appointment reminders: %d sent, %d failed, %d skipped\n",
        date('Y-m-d H:i:s'),
        $stats['sent'],
        $stats['failed'],
        $stats['skipped']
    );
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] Error sending appointment reminders: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));
    exit(1);
}
