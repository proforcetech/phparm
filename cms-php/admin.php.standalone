<?php
/**
 * Admin Entry Point
 * FixItForUs CMS
 *
 * This file handles all admin panel routes
 * Access via: /cms-php/admin.php or configure web server to use /admin
 */

require_once __DIR__ . '/config/bootstrap.php';

use CMS\Controllers\AdminController;

$controller = new AdminController();

// Parse the request URI
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = dirname($scriptName);

// Remove base path and query string
$path = parse_url($requestUri, PHP_URL_PATH);
$path = str_replace($basePath, '', $path);
$path = trim($path, '/');

// Remove 'admin.php' if present
$path = preg_replace('/^admin\.php\/?/', '', $path);
$path = trim($path, '/');

// Get the HTTP method
$method = $_SERVER['REQUEST_METHOD'];

// Simple routing
try {
    switch (true) {
        // Dashboard
        case $path === '' || $path === 'dashboard':
            $controller->dashboard();
            break;

        // Authentication
        case $path === 'login' && $method === 'GET':
            $controller->loginForm();
            break;

        case $path === 'login' && $method === 'POST':
            $controller->login();
            break;

        case $path === 'logout':
            $controller->logout();
            break;

        // Pages
        case $path === 'pages':
            $controller->pagesList();
            break;

        case $path === 'pages/new':
            $controller->pageNew();
            break;

        case preg_match('/^pages\/edit\/(\d+)$/', $path, $matches):
            $controller->pageEdit((int) $matches[1]);
            break;

        case $path === 'pages/create' && $method === 'POST':
            $controller->pageCreate();
            break;

        case preg_match('/^pages\/update\/(\d+)$/', $path, $matches) && $method === 'POST':
            $controller->pageUpdate((int) $matches[1]);
            break;

        case preg_match('/^pages\/delete\/(\d+)$/', $path, $matches) && $method === 'POST':
            $controller->pageDelete((int) $matches[1]);
            break;

        // Components
        case $path === 'components':
            $controller->componentsList();
            break;

        case $path === 'components/new':
            $controller->componentNew();
            break;

        case preg_match('/^components\/edit\/(\d+)$/', $path, $matches):
            $controller->componentEdit((int) $matches[1]);
            break;

        case $path === 'components/create' && $method === 'POST':
            $controller->componentCreate();
            break;

        case preg_match('/^components\/update\/(\d+)$/', $path, $matches) && $method === 'POST':
            $controller->componentUpdate((int) $matches[1]);
            break;

        case preg_match('/^components\/delete\/(\d+)$/', $path, $matches) && $method === 'POST':
            $controller->componentDelete((int) $matches[1]);
            break;

        case preg_match('/^components\/duplicate\/(\d+)$/', $path, $matches):
            $controller->componentDuplicate((int) $matches[1]);
            break;

        // Templates
        case $path === 'templates':
            $controller->templatesList();
            break;

        case $path === 'templates/new':
            $controller->templateNew();
            break;

        case preg_match('/^templates\/edit\/(\d+)$/', $path, $matches):
            $controller->templateEdit((int) $matches[1]);
            break;

        case $path === 'templates/create' && $method === 'POST':
            $controller->templateCreate();
            break;

        case preg_match('/^templates\/update\/(\d+)$/', $path, $matches) && $method === 'POST':
            $controller->templateUpdate((int) $matches[1]);
            break;

        case preg_match('/^templates\/delete\/(\d+)$/', $path, $matches) && $method === 'POST':
            $controller->templateDelete((int) $matches[1]);
            break;

        // Cache
        case $path === 'cache':
            $controller->cachePage();
            break;

        case $path === 'cache/clear' && $method === 'POST':
            $controller->cacheClear();
            break;

        // Settings
        case $path === 'settings':
            $controller->settingsPage();
            break;

        case $path === 'settings/update' && $method === 'POST':
            $controller->settingsUpdate();
            break;

        // Users
        case $path === 'users':
            $controller->usersList();
            break;

        case $path === 'users/new':
            $controller->userNew();
            break;

        case preg_match('/^users\/edit\/(\d+)$/', $path, $matches):
            $controller->userEdit((int) $matches[1]);
            break;

        case $path === 'users/create' && $method === 'POST':
            $controller->userCreate();
            break;

        case preg_match('/^users\/update\/(\d+)$/', $path, $matches) && $method === 'POST':
            $controller->userUpdate((int) $matches[1]);
            break;

        case preg_match('/^users\/delete\/(\d+)$/', $path, $matches) && $method === 'POST':
            $controller->userDelete((int) $matches[1]);
            break;

        // 404
        default:
            http_response_code(404);
            echo '<h1>404 - Page Not Found</h1>';
            echo '<p>The requested admin page does not exist.</p>';
            echo '<a href="' . adminUrl() . '">Go to Dashboard</a>';
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
