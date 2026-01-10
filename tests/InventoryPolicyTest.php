<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\RolePermissions;
use App\Support\Auth\UnauthorizedException;

$config = loadAuthConfig();
$roles = new RolePermissions($config['roles']);
$gate = new AccessGate($roles);

$admin = new User(['id' => 1, 'name' => 'Admin', 'role' => 'admin']);
$manager = new User(['id' => 2, 'name' => 'Manager', 'role' => 'manager']);
$parts = new User(['id' => 3, 'name' => 'Parts', 'role' => 'parts']);
$technician = new User(['id' => 4, 'name' => 'Tech', 'role' => 'technician']);

$results = [];

$results[] = ['scenario' => 'admin can view inventory', 'passed' => $gate->can($admin, 'inventory.view')];
$results[] = ['scenario' => 'manager can edit inventory', 'passed' => $gate->can($manager, 'inventory.edit')];
$results[] = ['scenario' => 'manager can adjust inventory', 'passed' => $gate->can($manager, 'inventory.adjust')];
$results[] = ['scenario' => 'parts can adjust inventory', 'passed' => $gate->can($parts, 'inventory.adjust')];

$techBlocked = false;
try {
    $gate->assert($technician, 'inventory.edit');
} catch (UnauthorizedException $e) {
    $techBlocked = true;
}
$results[] = ['scenario' => 'technician blocked from editing inventory', 'passed' => $techBlocked];

$failures = array_filter($results, static fn (array $row) => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All inventory policy tests passed." . PHP_EOL;
