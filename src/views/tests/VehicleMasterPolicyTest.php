<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\AccessMiddleware;
use App\Support\Auth\RolePermissions;
use App\Support\Auth\UnauthorizedException;

$config = loadAuthConfig();
$roles = new RolePermissions($config['roles']);
$gate = new AccessGate($roles);
$middleware = new AccessMiddleware($gate);

$admin = new User(['id' => 1, 'name' => 'Admin', 'role' => 'admin']);
$manager = new User(['id' => 2, 'name' => 'Manager', 'role' => 'manager']);
$technician = new User(['id' => 3, 'name' => 'Tech', 'role' => 'technician']);

$results = [];

$results[] = ['scenario' => 'admin can manage vehicle master', 'passed' => $gate->can($admin, 'vehicles.*')];
$results[] = ['scenario' => 'manager can manage vehicle master', 'passed' => $gate->can($manager, 'vehicles.*')];

$techBlocked = false;
try {
    $gate->assert($technician, 'vehicles.*');
} catch (UnauthorizedException $e) {
    $techBlocked = true;
}
$results[] = ['scenario' => 'technician blocked from vehicle master', 'passed' => $techBlocked];

$middlewareResult = $middleware->handle($manager, 'vehicles.*', fn () => 'ok');
$results[] = ['scenario' => 'middleware allows manager', 'passed' => $middlewareResult === 'ok'];

$failures = array_filter($results, static fn (array $row) => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All vehicle master access tests passed." . PHP_EOL;
