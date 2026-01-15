<?php

require __DIR__ . '/../bootstrap.php';

use App\Database\Connection;
use App\Support\Audit\AuditLogger;
use App\Support\Env;
use App\Services\Integrations\PartnerDispatchService;
use App\Services\Integrations\PartnerDispatchAdapterRegistry;
use App\Services\Integrations\PartnerEmailParser;
use App\Services\Integrations\AaaPartnerDispatchAdapter;
use App\Services\Integrations\GeicoPartnerDispatchAdapter;
use App\Services\Integrations\AgeroPartnerDispatchAdapter;

$options = getopt('', ['partner:', 'file::', 'attachments::', 'help']);

if (isset($options['help']) || empty($options['partner'])) {
    echo "Usage: php bin/parse_partner_email.php --partner=aaa [--file=/path/to/email.eml] [--attachments=/path/to/attachments.json]\n";
    exit(1);
}

$partner = (string) $options['partner'];
$rawEmail = '';
if (!empty($options['file'])) {
    $rawEmail = (string) file_get_contents($options['file']);
} else {
    $rawEmail = (string) stream_get_contents(STDIN);
}

if ($rawEmail === '') {
    echo "No email content provided.\n";
    exit(1);
}

$attachments = [];
if (!empty($options['attachments'])) {
    $rawAttachments = file_get_contents($options['attachments']);
    if ($rawAttachments !== false) {
        $decoded = json_decode($rawAttachments, true);
        if (is_array($decoded)) {
            $attachments = $decoded;
        }
    }
}

$env = new Env(__DIR__ . '/../.env');
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
$auditConfig = require __DIR__ . '/../config/audit.php';
$auditLogger = new AuditLogger($connection, $auditConfig);

$registry = new PartnerDispatchAdapterRegistry([
    new AaaPartnerDispatchAdapter(),
    new GeicoPartnerDispatchAdapter(),
    new AgeroPartnerDispatchAdapter(),
]);

$dispatchService = new PartnerDispatchService(
    $connection,
    $auditLogger,
    $registry,
    new PartnerEmailParser()
);

$result = $dispatchService->ingestEmailDispatch($partner, $rawEmail, $attachments);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

exit(($result['status'] ?? '') === 'failed' ? 1 : 0);
