<?php

/**
 * Recurring service-route visit generator + overdue sweep.
 *
 * Phase 15 / M7 of docs/woms-expansion-plan.md.
 *
 * Two-phase tick:
 *   1. Roll every active service_route forward through its
 *      generation_horizon_days, materializing planned route_visits.
 *      Idempotent — INSERT IGNORE on (route_stop_id, scheduled_for) means
 *      a re-run inside the same window is a no-op.
 *   2. Sweep visits whose scheduled window has expired but are still
 *      planned/en_route and mark them missed.
 *
 * Recommended schedule: every 5 minutes. Visit generation is cheap (a
 * single SELECT + N small INSERTs per active route) and the sweeper has
 * to run often enough that "missed" tracks reality on the dispatcher
 * board.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\ServiceRoutes\RouteStopRepository;
use App\Services\ServiceRoutes\RouteVisitGenerator;
use App\Services\ServiceRoutes\RouteVisitPhotoRepository;
use App\Services\ServiceRoutes\RouteVisitRepository;
use App\Services\ServiceRoutes\RouteVisitService;
use App\Services\ServiceRoutes\ServiceRouteRepository;
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

$routes = new ServiceRouteRepository($connection);
$stops = new RouteStopRepository($connection);
$visits = new RouteVisitRepository($connection);
$photos = new RouteVisitPhotoRepository($connection);

$generator = new RouteVisitGenerator($routes, $stops, $visits);
$service = new RouteVisitService($connection, $routes, $stops, $visits, $photos, $audit);

try {
    $genResult = $generator->runDueRoutes();
    $missed = $service->sweepOverdueOpen();

    echo sprintf(
        "[%s] route-visit-generator: routes_processed=%d visits_created=%d missed_swept=%d\n",
        date('Y-m-d H:i:s'),
        $genResult['routes_processed'],
        $genResult['visits_created'],
        $missed
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] route-visit-generator FAILED: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));
    exit(1);
}
