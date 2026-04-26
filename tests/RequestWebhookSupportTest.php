<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Support\Http\Request;

$request = new Request(
    'POST',
    '/api/webhooks/payments/stripe?foo=bar',
    [],
    ['id' => 'evt_123'],
    ['HOST' => 'example.com'],
    ['HTTPS' => 'on', 'HTTP_HOST' => 'example.com'],
    [],
    '{"id":"evt_123"}'
);

$results = [
    [
        'scenario' => 'request preserves raw body for webhook verification',
        'passed' => $request->rawBody() === '{"id":"evt_123"}',
    ],
    [
        'scenario' => 'request builds full url for webhook verification',
        'passed' => $request->fullUrl() === 'https://example.com/api/webhooks/payments/stripe?foo=bar',
    ],
];

$failures = array_filter($results, static fn (array $row): bool => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All request webhook support tests passed." . PHP_EOL;
