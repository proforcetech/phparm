<?php
/**
 * Bootstrap file - Initialize the CMS
 * FixItForUs CMS
 */

// Error reporting based on environment
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Define base paths
define('CMS_ROOT', dirname(__DIR__));
define('CMS_CONFIG', CMS_ROOT . '/config');
define('CMS_MODELS', CMS_ROOT . '/models');
define('CMS_CONTROLLERS', CMS_ROOT . '/controllers');
define('CMS_VIEWS', CMS_ROOT . '/views');
define('CMS_CACHE', CMS_ROOT . '/cache');
define('CMS_ASSETS', CMS_ROOT . '/assets');
define('CMS_INCLUDES', CMS_ROOT . '/includes');

// Load environment variables
// When integrated with the main phparm application, use its env() function
// Otherwise, load CMS-specific .env file
if (function_exists('env') && isset($GLOBALS['env'])) {
    // Integrated mode: populate $_ENV from main app's environment
    // This ensures CMS Database class can read the values
    $envVars = [
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET',
        'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_SECRET',
        'CACHE_ENABLED', 'CACHE_DRIVER', 'CACHE_TTL',
        'SESSION_LIFETIME', 'SESSION_NAME', 'ADMIN_PATH',
        'CMS_TABLE_PREFIX'
    ];

    foreach ($envVars as $var) {
        $value = env($var);
        if ($value !== null) {
            $_ENV[$var] = $value;
            putenv("$var=$value");
        }
    }
} else {
    // Standalone mode: load CMS .env file
    loadEnv(CMS_ROOT . '/.env');
}

// Set display errors based on debug mode
$debugMode = $_ENV['APP_DEBUG'] ?? 'false';
if ($debugMode === 'true' || $debugMode === true || $debugMode === '1') {
    ini_set('display_errors', 1);
}

// Set timezone
date_default_timezone_set('America/Detroit');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name($_ENV['SESSION_NAME'] ?? 'fixitforus_cms');
    session_start();
}

// Simple autoloader
spl_autoload_register(function ($class) {
    // Map namespaces to directories
    $namespaceMap = [
        'CMS\\Config\\' => CMS_CONFIG . '/',
        'CMS\\Models\\' => CMS_MODELS . '/',
        'CMS\\Controllers\\' => CMS_CONTROLLERS . '/',
        'CMS\\Includes\\' => CMS_INCLUDES . '/',
    ];

    foreach ($namespaceMap as $namespace => $directory) {
        if (strpos($class, $namespace) === 0) {
            $relativeClass = substr($class, strlen($namespace));
            $file = $directory . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
    }
    return false;
});

/**
 * Load environment variables from .env file
 */
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        // Try .env.example as fallback for development
        $examplePath = $path . '.example';
        if (file_exists($examplePath)) {
            $path = $examplePath;
        } else {
            return;
        }
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse key=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes
            if (preg_match('/^["\'](.*)["\']]$/', $value, $matches)) {
                $value = $matches[1];
            }

            // Set environment variable
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

/**
 * Get environment variable with default
 * Only define if not already defined by main application
 */
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}

/**
 * Escape HTML output
 */
function e(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate URL
 */
function url(string $path = ''): string
{
    $baseUrl = rtrim(env('APP_URL', ''), '/');
    return $baseUrl . '/' . ltrim($path, '/');
}

/**
 * Generate admin URL
 */
function adminUrl(string $path = ''): string
{
    $adminPath = env('ADMIN_PATH', '/admin');
    return url($adminPath . '/' . ltrim($path, '/'));
}

/**
 * Check if user is logged in
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current user ID
 */
function currentUserId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 */
function currentUserRole(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

/**
 * Check if current user has role
 */
function hasRole(string $role): bool
{
    return currentUserRole() === $role;
}

/**
 * Redirect to URL
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Set flash message
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][$type] = $message;
}

/**
 * Get and clear flash message
 */
function getFlash(string $type): ?string
{
    $message = $_SESSION['flash'][$type] ?? null;
    unset($_SESSION['flash'][$type]);
    return $message;
}

/**
 * CSRF token generation
 */
function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCsrfToken(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate CSRF input field
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}
