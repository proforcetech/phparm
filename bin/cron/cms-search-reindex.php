<?php

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\CMS\CMSIndexService;
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
$indexService = new CMSIndexService($connection, $env->get('CMS_TABLE_PREFIX', 'cms_'));

$counts = $indexService->reindexAll();

$timestamp = date('c');

echo sprintf(
    "[%s] CMS search index rebuilt for %d pages and %d components\n",
    $timestamp,
    $counts['pages'] ?? 0,
    $counts['components'] ?? 0
);
