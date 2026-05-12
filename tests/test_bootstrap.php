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

/**
 * Register MySQL-compatible scalar functions on a SQLite PDO so tests can
 * execute production SQL paths that rely on NOW() / GREATEST() / LEAST().
 *
 * These are the only MySQL-isms reached by the three legacy v1 tests
 * (auth tokens, payment webhook reconciliation, invoice manual payment).
 * Add new shims here if a test starts hitting a different MySQL function.
 */
function registerMysqlCompatFunctions(PDO $pdo): void
{
    $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

    $pdo->sqliteCreateFunction('GREATEST', static function (...$args) {
        $vals = array_values(array_filter($args, static fn ($v) => $v !== null));
        return $vals === [] ? null : max($vals);
    });

    $pdo->sqliteCreateFunction('LEAST', static function (...$args) {
        $vals = array_values(array_filter($args, static fn ($v) => $v !== null));
        return $vals === [] ? null : min($vals);
    });

    // Production code reuses the same named placeholder multiple times in
    // some statements (e.g. :invoice_id, :status in payment_webhook_events
    // upserts). MySQL with emulated prepares accepts this; SQLite's native
    // prepares do not. Enabling emulation here keeps the production SQL
    // unchanged.
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
}
