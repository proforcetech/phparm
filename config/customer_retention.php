<?php

return [
    'webhooks' => [
        'enabled' => env('CUSTOMER_RETENTION_WEBHOOKS_ENABLED', false),
        'endpoints' => array_filter(array_map('trim', explode(',', env('CUSTOMER_RETENTION_WEBHOOK_ENDPOINTS', '')))),
        'secret' => env('CUSTOMER_RETENTION_WEBHOOK_SECRET', ''),
        'timeout' => (int) env('CUSTOMER_RETENTION_WEBHOOK_TIMEOUT', 5),
    ],
];
