<?php

/**
 * Main application entry point
 * Handles API requests and delegates front-end rendering to the CMS
 */

// Load application bootstrap first (needed for CMS integration)
$config = require __DIR__ . '/../bootstrap.php';

// Determine if this request should be handled by the CMS front-end
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$normalizedPath = '/' . ltrim($requestUri, '/');

// Allow the health check endpoint and all /api routes to continue to the API router
$isApiRequest = str_starts_with($normalizedPath, '/api');
$isHealthCheck = $normalizedPath === '/health';

// Serve public assets directly if requested
if (!$isApiRequest && !$isHealthCheck) {
    $publicRoot = realpath(__DIR__);
    $assetPath = $publicRoot ? realpath($publicRoot . $requestUri) : false;

    if ($publicRoot && $assetPath && str_starts_with($assetPath, $publicRoot) && is_file($assetPath)) {
        $mimeType = mime_content_type($assetPath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mimeType);
        readfile($assetPath);
        return;
    }
}

// Bootstrap already loaded above, continue with API routing
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

// Load CMS routes
$cmsRouteLoader = require __DIR__ . '/../routes/cms.php';
$cmsRouteLoader($router, $config, $connection);

// Capture incoming request
$request = Request::capture();

// Dispatch and send response
$response = $router->dispatch($request);
$response->send();
