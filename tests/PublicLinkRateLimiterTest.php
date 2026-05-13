<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Support\Http\RateLimiter;
use App\Support\Security\PublicLinkRateLimiter;

$results = [];

$storage = sys_get_temp_dir() . '/phparm-rate-limit-' . bin2hex(random_bytes(6));
mkdir($storage, 0755, true);
register_shutdown_function(static function () use ($storage): void {
    foreach (glob($storage . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($storage);
});

$base = new RateLimiter($storage);
$limiter = new PublicLinkRateLimiter($base, [
    'decay_seconds' => 60,
    'max_attempts_per_ip' => 5,
    'max_attempts_per_link' => 3,
    'log_incidents' => false,
]);

// Scenario 1: first request is allowed.
$first = $limiter->hit('203.0.113.10', 'ABC123');
$results[] = [
    'scenario' => 'first request is allowed',
    'passed' => $first->allowed === true && $first->retryAfter === 0,
];

// Scenario 2: IP cap of 5 — sixth request must be denied; reason "ip".
$ip = '203.0.113.20';
for ($i = 0; $i < 4; $i++) {
    $limiter->hit($ip, null);
}
$fifth = $limiter->hit($ip, null);
$sixth = $limiter->hit($ip, null);
$results[] = [
    'scenario' => 'fifth IP hit allowed, sixth denied',
    'passed' => $fifth->allowed === true && $sixth->allowed === false && $sixth->reason === 'ip' && $sixth->retryAfter > 0,
];

// Scenario 3: link cap of 3 — fourth request to same link from any IP denied; reason "link".
$linkId = 'LINK-TARGET';
$limiter->hit('198.51.100.1', $linkId);
$limiter->hit('198.51.100.2', $linkId);
$limiter->hit('198.51.100.3', $linkId);
$fourthFromNewIp = $limiter->hit('198.51.100.4', $linkId);
$results[] = [
    'scenario' => 'link cap denies request from fresh IP after threshold',
    'passed' => $fourthFromNewIp->allowed === false && $fourthFromNewIp->reason === 'link' && $fourthFromNewIp->retryAfter > 0,
];

// Scenario 4: null link identifier never trips the link bucket.
$nullLinkIp = '203.0.113.99';
$results4 = [];
for ($i = 0; $i < 5; $i++) {
    $results4[] = $limiter->hit($nullLinkIp, null)->allowed;
}
$nullLinkSixth = $limiter->hit($nullLinkIp, null);
$results[] = [
    'scenario' => 'null link identifier still respects IP cap, never link cap',
    'passed' => $results4 === [true, true, true, true, true]
        && $nullLinkSixth->allowed === false
        && $nullLinkSixth->reason === 'ip',
];

// Scenario 5: check() is read-only — repeated check() calls must NOT trip the limit.
$readOnlyIp = '203.0.113.55';
for ($i = 0; $i < 20; $i++) {
    $limiter->check($readOnlyIp, 'NOTHING');
}
$afterChecks = $limiter->check($readOnlyIp, 'NOTHING');
$results[] = [
    'scenario' => 'check() does not consume budget',
    'passed' => $afterChecks->allowed === true && $afterChecks->ipAttempts === 0,
];

// Scenario 6: hashed link identifier — raw token never written to disk.
$rawToken = 'super-secret-token-' . bin2hex(random_bytes(8));
$limiter->hit('203.0.113.77', $rawToken);
$diskContents = '';
foreach (glob($storage . '/*.json') ?: [] as $file) {
    $diskContents .= (string) file_get_contents($file);
}
$results[] = [
    'scenario' => 'raw link identifier is never written to rate-limit storage',
    'passed' => $diskContents !== '' && !str_contains($diskContents, $rawToken),
];

// Scenario 7: payload shape for 429 response.
$denied = new \App\Support\Security\PublicLinkRateLimitResult(false, true, 42, 31, 11, 'ip_and_link');
$payload = $denied->toPayload('rate limited!');
$results[] = [
    'scenario' => 'result toPayload exposes retry_after, reason, error code',
    'passed' => $payload['error'] === 'rate_limited'
        && $payload['retry_after'] === 42
        && $payload['reason'] === 'ip_and_link'
        && $payload['message'] === 'rate limited!',
];

$failures = array_filter($results, static fn (array $row): bool => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo 'All public link rate limiter tests passed.' . PHP_EOL;
