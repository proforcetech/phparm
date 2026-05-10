<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Support\Http\IpAddressResolver;
use App\Support\Http\Request;

$results = [];

putenv('TRUSTED_PROXIES');
unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

$directRequest = new Request('GET', '/demo', [], [], [], [
    'REMOTE_ADDR' => '198.51.100.10',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
]);

$results[] = [
    'scenario' => 'untrusted remote address ignores forwarded headers',
    'passed' => $directRequest->getClientIp() === '198.51.100.10',
];

putenv('TRUSTED_PROXIES=10.0.0.0/8,127.0.0.1');
$_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8,127.0.0.1';

$proxiedRequest = new Request('GET', '/demo', [], [], [], [
    'REMOTE_ADDR' => '10.1.2.3',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 10.1.2.3',
]);

$results[] = [
    'scenario' => 'trusted proxy allows forwarded client ip',
    'passed' => $proxiedRequest->getClientIp() === '203.0.113.77',
];

$results[] = [
    'scenario' => 'resolver falls back to remote addr when trusted proxy has invalid forwarded header',
    'passed' => IpAddressResolver::resolve([
        'REMOTE_ADDR' => '10.1.2.3',
        'HTTP_X_FORWARDED_FOR' => 'not-an-ip',
    ]) === '10.1.2.3',
];

// Spoofing defense: a malicious client supplies its own X-Forwarded-For;
// the trusted proxy *appends* the real client IP. Right-to-left walking
// (skipping trusted-proxy entries) must return the real client, not the
// leftmost spoofed value.
$spoofedRequest = new Request('GET', '/demo', [], [], [], [
    'REMOTE_ADDR' => '10.1.2.3',
    'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.77, 10.1.2.3',
]);
$results[] = [
    'scenario' => 'rightmost-untrusted parsing rejects client-supplied spoofed XFF entries',
    'passed' => $spoofedRequest->getClientIp() === '203.0.113.77',
];

// Multiple chained trusted proxies — the resolver must skip every trusted
// hop and return the first untrusted entry from the right.
$results[] = [
    'scenario' => 'multiple trusted proxies are skipped right-to-left',
    'passed' => IpAddressResolver::resolve([
        'REMOTE_ADDR' => '10.1.2.3',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 10.5.5.5, 10.1.2.3',
    ]) === '203.0.113.77',
];

$failures = array_filter($results, static fn (array $row): bool => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All IP address resolver tests passed." . PHP_EOL;
