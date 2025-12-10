<?php

/**
 * Main application entry point
 * Handles API requests and delegates front-end rendering to the CMS
 */

// Determine if this request should be handled by the CMS front-end
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$normalizedPath = '/' . ltrim($requestUri, '/');

// Allow the health check endpoint and all /api routes to continue to the API router
$isApiRequest = str_starts_with($normalizedPath, '/api');
$isHealthCheck = $normalizedPath === '/health';

// Serve CMS assets directly if requested
if (!$isApiRequest && !$isHealthCheck) {
    $cmsRoot = realpath(__DIR__ . '/../cms-php');
    $assetPath = $cmsRoot ? realpath($cmsRoot . $requestUri) : false;

    if ($cmsRoot && $assetPath && str_starts_with($assetPath, $cmsRoot) && is_file($assetPath)) {
        $mimeType = mime_content_type($assetPath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mimeType);
        readfile($assetPath);
        return;
    }
}

// Route CMS admin requests directly to the CMS admin front controller
if (!$isApiRequest && ($normalizedPath === '/admin' || str_starts_with($normalizedPath, '/cms-php/admin'))) {
    require __DIR__ . '/../cms-php/admin.php';
    return;
}

// All other non-API requests are handled by the CMS front-end
if (!$isApiRequest && !$isHealthCheck) {
    require __DIR__ . '/../cms-php/index.php';
    return;
}

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

// Load CMS routes
$cmsRouteLoader = require __DIR__ . '/../routes/cms.php';
$cmsRouteLoader($router, $config, $connection);

// Capture incoming request
$request = Request::capture();

// Dispatch and send response
$response = $router->dispatch($request);
$response->send();
