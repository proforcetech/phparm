<?php

return [
    'default' => env('FILESYSTEM_DISK', 'public'),

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
        'logos' => 'logos',
        'attachments' => 'attachments',
        'signatures' => 'signatures',
        'receipts' => 'receipts',
    ],
];
