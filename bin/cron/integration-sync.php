<?php

/**
 * Integration Periodic Sync Runner
 *
 * Walks every connected third-party integration whose next_sync_at
 * has passed and runs the corresponding adapter's sync(). Each run
 * writes a sync log row (start + finish), advances next_sync_at by
 * the integration's cadence, and stamps last_sync_at/status.
 *
 * Recommended cadence: every 5 minutes. Integrations with a cadence
 * shorter than 5 minutes still resolve correctly — the runner walks
 * all due rows in a single tick.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Integrations\IntegrationService;
use App\Services\Integrations\IntegrationSyncLogRepository;
use App\Services\Integrations\ThirdParty\AccessControlAdapter;
use App\Services\Integrations\ThirdParty\GenericTelematicsAdapter;
use App\Services\Integrations\ThirdParty\GoogleMapsAdapter;
use App\Services\Integrations\ThirdParty\IntegrationAdapterRegistry;
use App\Services\Integrations\ThirdParty\MapboxAdapter;
use App\Services\Integrations\ThirdParty\QuickBooksOnlineAdapter;
use App\Services\Integrations\ThirdParty\TelecomMonitoringAdapter;
use App\Services\Integrations\ThirdParty\XeroAdapter;
use App\Services\Integrations\ThirdPartyIntegrationRepository;
use App\Support\Auth\AccessGate;
use App\Support\Auth\PolicyRegistry;
use App\Support\Auth\RolePermissions;
use App\Support\Crypto\FieldCipher;
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

$rolePermissions = new RolePermissions(require __DIR__ . '/../../config/auth.php');
$gate = new AccessGate($rolePermissions, new PolicyRegistry());

$registry = new IntegrationAdapterRegistry();
$registry->register(new QuickBooksOnlineAdapter());
$registry->register(new XeroAdapter());
$registry->register(new GoogleMapsAdapter());
$registry->register(new MapboxAdapter());
$registry->register(new GenericTelematicsAdapter());
$registry->register(new TelecomMonitoringAdapter());
$registry->register(new AccessControlAdapter());

$repo = new ThirdPartyIntegrationRepository($connection);
$logs = new IntegrationSyncLogRepository($connection);
$cipher = new FieldCipher(FieldCipher::DOMAIN_INTEGRATION_CREDENTIALS);
$service = new IntegrationService($repo, $logs, $registry, $cipher, $gate);

echo sprintf("[%s] Integration sync runner started\n", date('Y-m-d H:i:s'));

$results = $service->processDueSyncs();

$succeeded = 0;
$failed = 0;
foreach ($results as $r) {
    if (($r['status'] ?? '') === 'succeeded') {
        $succeeded++;
    } else {
        $failed++;
    }
    echo sprintf(
        "  integration=%d status=%s%s%s\n",
        $r['integration_id'] ?? 0,
        $r['status'] ?? '?',
        isset($r['records_in']) ? ' in=' . $r['records_in'] : '',
        isset($r['error']) ? ' error=' . $r['error'] : ''
    );
}

echo sprintf(
    "[%s] Integration sync runner finished: %d ok, %d failed (%d total)\n",
    date('Y-m-d H:i:s'),
    $succeeded,
    $failed,
    count($results)
);

exit($failed > 0 ? 1 : 0);
