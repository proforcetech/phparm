<?php

/**
 * SSO + Trusted-Device Sweep Cron Job
 *
 * Two table maintenance passes the auth subsystem needs but isn't worth
 * spinning up a dedicated runner for:
 *
 *   1. sso_login_attempts: any row still in 'pending' that's older than
 *      the configurable cutoff (default 1 hour) is rolled to 'expired'.
 *      Without this, a user that bails out mid-OIDC dance leaves a
 *      pending row forever — which is mostly harmless, but hides true
 *      pending state from operators querying the table.
 *
 *   2. trusted_devices: any row whose expires_at has passed is hard-
 *      deleted. We don't keep tombstones because the audit trail is
 *      already in security_events — keeping expired hashes around just
 *      grows the table for no benefit.
 *
 * Recommended schedule: daily at 3:30 AM (after retention runs at 3:00).
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Auth\TrustedDeviceRepository;
use App\Services\Sso\SsoLoginAttemptRepository;
use App\Support\Env;

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

$attemptRepo = new SsoLoginAttemptRepository($connection);
$deviceRepo = new TrustedDeviceRepository($connection);

// Pending SSO attempts older than 1 hour are dead for sure (the IdP
// authorize flow rarely takes > 5 min in practice).
$attemptCutoff = (new DateTimeImmutable())->modify('-1 hour')->format('Y-m-d H:i:s');

echo sprintf("[%s] Auth sweep started\n", date('Y-m-d H:i:s'));

$expired = $attemptRepo->expireStale($attemptCutoff);
echo sprintf("  sso_login_attempts: %d pending row(s) expired\n", $expired);

$purged = $deviceRepo->purgeExpired();
echo sprintf("  trusted_devices: %d expired row(s) purged\n", $purged);

echo sprintf("[%s] Auth sweep finished\n", date('Y-m-d H:i:s'));

exit(0);
