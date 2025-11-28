<?php

$config = require __DIR__ . '/../bootstrap.php';

use App\Database\Connection;

$connection = new Connection($config['database']);

$health = [
    'app' => 'Automotive Repair Shop Management System',
    'environment' => env('APP_ENV', 'production'),
    'database' => 'not connected',
];

try {
    $connection->pdo();
    $health['database'] = 'connected';
} catch (Throwable $e) {
    $health['database'] = 'connection failed: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($health, JSON_PRETTY_PRINT);
