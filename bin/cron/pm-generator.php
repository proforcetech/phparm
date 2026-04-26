<?php

/**
 * Preventative-maintenance ticket generator (Phase 5.3 of
 * docs/expansion-plan.md).
 *
 * Walks pm_schedules whose next_due_at - lead_time_days has been reached and
 * spawns a ticket per schedule, advancing the schedule's cadence via the
 * frequency engine. Idempotent on a per-day basis: the schedule's next_due_at
 * advances immediately after each generation, so a second run the same day
 * will skip everything that already fired.
 *
 * Recommended schedule: 0 2 * * * (daily at 02:00 local, after the contracts
 * renewal cron at 01:00 but before the daily inventory alert at 08:00).
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Pm\PmFrequencyService;
use App\Services\Pm\PmGenerationRepository;
use App\Services\Pm\PmGeneratorService;
use App\Services\Pm\PmPlanRepository;
use App\Services\Pm\PmScheduleRepository;
use App\Services\Tickets\TicketRepository;
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

$service = new PmGeneratorService(
    new PmScheduleRepository($connection),
    new PmPlanRepository($connection),
    new TicketRepository($connection),
    new PmGenerationRepository($connection),
    new PmFrequencyService(),
    $audit,
);

try {
    $result = $service->runDueThrough();
    echo sprintf(
        "[%s] pm-generator: generated=%d failed=%d\n",
        date('Y-m-d H:i:s'),
        $result['generated'],
        $result['failed']
    );
    foreach ($result['details'] as $d) {
        if (($d['status'] ?? null) === 'generated') {
            echo "  schedule={$d['schedule_id']} plan={$d['plan_id']} → ticket={$d['ticket_id']} due={$d['due_at']} next_due={$d['next_due_at']}\n";
        } else {
            echo "  schedule={$d['schedule_id']} FAILED: " . ($d['error'] ?? 'unknown') . "\n";
        }
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] pm-generator FAILED: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));
    exit(1);
}
