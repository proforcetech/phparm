<?php

return [
    'default' => env('FILESYSTEM_DISK', 'public'),
    'secure_url' => env('APP_URL', 'http://localhost') . '/download',
    'signing_key' => env('APP_KEY', 'dev-key-change-me'),

    'disks' => [
        'public' => [
            'driver' => 'local',
            'root' => __DIR__ . '/../storage/public',
            'url' => env('APP_URL', 'http://localhost') . '/storage',
            'visibility' => 'public',
        ],
        'private' => [
            'driver' => 'local',
            'root' => __DIR__ . '/../storage/private',
            'visibility' => 'private',
        ],
        'temp' => [
            'driver' => 'local',
            'root' => __DIR__ . '/../storage/temp',
            'visibility' => 'private',
        ],
    ],

    'upload_categories' => [
        'logos' => [
            'folder' => 'logos',
            'disk' => 'public',
            'visibility' => 'public',
        ],
        'attachments' => [
            'folder' => 'attachments',
            'disk' => 'private',
            'visibility' => 'private',
        ],
        'signatures' => [
            'folder' => 'signatures',
            'disk' => 'private',
            'visibility' => 'private',
        ],
        'receipts' => [
            'folder' => 'receipts',
            'disk' => 'public',
            'visibility' => 'public',
        ],
        'inspections' => [
            'folder' => 'inspections',
            'disk' => 'public',
            'visibility' => 'public',
        ],
    ],
];
