<?php

namespace App\CMS;

/**
 * CMS Bootstrap
 *
 * Initializes the CMS system within the main application context
 */
class CMSBootstrap
{
    private static bool $initialized = false;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Initialize the CMS
     */
    public function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Define CMS constants
        $this->defineConstants();

        // Set up CMS environment
        $this->setupEnvironment();

        // Initialize session if needed
        $this->initSession();

        // Register CMS helper functions
        $this->registerHelpers();

        self::$initialized = true;
    }

    /**
     * Define CMS constants
     */
    private function defineConstants(): void
    {
        if (!defined('CMS_ROOT')) {
            define('CMS_ROOT', $this->config['paths']['root']);
        }
        if (!defined('CMS_CONFIG')) {
            define('CMS_CONFIG', $this->config['paths']['config']);
        }
        if (!defined('CMS_MODELS')) {
            define('CMS_MODELS', $this->config['paths']['models']);
        }
        if (!defined('CMS_CONTROLLERS')) {
            define('CMS_CONTROLLERS', $this->config['paths']['controllers']);
        }
        if (!defined('CMS_VIEWS')) {
            define('CMS_VIEWS', $this->config['paths']['views']);
        }
        if (!defined('CMS_CACHE')) {
            define('CMS_CACHE', $this->config['paths']['cache']);
        }
        if (!defined('CMS_ASSETS')) {
            define('CMS_ASSETS', $this->config['paths']['assets']);
        }
    }

    /**
     * Setup CMS environment
     */
    private function setupEnvironment(): void
    {
        // Set timezone
        date_default_timezone_set('America/Detroit');

        // Set error reporting based on debug mode
        if ($this->config['debug']) {
            ini_set('display_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
        }

        // Set CMS environment variables for the Database class
        $_ENV['DB_HOST'] = $this->config['database']['host'];
        $_ENV['DB_PORT'] = $this->config['database']['port'];
        $_ENV['DB_NAME'] = $this->config['database']['name'];
        $_ENV['DB_USER'] = $this->config['database']['user'];
        $_ENV['DB_PASSWORD'] = $this->config['database']['password'];
        $_ENV['DB_CHARSET'] = $this->config['database']['charset'];
        $_ENV['APP_DEBUG'] = $this->config['debug'];
    }

    /**
     * Initialize session for CMS
     */
    private function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name($this->config['session']['name']);
            session_start();
        }
    }

    /**
     * Register CMS helper functions
     */
    private function registerHelpers(): void
    {
        // Only register if functions don't already exist
        if (!function_exists('cms_url')) {
            /**
             * Generate CMS URL
             */
            function cms_url(string $path = ''): string {
                $baseUrl = rtrim(env('APP_URL', ''), '/');
                return $baseUrl . '/cms/' . ltrim($path, '/');
            }
        }

        if (!function_exists('cms_admin_url')) {
            /**
             * Generate CMS admin URL
             */
            function cms_admin_url(string $path = ''): string {
                $baseUrl = rtrim(env('APP_URL', ''), '/');
                return $baseUrl . '/cms/admin/' . ltrim($path, '/');
            }
        }

        if (!function_exists('cms_is_logged_in')) {
            /**
             * Check if CMS user is logged in
             */
            function cms_is_logged_in(): bool {
                return isset($_SESSION['cms_user_id']) && !empty($_SESSION['cms_user_id']);
            }
        }

        if (!function_exists('cms_current_user_id')) {
            /**
             * Get current CMS user ID
             */
            function cms_current_user_id(): ?int {
                return $_SESSION['cms_user_id'] ?? null;
            }
        }

        if (!function_exists('cms_flash')) {
            /**
             * Set CMS flash message
             */
            function cms_flash(string $type, string $message): void {
                $_SESSION['cms_flash'][$type] = $message;
            }
        }

        if (!function_exists('cms_get_flash')) {
            /**
             * Get and clear CMS flash message
             */
            function cms_get_flash(string $type): ?string {
                $message = $_SESSION['cms_flash'][$type] ?? null;
                unset($_SESSION['cms_flash'][$type]);
                return $message;
            }
        }

        if (!function_exists('cms_csrf_token')) {
            /**
             * Generate CMS CSRF token
             */
            function cms_csrf_token(): string {
                if (!isset($_SESSION['cms_csrf_token'])) {
                    $_SESSION['cms_csrf_token'] = bin2hex(random_bytes(32));
                }
                return $_SESSION['cms_csrf_token'];
            }
        }

        if (!function_exists('cms_verify_csrf')) {
            /**
             * Verify CMS CSRF token
             */
            function cms_verify_csrf(string $token): bool {
                return isset($_SESSION['cms_csrf_token']) && hash_equals($_SESSION['cms_csrf_token'], $token);
            }
        }

        if (!function_exists('cms_csrf_field')) {
            /**
             * Generate CMS CSRF input field
             */
            function cms_csrf_field(): string {
                return '<input type="hidden" name="csrf_token" value="' . cms_csrf_token() . '">';
            }
        }

        if (!function_exists('e')) {
            /**
             * Escape HTML output
             */
            function e(string $string): string {
                return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
            }
        }
    }

    /**
     * Check if CMS is initialized
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }
}
