<?php

/**
 * Retention Runner Cron Job
 *
 * Iterates every active row in `data_retention_policies` and applies it
 * (delete or archive) per the configured retention window. Each policy
 * gets its own row in `data_retention_runs` capturing examined/affected
 * counts so ops can audit "what got purged on 2026-04-25 03:00".
 *
 * Recommended schedule: daily at 3 AM (one hour after data-cleanup so
 * the two don't compete on the same hot tables).
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Retention\RetentionPolicyRepository;
use App\Services\Retention\RetentionRunRepository;
use App\Services\Retention\RetentionRunner;
use App\Support\Auth\AccessGate;
use App\Support\Auth\PolicyRegistry;
use App\Support\Auth\RolePermissions;
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

// Cron has no logged-in user — pass null actor and skip the gate
// inside the runner. The gate is bypassed safely because (a) cron
// has filesystem-level access already and (b) RetentionRunner only
// calls assert() when actor !== null.
$policyRepo = new RetentionPolicyRepository($connection);
$runRepo = new RetentionRunRepository($connection);
$rolePermissions = new RolePermissions(require __DIR__ . '/../../config/auth.php');
$gate = new AccessGate($rolePermissions, new PolicyRegistry());
$runner = new RetentionRunner($connection, $policyRepo, $runRepo, $gate);

$dryRun = in_array('--dry-run', $argv, true);

echo sprintf("[%s] Retention runner started%s\n", date('Y-m-d H:i:s'), $dryRun ? ' (dry-run)' : '');

$runs = $runner->runAllActive(null, $dryRun);

$successes = 0;
$failures = 0;
$totalAffected = 0;
foreach ($runs as $run) {
    $totalAffected += (int) ($run->records_affected ?? 0);
    if (in_array($run->status, ['success', 'dry_run', 'skipped'], true)) {
        $successes++;
    } else {
        $failures++;
    }
    echo sprintf(
        "  policy=%d status=%s examined=%s affected=%s%s\n",
        $run->policy_id,
        $run->status,
        $run->records_examined ?? 'n/a',
        $run->records_affected ?? 'n/a',
        $run->error_message !== null ? ' error=' . $run->error_message : ''
    );
}

echo sprintf(
    "[%s] Retention runner finished: %d ok, %d failed, %d records affected\n",
    date('Y-m-d H:i:s'),
    $successes,
    $failures,
    $totalAffected
);

exit($failures > 0 ? 1 : 0);
