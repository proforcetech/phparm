<?php

namespace App\Support\Http;

use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\CsrfTokenService;
use App\Support\Auth\ImpersonationService;
use App\Support\Auth\JwtService;
use App\Support\Auth\ModuleAccessService;
use App\Support\Auth\PasswordExpirationPolicy;
use App\Support\Auth\RolePermissions;
use App\Support\Auth\SecureSessionService;
use App\Support\Auth\UnauthorizedException;
use App\Support\Auth\UserSessionManager;

class Middleware
{
    private static ?RateLimiter $rateLimiter = null;
    private static ?JwtService $jwtService = null;
    private static ?ModuleAccessService $moduleService = null;
    private static ?UserSessionManager $sessionManager = null;
    private static ?Connection $activityConnection = null;
    private static ?ImpersonationService $impersonationService = null;
    private static ?SecureSessionService $secureSessionService = null;
    private static ?CsrfTokenService $csrfTokenService = null;
    private static ?PasswordExpirationPolicy $passwordExpirationPolicy = null;

    /**
     * Endpoints that remain reachable while a user is in a forced-rotation
     * state. They're the minimum surface needed to: read who you are, log
     * out, refresh the JWT, get a CSRF token, and actually change the
     * password. Everything else returns 403 password_change_required.
     */
    private const PASSWORD_ROTATION_ALLOWLIST = [
        '/api/auth/me',
        '/api/auth/logout',
        '/api/auth/refresh',
        '/api/auth/csrf-token',
        '/api/users/me',
    ];

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
            $rolePermissions = RolePermissions::fromDatabase($connection, $config['roles'] ?? []);
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
     * Get or create the impersonation service instance.
     */
    private static function getImpersonationService(): ImpersonationService
    {
        if (self::$impersonationService === null) {
            $dbConfigPath = dirname(__DIR__, 3) . '/config/database.php';
            $dbConfig = file_exists($dbConfigPath) ? require $dbConfigPath : [];
            $connection = new Connection($dbConfig);

            self::$impersonationService = new ImpersonationService($connection);
        }
        return self::$impersonationService;
    }

    /**
     * Set a custom impersonation service instance (for testing).
     */
    public static function setImpersonationService(ImpersonationService $service): void
    {
        self::$impersonationService = $service;
    }

    /**
     * Get or create the secure session service instance.
     */
    private static function getSecureSessionService(): SecureSessionService
    {
        if (self::$secureSessionService === null) {
            self::$secureSessionService = new SecureSessionService();
        }
        return self::$secureSessionService;
    }

    /**
     * Get or create the CSRF token service instance.
     */
    private static function getCsrfTokenService(): CsrfTokenService
    {
        if (self::$csrfTokenService === null) {
            self::$csrfTokenService = new CsrfTokenService();
        }
        return self::$csrfTokenService;
    }

    /**
     * Lazy-load the password expiration policy from config/auth.php so the
     * auth middleware can short-circuit requests for users with an expired
     * or admin-flagged password.
     */
    private static function getPasswordExpirationPolicy(): PasswordExpirationPolicy
    {
        if (self::$passwordExpirationPolicy === null) {
            $configPath = dirname(__DIR__, 3) . '/config/auth.php';
            $config = file_exists($configPath) ? require $configPath : [];
            self::$passwordExpirationPolicy = PasswordExpirationPolicy::fromConfig($config);
        }
        return self::$passwordExpirationPolicy;
    }

    public static function setPasswordExpirationPolicy(PasswordExpirationPolicy $policy): void
    {
        self::$passwordExpirationPolicy = $policy;
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
     * Authenticate user from session, httpOnly cookie, or bearer token.
     *
     * Authentication priority:
     * 1. Session-based auth (for backwards compatibility)
     * 2. JWT from httpOnly cookie (preferred for security)
     * 3. Bearer token from Authorization header (API clients)
     *
     * ## Security Implications by Method
     *
     * **Session-based**: Requires CSRF protection on state-changing requests.
     * Used primarily for legacy compatibility and server-rendered pages.
     *
     * **httpOnly Cookie (Method 2)**: Most secure for browser clients. The cookie
     * cannot be accessed by JavaScript, protecting against XSS token theft.
     * Combined with SameSite=Strict, this also prevents CSRF attacks. This method
     * is preferred and checked before bearer tokens.
     *
     * **Bearer Token (Method 3)**: Necessary for mobile apps and API clients that
     * cannot use cookies. However, tokens must be stored somewhere accessible to
     * the client (localStorage, memory), making them vulnerable to XSS attacks.
     * This is an acceptable tradeoff for API clients that:
     * - Implement certificate pinning
     * - Use secure storage (Keychain/Keystore)
     * - Are not browser-based
     *
     * The authentication order ensures browser clients will preferentially use
     * the more secure cookie-based authentication.
     *
     * Also validates impersonation sessions against the database with IP checking
     * to detect potential session hijacking attempts.
     *
     * @see JwtService For detailed security model documentation
     */
    public static function auth(): callable
    {
        return function (Request $request, callable $next) {
            $secureSession = self::getSecureSessionService();
            $secureSession->start();

            // Validate session - but don't reject yet; JWT/Bearer auth may still succeed
            $sessionValid = $secureSession->validate();

            // Clean up expired 2FA challenges
            if ($sessionValid) {
                $secureSession->cleanExpiredTwoFactorChallenges();
            }

            $user = null;
            $isImpersonating = false;

            // Try session-based auth (only if session is valid)
            if ($sessionValid && isset($_SESSION['user_id'])) {
                $sessionManager = self::getSessionManager();
                $sessionId = session_id();
                $ipAddress = $request->getClientIp();
                $userAgent = $request->header('HTTP_USER_AGENT') ?? $request->header('USER_AGENT');

                if (!$sessionManager->ensureSessionActive((int) $_SESSION['user_id'], $sessionId, $ipAddress, $userAgent)) {
                    $secureSession->destroy();
                    throw new UnauthorizedException('Session has been revoked');
                }

                // Validate impersonation session if present
                if (isset($_SESSION['impersonation']['session_token'])) {
                    $impersonationService = self::getImpersonationService();
                    $impersonationData = $impersonationService->validateSession(
                        $_SESSION['impersonation']['session_token'],
                        $ipAddress // Pass IP for validation to prevent session hijacking
                    );

                    if ($impersonationData === null) {
                        // Impersonation session is invalid - save impersonator data before clearing
                        $impersonatorId = $_SESSION['impersonation']['impersonator_id'] ?? null;
                        $impersonatorData = $_SESSION['impersonation']['impersonator'] ?? null;

                        // Clear the invalid impersonation session
                        unset($_SESSION['impersonation']);

                        // Restore original user if we have impersonator data
                        if ($impersonatorId !== null && $impersonatorData !== null) {
                            $_SESSION['user_id'] = $impersonatorId;
                            $_SESSION['user'] = $impersonatorData;
                        }
                    } else {
                        $isImpersonating = true;
                    }
                }

                $user = $_SESSION['user'] ?? null;
            }

            // Try JWT from httpOnly cookie
            if ($user === null) {
                $jwtService = self::getJwtService();
                $cookieUser = $jwtService->validateTokenFromCookie();
                if ($cookieUser !== null) {
                    $user = $cookieUser;
                }
            }

            // Try bearer token auth (for API clients)
            if ($user === null) {
                $token = $request->bearerToken();
                if ($token !== null) {
                    $user = self::validateToken($token);
                    // Phase 6.1 of docs/expansion-plan.md: staff auth must
                    // reject tokens minted for the isolated portal scope.
                    // We decode the already-validated token so signature
                    // verification is not repeated.
                    if ($user !== null) {
                        $payload = self::getJwtService()->decodeWithoutValidation($token);
                        if (is_array($payload) && ($payload['scope'] ?? null) === 'portal') {
                            throw new UnauthorizedException(
                                'portal-scoped token cannot access staff routes'
                            );
                        }
                    }
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

            // Store impersonation status in request for access by handlers
            $request->setAttribute('is_impersonating', $isImpersonating);

            if ($userModel instanceof User) {
                self::recordUserActivity($userModel->id);

                // Force-rotation gate: block all API calls outside the
                // allowlist when this user must rotate their password,
                // either because aging tipped them over or an admin flipped
                // must_change_password. Customers and impersonated sessions
                // are exempt — customers don't manage their own password
                // through this surface, and impersonators shouldn't be able
                // to silently accept rotation on someone else's behalf.
                if (!$isImpersonating
                    && $userModel->role !== 'customer'
                    && self::getPasswordExpirationPolicy()->isRotationRequired($userModel)
                    && !in_array($request->path(), self::PASSWORD_ROTATION_ALLOWLIST, true)
                ) {
                    return Response::json([
                        'success' => false,
                        'error' => 'password_change_required',
                        'message' => 'Your password has expired. Please change it before continuing.',
                    ], 403);
                }
            }

            return $next($request);
        };
    }

    /**
     * Phase 6.1 of docs/expansion-plan.md: isolated portal authentication.
     *
     * Requires a bearer token with scope='portal' and an active
     * portal_accounts row that matches the token's company_id claim. On
     * success, attaches `user`, `portal_account`, and `portal_scope`
     * attributes to the request so downstream handlers can enforce site
     * scoping without re-parsing the JWT.
     *
     * This middleware deliberately does NOT fall back to session cookies
     * or staff-style auth — the portal is a separate security context and
     * mixing fallbacks would undermine the isolation.
     */
    public static function portalAuth(\App\Services\Portal\PortalAuthService $authService): callable
    {
        return function (Request $request, callable $next) use ($authService) {
            $token = $request->bearerToken();
            if ($token === null) {
                throw new UnauthorizedException('portal authentication required');
            }
            $jwtService = self::getJwtService();
            $result = $jwtService->validateTokenWithPayload($token);
            if ($result === null) {
                throw new UnauthorizedException('invalid or expired portal token');
            }
            $account = $authService->assertValidSession($result['user'], $result['payload']);

            $request->setAttribute('user', $result['user']);
            $request->setAttribute('portal_account', $account);
            $request->setAttribute('portal_scope', [
                'company_id' => $account->company_id,
                'site_ids' => $account->allowed_site_ids,
            ]);

            return $next($request);
        };
    }

    /**
     * Phase 2a — tenant host gate.
     *
     * Prevents cross-tenant access on white-label hosts. Resolves the request
     * Host via PortalThemeService::resolveByHost; if a tenant theme matches,
     * the authenticated portal_account.company_id MUST equal the resolved
     * theme.company_id, otherwise the request is rejected.
     *
     * Permissive when no theme matches the host — that path is the
     * unbranded/default portal (e.g. portal.example.com or localhost in
     * dev), where any company's portal user is allowed.
     *
     * Must be layered AFTER portalAuth so portal_account is on the request.
     */
    public static function portalTenantGate(\App\Services\Portal\PortalThemeService $themeService): callable
    {
        return function (Request $request, callable $next) use ($themeService) {
            $account = $request->getAttribute('portal_account');
            if (!($account instanceof \App\Models\PortalAccount)) {
                // portalAuth must have run first. Treat absence as a hard
                // fail rather than silently letting the request through —
                // misconfigured route groups should surface immediately.
                throw new UnauthorizedException('portal tenant gate requires portal authentication');
            }

            $host = $request->header('Host') ?? $request->header('HTTP_HOST');
            $resolved = $themeService->resolveByHost($host);
            if ($resolved === null) {
                // No tenant theme bound to this host — unbranded/default
                // surface, no cross-tenant claim to enforce.
                return $next($request);
            }
            $themeCompanyId = isset($resolved['company_id']) ? (int) $resolved['company_id'] : 0;
            if ($themeCompanyId === 0) {
                // Default payload returned by publicResolveOrDefault has
                // company_id null, but resolveByHost returns null for
                // unmatched hosts, so this branch only fires on a
                // misconfigured theme row. Refuse to gate without a target.
                throw new UnauthorizedException('portal tenant could not be determined');
            }
            if ($themeCompanyId !== $account->company_id) {
                throw new UnauthorizedException('portal account does not belong to this host');
            }

            $request->setAttribute('portal_host_company_id', $themeCompanyId);
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
     * Generate a CSRF token if one does not exist in the session.
     * The token is stored in $_SESSION['csrf_token'].
     *
     * @deprecated Use CsrfTokenService instead
     */
    public static function generateCsrfToken(): string
    {
        $csrfService = self::getCsrfTokenService();
        return $csrfService->getToken();
    }

    /**
     * Validate the CSRF token from the request header against the session token.
     * Uses timing-safe comparison to prevent timing attacks.
     *
     * @param string $token The token from the request header
     * @return bool True if the token is valid, false otherwise
     * @deprecated Use CsrfTokenService instead
     */
    public static function validateCsrfToken(string $token): bool
    {
        $csrfService = self::getCsrfTokenService();
        return $csrfService->validateToken($token);
    }

    /**
     * Get the CSRF token service for external use.
     *
     * @return CsrfTokenService
     */
    public static function csrfService(): CsrfTokenService
    {
        return self::getCsrfTokenService();
    }

    /**
     * Legacy validation method - kept for backwards compatibility.
     *
     * @param string $token The token from the request header
     * @return bool True if the token is valid, false otherwise
     */
    private static function validateCsrfTokenLegacy(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * CSRF protection middleware for state-changing requests.
     * Validates CSRF token for POST, PUT, PATCH, and DELETE requests.
     * Allows exemption of specific paths (e.g., webhooks, public endpoints).
     *
     * @param array<string> $exemptPaths Paths to exclude from CSRF validation (supports wildcard *)
     */
    public static function csrf(array $exemptPaths = []): callable
    {
        return function (Request $request, callable $next) use ($exemptPaths) {
            $method = strtoupper($request->method());

            // Only validate state-changing methods
            if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return $next($request);
            }

            // Check if path is exempt from CSRF validation
            $path = $request->path();
            foreach ($exemptPaths as $exemptPath) {
                if (self::matchesCsrfExemptPath($path, $exemptPath)) {
                    return $next($request);
                }
            }

            // Get CSRF token from request header
            $token = $request->header('X-CSRF-Token') ?? $request->header('HTTP_X_CSRF_TOKEN') ?? '';

            if (!self::validateCsrfToken($token)) {
                return Response::json([
                    'success' => false,
                    'error' => 'csrf_token_invalid',
                    'message' => 'Invalid CSRF token. Please refresh the page and try again.',
                ], 403);
            }

            return $next($request);
        };
    }

    /**
     * Check if a path matches a CSRF exempt pattern.
     *
     * @param string $path The request path
     * @param string $pattern The exempt pattern (supports trailing * wildcard)
     * @return bool True if the path matches the pattern
     */
    private static function matchesCsrfExemptPath(string $path, string $pattern): bool
    {
        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim($pattern, '*');
            return str_starts_with($path, $prefix);
        }

        return $path === $pattern;
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
        return $request->getClientIp() ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
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
