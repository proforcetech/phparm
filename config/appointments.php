<?php

return [
    'webhooks' => [
        'enabled' => env('APPOINTMENT_WEBHOOKS_ENABLED', false),
        // Comma-separated list of webhook endpoint URLs
        'endpoints' => array_filter(array_map('trim', explode(',', env('APPOINTMENT_WEBHOOK_ENDPOINTS', '')))),
        'secret' => env('APPOINTMENT_WEBHOOK_SECRET', ''),
        'timeout' => (int) env('APPOINTMENT_WEBHOOK_TIMEOUT', 5),
    ],
];
