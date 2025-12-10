<?php

/**
 * CMS Configuration
 *
 * This configuration integrates the FixItForUs CMS with the main application
 */

return [
    // CMS base paths
    'paths' => [
        'root' => __DIR__ . '/../cms-php',
        'config' => __DIR__ . '/../cms-php/config',
        'models' => __DIR__ . '/../cms-php/models',
        'controllers' => __DIR__ . '/../cms-php/controllers',
        'views' => __DIR__ . '/../cms-php/views',
        'cache' => __DIR__ . '/../cms-php/cache',
        'assets' => __DIR__ . '/../cms-php/assets',
    ],

    // Database configuration (inherits from main app)
    'database' => [
        'host' => env('DB_HOST', 'localhost'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'phparm'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],

    // URL routing configuration
    'routes' => [
        'admin_prefix' => '/cms/admin',    // Admin panel URL prefix
        'public_prefix' => '/cms',         // Public CMS pages URL prefix
    ],

    // Session configuration
    'session' => [
        'name' => 'fixitforus_cms',
        'timeout' => 3600, // 1 hour
    ],

    // Security settings
    'security' => [
        'csrf_enabled' => true,
        'session_regenerate' => true,
    ],

    // Cache settings
    'cache' => [
        'enabled' => env('CMS_CACHE_ENABLED', true),
        'ttl' => env('CMS_CACHE_TTL', 3600), // 1 hour default
        'driver' => 'file', // file or database
    ],

    // Debug mode
    'debug' => env('APP_DEBUG', false),
];
