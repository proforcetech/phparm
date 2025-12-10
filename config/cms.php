<?php

/**
 * CMS Configuration
 *
 * This configuration integrates the FixItForUs CMS with the main application
 */

// Get the main database config to reuse
$mainDbConfig = require __DIR__ . '/database.php';

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

    // Database configuration (reuses main app's database config)
    'database' => $mainDbConfig,

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
