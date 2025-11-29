<?php
// Minimal PSR-4 autoloader for test execution without Composer runtime.
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/../src/';

    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});

/**
 * @return array<string, mixed>
 */
function loadAuthConfig(): array
{
    /** @var array<string, mixed> $config */
    $config = require __DIR__ . '/../config/auth.php';

    return $config;
}
