<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Services\Invoice\InvoiceService;
use App\Services\Tracking\TrackingService;

$invoiceReflection = new \ReflectionClass(InvoiceService::class);
$invoiceService = $invoiceReflection->newInstanceWithoutConstructor();
$invoiceHashMethod = $invoiceReflection->getMethod('hashPublicToken');
$invoiceHashMethod->setAccessible(true);

$trackingReflection = new \ReflectionClass(TrackingService::class);
$trackingService = $trackingReflection->newInstanceWithoutConstructor();
$trackingHashMethod = $trackingReflection->getMethod('hashToken');
$trackingHashMethod->setAccessible(true);

$invoiceToken = str_repeat('a', 40);
$trackingToken = str_repeat('b', 48);

$invoiceHash = $invoiceHashMethod->invoke($invoiceService, $invoiceToken);
$trackingHash = $trackingHashMethod->invoke($trackingService, $trackingToken);

$results = [
    [
        'scenario' => 'invoice public token hashing uses sha256-sized output',
        'passed' => is_string($invoiceHash) && strlen($invoiceHash) === 64 && $invoiceHash !== $invoiceToken,
    ],
    [
        'scenario' => 'tracking token hashing uses sha256-sized output',
        'passed' => is_string($trackingHash) && strlen($trackingHash) === 64 && $trackingHash !== $trackingToken,
    ],
];

$failures = array_filter($results, static fn (array $row): bool => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All public link hashing contract tests passed." . PHP_EOL;
