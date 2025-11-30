<?php

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\ServiceType\ServiceTypeRepository;

class FakeConnection extends Connection
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

function setUpInMemoryDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE service_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(120) NOT NULL,
        alias VARCHAR(120) NULL,
        description TEXT NULL,
        active TINYINT(1) DEFAULT 1,
        display_order INT DEFAULT 0
    )');

    $pdo->exec('CREATE TABLE estimate_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_type_id INT NULL
    )');

    $pdo->exec('CREATE TABLE invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_type_id INT NULL
    )');

    return $pdo;
}

$pdo = setUpInMemoryDatabase();
$repository = new ServiceTypeRepository(new FakeConnection($pdo));

$serviceType = $repository->create([
    'name' => 'Brake Service',
    'alias' => 'brake',
    'description' => null,
    'active' => true,
    'display_order' => 1,
]);

$results = [];

$pdo->prepare('INSERT INTO estimate_jobs (service_type_id) VALUES (:id)')->execute(['id' => $serviceType->id]);

try {
    $repository->setActive($serviceType->id, false);
    $results[] = ['scenario' => 'blocking deactivation when referenced by estimate jobs', 'passed' => false];
} catch (InvalidArgumentException $e) {
    $results[] = ['scenario' => 'blocking deactivation when referenced by estimate jobs', 'passed' => true];
}

$pdo->prepare('INSERT INTO invoices (service_type_id) VALUES (:id)')->execute(['id' => $serviceType->id]);

try {
    $repository->delete($serviceType->id);
    $results[] = ['scenario' => 'blocking deletion when referenced by invoices', 'passed' => false];
} catch (InvalidArgumentException $e) {
    $results[] = ['scenario' => 'blocking deletion when referenced by invoices', 'passed' => true];
}

$unused = $repository->create([
    'name' => 'Oil Change',
    'alias' => null,
    'description' => null,
    'active' => true,
    'display_order' => 2,
]);

$updated = $repository->setActive($unused->id, false);
$results[] = ['scenario' => 'deactivation succeeds when unused', 'passed' => $updated !== null && $updated->active === false];

$removable = $repository->create([
    'name' => 'Alignment',
    'alias' => null,
    'description' => null,
    'active' => true,
    'display_order' => 3,
]);

$results[] = ['scenario' => 'deletion succeeds when unused', 'passed' => $repository->delete($removable->id) === true];

$failures = array_filter($results, static fn (array $row) => $row['passed'] === false);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All service type repository tests passed." . PHP_EOL;

