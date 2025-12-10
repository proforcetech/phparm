<?php
/**
 * Front-end Entry Point
 * FixItForUs CMS
 *
 * This file handles all public page routes
 * Configure your web server to route requests here
 */

require_once __DIR__ . '/config/bootstrap.php';

use CMS\Controllers\PageController;

$controller = new PageController();

// Parse the request URI
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = dirname($scriptName);

// Remove base path and query string
$path = parse_url($requestUri, PHP_URL_PATH);
$path = str_replace($basePath, '', $path);
$path = trim($path, '/');

// Remove 'index.php' if present
$path = preg_replace('/^index\.php\/?/', '', $path);
$path = trim($path, '/');

try {
    // Special routes
    switch ($path) {
        case '':
        case 'home':
            $controller->renderHome();
            break;

        case 'sitemap.xml':
            $controller->renderSitemap();
            break;

        default:
            // Try to render the page by slug
            $controller->render($path);
            break;
    }
} catch (\Exception $e) {
    // Error handling
    http_response_code(500);

    if (env('APP_DEBUG')) {
        echo '<h1>Error</h1>';
        echo '<pre>' . e($e->getMessage()) . '</pre>';
        echo '<pre>' . e($e->getTraceAsString()) . '</pre>';
    } else {
        echo '<h1>Server Error</h1>';
        echo '<p>An error occurred. Please try again later.</p>';
    }
}
