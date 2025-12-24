<?php

require __DIR__ . '/../bootstrap.php';

use App\Database\Connection;
use App\Services\Financial\FinancialEntryMigrator;
use App\Support\Env;

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

$migrator = new FinancialEntryMigrator($connection, dirname(__DIR__));
$result = $migrator->migrate();

echo sprintf(
    "Updated %d financial entries. %d entries lacked metadata hints.%s\n",
    $result['updated'],
    count($result['missing']),
    count($result['missing']) ? ' Missing IDs: ' . implode(', ', $result['missing']) : ''
);
