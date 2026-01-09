<?php

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Inventory\InventoryItemRepository;
use App\Services\Inventory\InventoryLowStockService;
use App\Support\Env;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationLogRepository;
use App\Support\Notifications\TemplateEngine;
use App\Support\SettingsRepository;
use App\Services\User\UserRepository;

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

$dispatcher = new NotificationDispatcher(
    $notificationsConfig,
    new TemplateEngine(),
    new NotificationLogRepository($connection)
);

$recipientSetting = $settings->get('notifications.inventory.recipient');
$recipientList = normalizeRecipients($recipientSetting);

if ($recipientList === []) {
    $userRepository = new UserRepository($connection);
    $managers = $userRepository->listByRole('manager');
    foreach ($managers as $manager) {
        if (!empty($manager->email)) {
            $recipientList[] = $manager->email;
        }
    }
}

if ($recipientList === []) {
    $fallbackRecipient = $settings->get('shop.email', $env->get('NOTIFICATIONS_FROM_EMAIL'));
    $recipientList = normalizeRecipients($fallbackRecipient);
}

if ($recipientList === []) {
    fwrite(STDERR, "No inventory alert recipients configured. Set notifications.inventory.recipient, add a manager user, or provide NOTIFICATIONS_FROM_EMAIL in .env.\n");
    exit(1);
}

$recipient = implode(', ', $recipientList);
$subject = $settings->get('notifications.inventory.subject', $env->get('INVENTORY_LOW_STOCK_SUBJECT', 'Daily low stock summary'));

$service = new InventoryLowStockService(
    new InventoryItemRepository($connection),
    null,
    $dispatcher
);

$payload = $service->sendEmailAlert((string) $recipient, (string) $subject);

echo sprintf(
    "Sent low-stock email to %s with %d items at %s\n",
    $recipient,
    $payload['total'] ?? 0,
    date('c')
);

/**
 * @param mixed $value
 * @return array<int, string>
 */
function normalizeRecipients($value): array
{
    if ($value === null) {
        return [];
    }

    if (is_array($value)) {
        $recipients = array_map('strval', $value);
    } else {
        $recipients = preg_split('/[;,]+/', (string) $value) ?: [];
    }

    $recipients = array_map('trim', $recipients);
    $recipients = array_filter($recipients, static fn (string $recipient) => $recipient !== '');

    return array_values(array_unique($recipients));
}
