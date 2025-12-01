<?php

namespace App\Support\Http;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

class Middleware
{
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
                $request->setAttribute('user', new User($user));
            } else {
                $request->setAttribute('user', $user);
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
     * Simple token validation (for demonstration)
     * In production, use JWT or database-backed tokens
     */
    private static function validateToken(string $token): ?array
    {
        // This is a placeholder - implement proper token validation
        // For now, just return null to indicate invalid token
        return null;
    }
}
