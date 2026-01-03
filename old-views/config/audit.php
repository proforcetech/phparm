<?php

return [
    'enabled' => env('AUDIT_ENABLED', true),
    'table' => 'audit_logs',
    'redact_keys' => [
        'password',
        'token',
        'secret',
    ],
];
