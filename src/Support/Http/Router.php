<?php

namespace App\Support\Http;

use App\Support\Auth\UnauthorizedException;
use Exception;
use InvalidArgumentException;
use Throwable;

class Router
{
    /**
     * @var array<Route>
     */
    private array $routes = [];

    /**
     * @var array<callable>
     */
    private array $globalMiddleware = [];

    /**
     * @var array<callable>
     */
    private array $groupMiddleware = [];

    /**
     * @var array<string, Route>
     */
    private array $namedRoutes = [];

    /**
     * @param callable $handler
     */
    public function get(string $pattern, callable $handler): Route
    {
        return $this->addRoute('GET', $pattern, $handler);
    }

    /**
     * @param callable $handler
     */
    public function post(string $pattern, callable $handler): Route
    {
        return $this->addRoute('POST', $pattern, $handler);
    }

    /**
     * @param callable $handler
     */
    public function put(string $pattern, callable $handler): Route
    {
        return $this->addRoute('PUT', $pattern, $handler);
    }

    /**
     * @param callable $handler
     */
    public function patch(string $pattern, callable $handler): Route
    {
        return $this->addRoute('PATCH', $pattern, $handler);
    }

    /**
     * @param callable $handler
     */
    public function delete(string $pattern, callable $handler): Route
    {
        return $this->addRoute('DELETE', $pattern, $handler);
    }

    /**
     * @param callable $handler
     */
    private function addRoute(string $method, string $pattern, callable $handler): Route
    {
        $route = new Route($method, $pattern, $handler);

        // Apply active group middleware to the new route
        foreach ($this->groupMiddleware as $middleware) {
            $route->middleware($middleware);
        }

        $this->routes[] = $route;
        return $route;
    }

    /**
     * @param callable $middleware
     */
    public function middleware(callable $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    /**
     * @param callable $callback
     * @param array<callable> $middleware
     */
    public function group(array $middleware, callable $callback): void
    {
        // Save current group middleware state to handle nesting
        $previousGroupMiddleware = $this->groupMiddleware;
        
        // Merge new middleware into the group stack
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);

        $callback($this);

        // Restore previous state
        $this->groupMiddleware = $previousGroupMiddleware;
    }

    public function dispatch(Request $request): Response
    {
        try {
            $route = $this->findRoute($request->method(), $request->path());

            if ($route === null) {
                return Response::notFound('Route not found');
            }

            // Set route parameters as request attributes
            foreach ($route->getMatches() as $key => $value) {
                $request->setAttribute($key, $value);
            }

            // Build middleware stack (global + route-specific)
            // Note: Route-specific now includes the group middleware attached in addRoute
            $middleware = array_merge($this->globalMiddleware, $route->getMiddleware());

            // Execute middleware chain
            $handler = $this->buildMiddlewareStack($middleware, $route->getHandler());

            $response = $handler($request);

            // Ensure we always return a Response object
            if (!$response instanceof Response) {
                if (is_array($response) || is_object($response)) {
                    return Response::json($response);
                }
                return Response::text((string) $response);
            }

            return $response;
        } catch (UnauthorizedException $e) {
            return Response::forbidden($e->getMessage());
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        } catch (Throwable $e) {
            error_log("Router error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return Response::serverError('An error occurred: ' . $e->getMessage());
        }
    }

    private function findRoute(string $method, string $path): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->matches($method, $path)) {
                return $route;
            }
        }
        return null;
    }

    /**
     * @param array<callable> $middleware
     * @param callable $handler
     * @return callable
     */
    private function buildMiddlewareStack(array $middleware, callable $handler): callable
    {
        // Build middleware stack from inside out
        $next = $handler;

        foreach (array_reverse($middleware) as $mw) {
            $next = function (Request $request) use ($mw, $next) {
                return $mw($request, $next);
            };
        }

        return $next;
    }

    /**
     * @return array<Route>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
