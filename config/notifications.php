<?php

return [
    'mail' => [
        'default' => env('MAIL_DRIVER', 'log'),
        'from_name' => env('MAIL_FROM_NAME', null),
        'from_address' => env('MAIL_FROM_ADDRESS', null),
        'drivers' => [
            'log' => [],
            'smtp' => [
                'host' => env('MAIL_HOST', 'smtp'),
                'port' => env('MAIL_PORT', 587),
                'username' => env('MAIL_USERNAME', null),
                'password' => env('MAIL_PASSWORD', null),
                'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            ],
        ],
    ],
    'sms' => [
        'default' => env('SMS_DRIVER', 'log'),
        'from_number' => env('SMS_FROM', null),
        'drivers' => [
            'log' => [],
            'twilio' => [
                'sid' => env('TWILIO_SID', null),
                'token' => env('TWILIO_TOKEN', null),
            ],
        ],
    ],
    'templates' => [
        'estimate.sent' => 'Your estimate {{estimate_number}} is ready. View at {{estimate_url}}.',
        'invoice.due' => 'Invoice {{invoice_number}} is due on {{due_date}}. Pay at {{invoice_url}}.',
    ],
];
