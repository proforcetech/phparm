<?php

/**
 * Lease expiry alert worker — Phase 13 (M3) of docs/woms-expansion-plan.md
 * (task #120). Fires the 90/60/30/0-day notice for active asset_leases rows
 * whose end_date is approaching, then stamps the alert column so the next
 * run is a no-op.
 *
 * Recipients (in order): notifications.lease.recipient setting → manager
 * users → shop.email setting → NOTIFICATIONS_FROM_EMAIL env. Mirrors the
 * pattern used by inventory-low-stock.
 *
 * Schedule: daily at 08:00 (managers' inbox first thing).
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;

// config/notifications.php uses env() at parse time. The unified cron runner
// doesn't load bootstrap.php (which defines env()), so shim it here. Other
// cron scripts share the same latent bug — fixing it project-wide is out of
// scope for this task.
$cronEnvFile = __DIR__ . '/../../.env';
$GLOBALS['env'] = new \App\Support\Env($cronEnvFile);
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        return $GLOBALS['env']->get($key, $default);
    }
}

use App\Services\Assets\AssetLeaseRepository;
use App\Services\Assets\LeaseExpiryAlertService;
use App\Services\User\UserRepository;
use App\Support\Audit\AuditLogger;
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

$connection = new Connection($dbConfig);
$settings = new SettingsRepository($connection);

$auditConfig = require __DIR__ . '/../../config/audit.php';
$audit = new AuditLogger($connection, $auditConfig);

$dispatcher = new NotificationDispatcher(
    $notificationsConfig,
    new TemplateEngine(),
    new NotificationLogRepository($connection),
    $audit,
);

$recipients = resolveRecipients($settings, $env, $connection);
if ($recipients === []) {
    fwrite(STDERR, "No lease alert recipients configured. Set notifications.lease.recipient, add a manager user, or provide NOTIFICATIONS_FROM_EMAIL in .env.\n");
    exit(1);
}

$service = new LeaseExpiryAlertService(
    new AssetLeaseRepository($connection),
    $dispatcher,
    $audit,
);

try {
    $summary = $service->runDaily($recipients);
    echo sprintf(
        "[%s] lease-expiry-alerts: sent=%d failed=%d skipped=%d recipients=%d\n",
        date('Y-m-d H:i:s'),
        count($summary['sent']),
        count($summary['failed']),
        $summary['skipped'],
        count($recipients),
    );
    foreach ($summary['sent'] as $row) {
        echo "  sent lease_id={$row['lease_id']} milestone={$row['milestone']}d\n";
    }
    foreach ($summary['failed'] as $row) {
        echo "  FAILED lease_id={$row['lease_id']} milestone={$row['milestone']}d: {$row['error']}\n";
    }
    exit(count($summary['failed']) > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] lease-expiry-alerts FAILED: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));
    exit(1);
}

/**
 * @return array<int, string>
 */
function resolveRecipients(SettingsRepository $settings, Env $env, Connection $connection): array
{
    $list = normalizeRecipients($settings->get('notifications.lease.recipient'));
    if ($list !== []) {
        return $list;
    }

    $userRepository = new UserRepository($connection);
    foreach ($userRepository->listByRole('manager') as $manager) {
        if (!empty($manager->email)) {
            $list[] = $manager->email;
        }
    }
    if ($list !== []) {
        return array_values(array_unique($list));
    }

    return normalizeRecipients(
        $settings->get('shop.email', $env->get('NOTIFICATIONS_FROM_EMAIL'))
    );
}

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
    $recipients = array_filter($recipients, static fn (string $r) => $r !== '');

    return array_values(array_unique($recipients));
}
