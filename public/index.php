<?php

/**
 * Main application entry point
 * Handles all HTTP requests through the router
 */

$config = require __DIR__ . '/../bootstrap.php';

use App\Database\Connection;
use App\Support\Http\Request;
use App\Support\Http\Router;

// Initialize database connection
$connection = new Connection($config['database']);

// Create router instance
$router = new Router();

// Load route definitions
$routeLoader = require __DIR__ . '/../routes/api.php';
$routeLoader($router, $config, $connection);

// Capture incoming request
$request = Request::capture();

// Dispatch and send response
$response = $router->dispatch($request);
$response->send();
