<?php

require __DIR__ . '/../bootstrap.php';

use App\Database\Connection;
use App\Database\SchemaRefiner;
use App\Database\Seeders\DatabaseSeeder;
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
$schemaRefiner = new SchemaRefiner($connection);
$schemaRefiner->ensureIndexes();
$schemaRefiner->backfillDefaults();

$seeder = new DatabaseSeeder($connection);
$seeder->seed();

echo "Database seeded and schema refined.\n";
