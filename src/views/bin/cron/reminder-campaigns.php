<?php

/**
 * Reminder Campaign Scheduler Cron Job
 *
 * Sends due reminder campaigns to customers based on their preferences.
 *
 * Recommended cron schedule: every 15 minutes
 * Example: */15 * * * * php /path/to/bin/cron/reminder-campaigns.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Reminder\ReminderCampaignService;
use App\Services\Reminder\ReminderLogService;
use App\Services\Reminder\ReminderPreferenceService;
use App\Services\Reminder\ReminderScheduler;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditLogRepository;
use App\Support\Env;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationLogRepository;
use App\Support\Notifications\TemplateEngine;

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

// Initialize services
$templateEngine = new TemplateEngine();
$notificationDispatcher = new NotificationDispatcher(
    $notificationsConfig,
    $templateEngine,
    new NotificationLogRepository($connection)
);

$auditLogger = new AuditLogger(new AuditLogRepository($connection));

$campaignService = new ReminderCampaignService($connection, $auditLogger);
$preferenceService = new ReminderPreferenceService($connection, $auditLogger);
$logService = new ReminderLogService($connection);

$scheduler = new ReminderScheduler(
    $connection,
    $campaignService,
    $preferenceService,
    $notificationDispatcher,
    $logService,
    $templateEngine,
    $auditLogger
);

// Use system user ID for audit logging (1 = admin)
$systemActorId = 1;

try {
    $sentCount = $scheduler->sendDueCampaigns($systemActorId);

    echo sprintf(
        "[%s] Reminder campaigns processed: %d messages sent\n",
        date('Y-m-d H:i:s'),
        $sentCount
    );
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] Error processing reminder campaigns: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));
    exit(1);
}
