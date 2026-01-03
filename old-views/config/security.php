<?php

return [
    'auth_rate_limiting' => [
        'decay_seconds' => (int) env('AUTH_RATE_WINDOW', 60),
        'max_attempts_per_ip' => (int) env('AUTH_RATE_IP', 25),
        'max_attempts_per_identifier' => (int) env('AUTH_RATE_IDENTIFIER', 10),
        'lockout_threshold' => (int) env('AUTH_LOCKOUT_THRESHOLD', 8),
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),
        'captcha_after_attempts' => (int) env('AUTH_CAPTCHA_AFTER', 4),
        'captcha_cooldown_minutes' => (int) env('AUTH_CAPTCHA_DURATION', 10),
        'log_incidents' => true,
    ],
];
