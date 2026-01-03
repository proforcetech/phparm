<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Integrations\PartsTechService;
use App\Support\Audit\AuditLogger;
use App\Support\SettingsRepository;

class PartsTechMemoryConnection extends Connection
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

function setupPartsTechDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        `key` VARCHAR(190) NOT NULL UNIQUE,
        `group` VARCHAR(120) NOT NULL,
        type VARCHAR(20) NOT NULL,
        value TEXT NULL,
        description TEXT NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL
    )');

    $pdo->exec('CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event VARCHAR(190) NOT NULL,
        entity_type VARCHAR(120) NOT NULL,
        entity_id INTEGER NOT NULL,
        actor_id INTEGER NULL,
        context TEXT NULL,
        created_at DATETIME NOT NULL
    )');

    return $pdo;
}

$pdo = setupPartsTechDatabase();
$connection = new PartsTechMemoryConnection($pdo);
$settings = new SettingsRepository($connection);
$pdo->prepare('INSERT INTO settings (`key`, `group`, type, value) VALUES (:key, :group, :type, :value)')->execute([
    'key' => 'integrations.partstech.api_base',
    'group' => 'integrations',
    'type' => 'string',
    'value' => 'https://api.partstech.com',
]);
$pdo->prepare('INSERT INTO settings (`key`, `group`, type, value) VALUES (:key, :group, :type, :value)')->execute([
    'key' => 'integrations.partstech.api_key',
    'group' => 'integrations',
    'type' => 'string',
    'value' => 'demo-key',
]);
$pdo->prepare('INSERT INTO settings (`key`, `group`, type, value) VALUES (:key, :group, :type, :value)')->execute([
    'key' => 'integrations.partstech.markup_tiers',
    'group' => 'integrations',
    'type' => 'json',
    'value' => json_encode([['rate' => 10]]),
]);

$httpCalls = [];
$fakeClient = function (string $method, string $url, array $headers, $body) use (&$httpCalls) {
    $httpCalls[] = [$method, $url, $headers, $body];

    if (str_contains($url, 'vehicles/lookup')) {
        return ['status' => 200, 'body' => json_encode(['data' => [
            'year' => 2020,
            'make' => 'Honda',
            'model' => 'Civic',
            'engine' => '2.0L',
        ]])];
    }

    return ['status' => 200, 'body' => json_encode([
        'items' => [
            ['description' => 'Oil Filter', 'brand' => 'Acme', 'partNumber' => 'OF-123', 'price' => 10.0],
            ['description' => 'Air Filter', 'brand' => 'Acme', 'partNumber' => 'AF-456', 'price' => 5.0],
        ],
    ])];
};

$audit = new AuditLogger($connection, ['enabled' => true, 'table' => 'audit_logs']);
$service = new PartsTechService($settings, $audit, $fakeClient);

// Successful VIN decode
$vinResult = $service->decodeVin('1hgbh41jXmn109186');
$expectedLabel = '2020 Honda Civic';

// Successful search with markup applied (10% on first tier)
$searchResult = $service->searchParts('filter', ['year' => '2020', 'make' => 'Honda']);
$firstPrice = $searchResult[0]['price'];

// Missing credentials should throw
$emptyPdo = setupPartsTechDatabase();
$emptyConnection = new PartsTechMemoryConnection($emptyPdo);
$missingSettings = new SettingsRepository($emptyConnection);
$missingService = new PartsTechService($missingSettings, null, $fakeClient);
$credentialExceptionThrown = false;
try {
    $missingService->decodeVin('123');
} catch (InvalidArgumentException $e) {
    $credentialExceptionThrown = true;
}

// HTTP error should be logged and throw
$errorClient = function () {
    return ['status' => 500, 'body' => json_encode(['message' => 'Upstream error'])];
};
$errorService = new PartsTechService($settings, $audit, $errorClient);
$errorThrown = false;
try {
    $errorService->searchParts('brake');
} catch (InvalidArgumentException $e) {
    $errorThrown = true;
}

$auditCount = (int) $pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();

$scenarios = [
    ['scenario' => 'vin lookup returns label', 'passed' => $vinResult['label'] === $expectedLabel],
    ['scenario' => 'search applies markup', 'passed' => abs($firstPrice - 11.0) < 0.0001],
    ['scenario' => 'missing credentials throws', 'passed' => $credentialExceptionThrown],
    ['scenario' => 'http error logs audit', 'passed' => $auditCount > 0 && $errorThrown],
    ['scenario' => 'http client called twice', 'passed' => count($httpCalls) === 2],
];

$failures = array_filter($scenarios, static fn (array $row) => $row['passed'] === false);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All PartsTech service tests passed." . PHP_EOL;

