<?php

namespace App\Support\Http;

use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\JwtService;
use App\Support\Auth\ModuleAccessService;
use App\Support\Auth\RolePermissions;
use App\Support\Auth\UnauthorizedException;
use App\Support\Auth\UserSessionManager;

class Middleware
{
    private static ?RateLimiter $rateLimiter = null;
    private static ?JwtService $jwtService = null;
    private static ?ModuleAccessService $moduleService = null;
    private static ?UserSessionManager $sessionManager = null;
    private static ?Connection $activityConnection = null;

    /**
     * Get or create the default rate limiter instance.
     */
    private static function getRateLimiter(): RateLimiter
    {
        if (self::$rateLimiter === null) {
            $storagePath = dirname(__DIR__, 3) . '/storage/temp/ratelimits';
            self::$rateLimiter = new RateLimiter($storagePath);
        }
        return self::$rateLimiter;
    }

    /**
     * Set a custom rate limiter instance (for testing or custom configuration).
     */
    public static function setRateLimiter(RateLimiter $limiter): void
    {
        self::$rateLimiter = $limiter;
    }

    /**
     * Get or create the JWT service instance.
     */
    private static function getJwtService(): JwtService
    {
        if (self::$jwtService === null) {
            $configPath = dirname(__DIR__, 3) . '/config/auth.php';
            $config = file_exists($configPath) ? require $configPath : [];
            $jwtConfig = $config['jwt'] ?? [];

            $secret = $jwtConfig['secret'] ?? 'default-secret-key-change-in-production';
            $ttl = $jwtConfig['ttl'] ?? 3600;
            $refreshTtl = $jwtConfig['refresh_ttl'] ?? 604800;

            // Create database connection
            $dbConfigPath = dirname(__DIR__, 3) . '/config/database.php';
            $dbConfig = file_exists($dbConfigPath) ? require $dbConfigPath : [];
            $connection = new Connection($dbConfig);

            self::$jwtService = new JwtService($connection, $secret, $ttl, $refreshTtl);
        }
        return self::$jwtService;
    }

    /**
     * Set a custom JWT service instance (for testing or custom configuration).
     */
    public static function setJwtService(JwtService $service): void
    {
        self::$jwtService = $service;
    }

    /**
     * Get or create the module access service instance.
     */
    private static function getModuleService(): ModuleAccessService
    {
        if (self::$moduleService === null) {
            $configPath = dirname(__DIR__, 3) . '/config/auth.php';
            $config = file_exists($configPath) ? require $configPath : [];

            // Create database connection
            $dbConfigPath = dirname(__DIR__, 3) . '/config/database.php';
            $dbConfig = file_exists($dbConfigPath) ? require $dbConfigPath : [];
            $connection = new Connection($dbConfig);

            // Create role permissions and access gate
            $rolePermissions = new RolePermissions($config['roles'] ?? []);
            $gate = new AccessGate($rolePermissions);

            self::$moduleService = new ModuleAccessService($connection, $gate);
        }
        return self::$moduleService;
    }

    /**
     * Get or create the connection for activity tracking.
     */
    private static function getActivityConnection(): Connection
    {
        if (self::$activityConnection === null) {
            $dbConfigPath = dirname(__DIR__, 3) . '/config/database.php';
            $dbConfig = file_exists($dbConfigPath) ? require $dbConfigPath : [];
            self::$activityConnection = new Connection($dbConfig);
        }

        return self::$activityConnection;
    }

    /**
     * Update the user's last activity timestamp.
     */
    private static function recordUserActivity(int $userId): void
    {
        $connection = self::getActivityConnection();
        $stmt = $connection->pdo()->prepare('UPDATE users SET last_activity_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    /**
     * Set a custom module access service instance (for testing or custom configuration).
     */
    public static function setModuleService(ModuleAccessService $service): void
    {
        self::$moduleService = $service;
    }

    /**
     * Get or create the user session manager instance.
     */
    private static function getSessionManager(): UserSessionManager
    {
        if (self::$sessionManager === null) {
            $dbConfigPath = dirname(__DIR__, 3) . '/config/database.php';
            $dbConfig = file_exists($dbConfigPath) ? require $dbConfigPath : [];
            $connection = new Connection($dbConfig);

            self::$sessionManager = new UserSessionManager($connection);
        }
        return self::$sessionManager;
    }

    /**
     * Require access to a specific module.
     *
     * This middleware checks:
     * 1. Module is enabled at the shop level
     * 2. User's groups don't have this module disabled
     * 3. User has at least one permission in the module's permission prefix
     *
     * @param string $moduleKey The module key (e.g., 'towing', 'cms', 'inventory')
     */
    public static function module(string $moduleKey): callable
    {
        return function (Request $request, callable $next) use ($moduleKey) {
            $user = $request->getAttribute('user');

            if ($user === null) {
                throw new UnauthorizedException('Authentication required');
            }

            if (!($user instanceof User)) {
                throw new UnauthorizedException('Invalid user');
            }

            $moduleService = self::getModuleService();

            // Check if module is enabled globally
            if (!$moduleService->isModuleEnabled($moduleKey)) {
                return Response::json([
                    'error' => 'Module not available',
                    'message' => "The '{$moduleKey}' module is not enabled.",
                    'module' => $moduleKey,
                ], 403);
            }

            // Check if user can access the module
            if (!$moduleService->canUserAccessModule($user, $moduleKey)) {
                return Response::json([
                    'error' => 'Module access denied',
                    'message' => "You do not have access to the '{$moduleKey}' module.",
                    'module' => $moduleKey,
                ], 403);
            }

            return $next($request);
        };
    }

    /**
     * Authenticate user from session or bearer token
     */
    public static function auth(): callable
    {
        return function (Request $request, callable $next) {
            // Check session first
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $user = null;

            // Try session-based auth
            if (isset($_SESSION['user_id'])) {
                $sessionManager = self::getSessionManager();
                $sessionId = session_id();
                $ipAddress = $request->getClientIp();
                $userAgent = $request->header('HTTP_USER_AGENT') ?? $request->header('USER_AGENT');

                if (!$sessionManager->ensureSessionActive((int) $_SESSION['user_id'], $sessionId, $ipAddress, $userAgent)) {
                    session_destroy();
                    throw new UnauthorizedException('Session has been revoked');
                }

                // In a real implementation, fetch user from database
                $user = $_SESSION['user'] ?? null;
            }

            // Try bearer token auth
            if ($user === null) {
                $token = $request->bearerToken();
                if ($token !== null) {
                    // In a real implementation, validate token and fetch user
                    // For now, we'll just decode a simple token
                    $user = self::validateToken($token);
                }
            }

            if ($user === null) {
                throw new UnauthorizedException('Authentication required');
            }

            // Store user in request
            if (is_array($user)) {
                $userModel = new User($user);
                $request->setAttribute('user', $userModel);
            } else {
                $userModel = $user;
                $request->setAttribute('user', $userModel);
            }

            if ($userModel instanceof User) {
                self::recordUserActivity($userModel->id);
            }

            return $next($request);
        };
    }

    /**
     * Require specific permission
     */
    public static function can(string $permission, AccessGate $gate): callable
    {
        return function (Request $request, callable $next) use ($permission, $gate) {
            $user = $request->getAttribute('user');

            if ($user === null) {
                throw new UnauthorizedException('Authentication required');
            }

            if (!($user instanceof User)) {
                throw new UnauthorizedException('Invalid user');
            }

            if (!$gate->can($user, $permission)) {
                throw new UnauthorizedException("Permission denied: {$permission}");
            }

            return $next($request);
        };
    }

    /**
     * Require specific role
     */
    public static function role(string ...$roles): callable
    {
        return function (Request $request, callable $next) use ($roles) {
            $user = $request->getAttribute('user');

            if ($user === null) {
                throw new UnauthorizedException('Authentication required');
            }

            if (!($user instanceof User)) {
                throw new UnauthorizedException('Invalid user');
            }

            if (!in_array($user->role, $roles, true)) {
                throw new UnauthorizedException('Insufficient permissions');
            }

            return $next($request);
        };
    }

    /**
     * CORS middleware
     */
    public static function cors(): callable
    {
        return function (Request $request, callable $next) {
            $response = $next($request);

            if ($response instanceof Response) {
                $response->withHeader('Access-Control-Allow-Origin', '*')
                    ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                    ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            }

            return $response;
        };
    }

    /**
     * JSON content type middleware
     */
    public static function jsonOnly(): callable
    {
        return function (Request $request, callable $next) {
            if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
                if (!$request->isJson()) {
                    return Response::badRequest('Content-Type must be application/json');
                }
            }

            return $next($request);
        };
    }

    /**
     * Rate limiting middleware using IP address.
     *
     * @param int $maxAttempts Maximum requests per window (default: 60)
     * @param int $decaySeconds Time window in seconds (default: 60)
     */
    public static function throttle(int $maxAttempts = 60, int $decaySeconds = 60): callable
    {
        return self::throttleWithOverrides($maxAttempts, $decaySeconds);
    }

    /**
     * Rate limiting with per-path overrides.
     *
     * @param int $maxAttempts Maximum requests per window (default: 60)
     * @param int $decaySeconds Time window in seconds (default: 60)
     * @param array<string, array{max?: int, decay?: int}> $overrides
     */
    public static function throttleWithOverrides(
        int $maxAttempts = 60,
        int $decaySeconds = 60,
        array $overrides = []
    ): callable {
        return function (Request $request, callable $next) use ($maxAttempts, $decaySeconds, $overrides) {
            $path = $request->path();
            $effectiveMax = $maxAttempts;
            $effectiveDecay = $decaySeconds;

            foreach ($overrides as $pattern => $override) {
                if (self::matchesRateLimitPath($path, $pattern)) {
                    $effectiveMax = $override['max'] ?? $effectiveMax;
                    $effectiveDecay = $override['decay'] ?? $effectiveDecay;
                    break;
                }
            }

            $limiter = self::getRateLimiter()->withLimits($effectiveMax, $effectiveDecay);
            $key = self::resolveRateLimitKey($request);

            if ($limiter->tooManyAttempts($key)) {
                $retryAfter = $limiter->availableIn($key);
                return Response::json([
                    'error' => 'Too many requests',
                    'message' => 'Rate limit exceeded. Please try again later.',
                    'retry_after' => $retryAfter,
                ], 429)
                    ->withHeader('Retry-After', (string) $retryAfter)
                    ->withHeader('X-RateLimit-Limit', (string) $effectiveMax)
                    ->withHeader('X-RateLimit-Remaining', '0')
                    ->withHeader('X-RateLimit-Reset', (string) (time() + $retryAfter));
            }

            $hits = $limiter->hit($key);
            $remaining = max(0, $effectiveMax - $hits);

            $response = $next($request);

            if ($response instanceof Response) {
                $response->withHeader('X-RateLimit-Limit', (string) $effectiveMax)
                    ->withHeader('X-RateLimit-Remaining', (string) $remaining)
                    ->withHeader('X-RateLimit-Reset', (string) (time() + $effectiveDecay));
            }

            return $response;
        };
    }

    /**
     * Strict rate limiting for sensitive endpoints (e.g., login, password reset).
     *
     * @param int $maxAttempts Maximum requests per window (default: 5)
     * @param int $decaySeconds Time window in seconds (default: 60)
     */
    public static function throttleStrict(int $maxAttempts = 5, int $decaySeconds = 60): callable
    {
        return self::throttle($maxAttempts, $decaySeconds);
    }

    /**
     * Rate limiting by authenticated user instead of IP.
     *
     * @param int $maxAttempts Maximum requests per window (default: 100)
     * @param int $decaySeconds Time window in seconds (default: 60)
     */
    public static function throttleByUser(int $maxAttempts = 100, int $decaySeconds = 60): callable
    {
        return function (Request $request, callable $next) use ($maxAttempts, $decaySeconds) {
            $limiter = self::getRateLimiter()->withLimits($maxAttempts, $decaySeconds);

            $user = $request->getAttribute('user');
            $key = $user instanceof User
                ? 'user:' . $user->id
                : 'ip:' . self::getClientIp($request);

            if ($limiter->tooManyAttempts($key)) {
                $retryAfter = $limiter->availableIn($key);
                return Response::json([
                    'error' => 'Too many requests',
                    'message' => 'Rate limit exceeded. Please try again later.',
                    'retry_after' => $retryAfter,
                ], 429)
                    ->withHeader('Retry-After', (string) $retryAfter)
                    ->withHeader('X-RateLimit-Limit', (string) $maxAttempts)
                    ->withHeader('X-RateLimit-Remaining', '0')
                    ->withHeader('X-RateLimit-Reset', (string) (time() + $retryAfter));
            }

            $hits = $limiter->hit($key);
            $remaining = max(0, $maxAttempts - $hits);

            $response = $next($request);

            if ($response instanceof Response) {
                $response->withHeader('X-RateLimit-Limit', (string) $maxAttempts)
                    ->withHeader('X-RateLimit-Remaining', (string) $remaining)
                    ->withHeader('X-RateLimit-Reset', (string) (time() + $decaySeconds));
            }

            return $response;
        };
    }

    /**
     * Resolve the rate limit key for a request.
     */
    private static function resolveRateLimitKey(Request $request): string
    {
        return 'ip:' . self::getClientIp($request) . ':' . $request->path();
    }

    /**
     * Determine if the rate limit override pattern matches the request path.
     */
    private static function matchesRateLimitPath(string $path, string $pattern): bool
    {
        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');
            return str_starts_with($path, $prefix);
        }

        return $path === $pattern;
    }

    /**
     * Get the client IP address from the request.
     */
    private static function getClientIp(Request $request): string
    {
        // Check for forwarded IP (when behind proxy/load balancer)
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor !== null) {
            $ips = array_map('trim', explode(',', $forwardedFor));
            return $ips[0];
        }

        $realIp = $request->header('X-Real-IP');
        if ($realIp !== null) {
            return $realIp;
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Validate a JWT bearer token and return the user data.
     *
     * @param string $token The JWT token to validate
     * @return User|null The authenticated user or null if invalid
     */
    private static function validateToken(string $token): ?User
    {
        try {
            $jwtService = self::getJwtService();
            return $jwtService->validateToken($token);
        } catch (\Throwable $e) {
            // Log validation errors but don't expose details
            error_log('JWT validation error: ' . $e->getMessage());
            return null;
        }
    }
}
