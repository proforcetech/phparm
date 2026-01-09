<?php

return [
    'partners' => [
        'agero' => [
            'retry' => [
                'attempts' => (int) env('AGERO_DISPATCH_RETRY_ATTEMPTS', 3),
                'backoff_ms' => (int) env('AGERO_DISPATCH_RETRY_BACKOFF_MS', 250),
            ],
            'protocols' => [
                'swift' => [
                    'accept_endpoint' => env('AGERO_SWIFT_ACCEPT_ENDPOINT', ''),
                    'status_endpoint' => env('AGERO_SWIFT_STATUS_ENDPOINT', ''),
                    'auth_token' => env('AGERO_SWIFT_AUTH_TOKEN', ''),
                    'timeout' => (int) env('AGERO_SWIFT_TIMEOUT', 5),
                ],
                'digital_dispatch' => [
                    'accept_endpoint' => env('AGERO_DIGITAL_DISPATCH_ACCEPT_ENDPOINT', ''),
                    'status_endpoint' => env('AGERO_DIGITAL_DISPATCH_STATUS_ENDPOINT', ''),
                    'auth_token' => env('AGERO_DIGITAL_DISPATCH_AUTH_TOKEN', ''),
                    'timeout' => (int) env('AGERO_DIGITAL_DISPATCH_TIMEOUT', 5),
                ],
            ],
        ],
        'aaa' => [
            'retry' => [
                'attempts' => (int) env('AAA_DISPATCH_RETRY_ATTEMPTS', 3),
                'backoff_ms' => (int) env('AAA_DISPATCH_RETRY_BACKOFF_MS', 250),
            ],
            'protocols' => [
                'swift' => [
                    'accept_endpoint' => env('AAA_SWIFT_ACCEPT_ENDPOINT', ''),
                    'status_endpoint' => env('AAA_SWIFT_STATUS_ENDPOINT', ''),
                    'auth_token' => env('AAA_SWIFT_AUTH_TOKEN', ''),
                    'timeout' => (int) env('AAA_SWIFT_TIMEOUT', 5),
                ],
                'digital_dispatch' => [
                    'accept_endpoint' => env('AAA_DIGITAL_DISPATCH_ACCEPT_ENDPOINT', ''),
                    'status_endpoint' => env('AAA_DIGITAL_DISPATCH_STATUS_ENDPOINT', ''),
                    'auth_token' => env('AAA_DIGITAL_DISPATCH_AUTH_TOKEN', ''),
                    'timeout' => (int) env('AAA_DIGITAL_DISPATCH_TIMEOUT', 5),
                ],
            ],
        ],
    ],
];
