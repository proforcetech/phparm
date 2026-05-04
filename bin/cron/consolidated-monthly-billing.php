<?php

/**
 * Consolidated monthly billing — Phase 17 / M11 of docs/woms-expansion-plan.md.
 *
 * Runs once a month (recommend the 1st of the month at 02:00 local time) and
 * generates a single statement per opted-in customer for the prior calendar
 * month. Per-customer failures are isolated so one bad customer can't kill the
 * whole batch.
 *
 * Recommended schedule: 0 2 1 * *  (1st of every month at 02:00 local).
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\ConsolidatedBilling\ConsolidatedBillingService;
use App\Services\ConsolidatedBilling\ConsolidatedStatementRepository;
use App\Support\Audit\AuditLogger;
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
$auditConfig = require __DIR__ . '/../../config/audit.php';
$audit = new AuditLogger($connection, $auditConfig);

$repo = new ConsolidatedStatementRepository($connection);
$service = new ConsolidatedBillingService($connection, $repo, $audit);

// Default to the prior calendar month. Override via env vars BILLING_PERIOD_START
// / BILLING_PERIOD_END (YYYY-MM-DD) for backfills.
$periodStart = $env->get('BILLING_PERIOD_START')
    ?: date('Y-m-01', strtotime('first day of last month'));
$periodEnd = $env->get('BILLING_PERIOD_END')
    ?: date('Y-m-t', strtotime('last day of last month'));

try {
    $result = $service->runMonthlyBatch($periodStart, $periodEnd, null);
    echo sprintf(
        "[%s] consolidated-monthly-billing: period=%s..%s processed=%d failed=%d\n",
        date('Y-m-d H:i:s'),
        $periodStart,
        $periodEnd,
        $result['processed'],
        count($result['failures'])
    );
    foreach ($result['failures'] as $failure) {
        echo sprintf(
            "  FAILED customer_id=%d: %s\n",
            $failure['customer_id'],
            $failure['error']
        );
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] consolidated-monthly-billing FAILED: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));
    exit(1);
}
