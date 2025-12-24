<?php

/**
 * Data Cleanup Cron Job
 *
 * Cleans up expired and temporary data:
 * - Expired password reset tokens
 * - Expired email verification tokens
 * - Old rate limit files
 * - Old notification logs (configurable retention)
 * - Old audit logs (configurable retention)
 *
 * Recommended cron schedule: daily at 2 AM
 * Example: 0 2 * * * php /path/to/bin/cron/data-cleanup.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Support\Env;
use App\Support\Http\RateLimiter;
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

$connection = new Connection($dbConfig);
$settings = new SettingsRepository($connection);
$pdo = $connection->pdo();

// Configuration
$auditRetentionDays = (int) $settings->get('cleanup.audit_retention_days', $env->get('AUDIT_RETENTION_DAYS', 90));
$notificationRetentionDays = (int) $settings->get('cleanup.notification_retention_days', $env->get('NOTIFICATION_RETENTION_DAYS', 30));

$stats = [
    'password_reset_tokens' => 0,
    'email_verification_tokens' => 0,
    'rate_limit_files' => 0,
    'notification_logs' => 0,
    'audit_logs' => 0,
    'payment_sessions' => 0,
];

echo sprintf("[%s] Starting data cleanup...\n", date('Y-m-d H:i:s'));

// 1. Clean up expired password reset tokens
try {
    $stmt = $pdo->prepare('DELETE FROM password_resets WHERE used = 1 OR expires_at < NOW()');
    $stmt->execute();
    $stats['password_reset_tokens'] = $stmt->rowCount();
    echo sprintf("  - Cleaned %d expired password reset tokens\n", $stats['password_reset_tokens']);
} catch (Throwable $e) {
    fwrite(STDERR, "  - Error cleaning password reset tokens: " . $e->getMessage() . "\n");
}

// 2. Clean up expired email verification tokens
try {
    $stmt = $pdo->prepare('DELETE FROM email_verifications WHERE used = 1 OR expires_at < NOW()');
    $stmt->execute();
    $stats['email_verification_tokens'] = $stmt->rowCount();
    echo sprintf("  - Cleaned %d expired email verification tokens\n", $stats['email_verification_tokens']);
} catch (Throwable $e) {
    fwrite(STDERR, "  - Error cleaning email verification tokens: " . $e->getMessage() . "\n");
}

// 3. Clean up rate limit files
try {
    $rateLimitPath = __DIR__ . '/../../storage/temp/ratelimits';
    if (is_dir($rateLimitPath)) {
        $rateLimiter = new RateLimiter($rateLimitPath);
        $stats['rate_limit_files'] = $rateLimiter->cleanup();
        echo sprintf("  - Cleaned %d expired rate limit files\n", $stats['rate_limit_files']);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "  - Error cleaning rate limit files: " . $e->getMessage() . "\n");
}

// 4. Clean up old notification logs
try {
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$notificationRetentionDays} days"));
    $stmt = $pdo->prepare('DELETE FROM notification_logs WHERE created_at < :cutoff');
    $stmt->execute(['cutoff' => $cutoffDate]);
    $stats['notification_logs'] = $stmt->rowCount();
    echo sprintf("  - Cleaned %d notification logs older than %d days\n", $stats['notification_logs'], $notificationRetentionDays);
} catch (Throwable $e) {
    fwrite(STDERR, "  - Error cleaning notification logs: " . $e->getMessage() . "\n");
}

// 5. Clean up old audit logs (optional, only if retention is configured)
if ($auditRetentionDays > 0) {
    try {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$auditRetentionDays} days"));
        $stmt = $pdo->prepare('DELETE FROM audit_logs WHERE created_at < :cutoff');
        $stmt->execute(['cutoff' => $cutoffDate]);
        $stats['audit_logs'] = $stmt->rowCount();
        echo sprintf("  - Cleaned %d audit logs older than %d days\n", $stats['audit_logs'], $auditRetentionDays);
    } catch (Throwable $e) {
        fwrite(STDERR, "  - Error cleaning audit logs: " . $e->getMessage() . "\n");
    }
}

// 6. Clean up expired payment sessions (older than 24 hours)
try {
    $stmt = $pdo->prepare('DELETE FROM payment_sessions WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)');
    $stmt->execute();
    $stats['payment_sessions'] = $stmt->rowCount();
    echo sprintf("  - Cleaned %d expired payment sessions\n", $stats['payment_sessions']);
} catch (Throwable $e) {
    fwrite(STDERR, "  - Error cleaning payment sessions: " . $e->getMessage() . "\n");
}

// 7. Clean up old reminder logs
try {
    $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$notificationRetentionDays} days"));
    $stmt = $pdo->prepare('DELETE FROM reminder_logs WHERE created_at < :cutoff');
    $stmt->execute(['cutoff' => $cutoffDate]);
    $reminderLogs = $stmt->rowCount();
    echo sprintf("  - Cleaned %d reminder logs older than %d days\n", $reminderLogs, $notificationRetentionDays);
} catch (Throwable $e) {
    // Table may not exist yet
}

// Summary
$total = array_sum($stats);
echo sprintf(
    "[%s] Data cleanup complete. Total records cleaned: %d\n",
    date('Y-m-d H:i:s'),
    $total
);
