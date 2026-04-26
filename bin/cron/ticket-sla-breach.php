<?php

/**
 * Ticket SLA Breach Detector (Phase 3.2 of docs/expansion-plan.md).
 *
 * Scans running SLA clocks that have exceeded their target, stamps
 * `breached_at`.  Escalation rules (Phase 3.4) pick up the breach state.
 *
 * Recommended schedule: every minute.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Tickets\SlaClockService;
use App\Services\Tickets\TicketSlaClockRepository;
use App\Services\Tickets\TicketSlaPolicyRepository;
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

$service = new SlaClockService(
    new TicketSlaClockRepository($connection),
    new TicketSlaPolicyRepository($connection)
);

try {
    $breached = $service->detectBreaches();
    echo sprintf(
        "[%s] SLA breach detection: %d clock(s) newly breached\n",
        date('Y-m-d H:i:s'),
        count($breached)
    );
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] SLA breach detection FAILED: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));
    exit(1);
}
