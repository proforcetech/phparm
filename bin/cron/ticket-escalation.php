<?php

/**
 * Ticket Escalation Cron Job (Phase 3.4 of docs/expansion-plan.md).
 *
 * Walks every open ticket against every active rule, applies the first
 * match whose trigger has fired and whose cooldown has elapsed.
 *
 * Recommended schedule: every 5 minutes.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Tickets\SlaClockService;
use App\Services\Tickets\TicketEscalationEventRepository;
use App\Services\Tickets\TicketEscalationRuleRepository;
use App\Services\Tickets\TicketEscalationService;
use App\Services\Tickets\TicketEventRepository;
use App\Services\Tickets\TicketRepository;
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

$service = new TicketEscalationService(
    new TicketRepository($connection),
    new TicketEscalationRuleRepository($connection),
    new TicketEscalationEventRepository($connection),
    new TicketEventRepository($connection),
    new SlaClockService(
        new TicketSlaClockRepository($connection),
        new TicketSlaPolicyRepository($connection)
    )
);

try {
    $summary = $service->runOnce();
    echo sprintf(
        "[%s] Ticket escalation: %d tickets evaluated, %d rules fired, %d cooldown skips\n",
        date('Y-m-d H:i:s'),
        $summary['evaluated'],
        $summary['fired'],
        $summary['skipped_cooldown']
    );
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] Ticket escalation FAILED: %s\n%s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getTraceAsString()
    ));
    exit(1);
}
