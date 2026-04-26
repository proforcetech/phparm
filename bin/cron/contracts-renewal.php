<?php

/**
 * Contracts auto-renewal + expiry (Phase 4.3 of docs/expansion-plan.md).
 *
 * Daily job. Two actions:
 *   1. autoRenewDue()  — create successors, transition old → renewed.
 *   2. expireDue()     — transition non-renewing contracts past end_date → expired.
 *
 * Recommended schedule: 0 1 * * *  (daily at 01:00 local time).
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Contracts\ContractAmendmentRepository;
use App\Services\Contracts\ContractEntitlementRepository;
use App\Services\Contracts\ContractRenewalService;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSiteRepository;
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

$service = new ContractRenewalService(
    new ContractRepository($connection),
    new ContractSiteRepository($connection),
    new ContractEntitlementRepository($connection),
    new ContractAmendmentRepository($connection),
    $audit,
);

try {
    $renewed = $service->autoRenewDue();
    $expired = $service->expireDue();
    echo sprintf(
        "[%s] contracts-renewal: renewed=%d expired=%d\n",
        date('Y-m-d H:i:s'),
        count($renewed),
        count($expired)
    );
    foreach ($renewed as $row) {
        echo "  renewed old={$row['old_id']} → new={$row['new_id']} ({$row['new_contract_number']})\n";
    }
    foreach ($expired as $id) {
        echo "  expired contract_id={$id}\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] contracts-renewal FAILED: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));
    exit(1);
}
