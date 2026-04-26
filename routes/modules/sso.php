<?php

use App\Services\Sso\OidcHttpClient;
use App\Services\Sso\SsoController;
use App\Services\Sso\SsoLoginAttemptRepository;
use App\Services\Sso\SsoProviderRepository;
use App\Services\Sso\SsoService;
use App\Services\Sso\SsoUserLinkRepository;
use App\Services\User\UserRepository;
use App\Support\Auth\JwtService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * SSO (OIDC) endpoints.
 *
 * Public (no auth):
 *   GET  /api/auth/sso/providers           list active providers (login screen)
 *   POST /api/auth/sso/{slug}/start        start the OIDC dance, get authorize URL
 *   GET  /api/auth/sso/callback            handle ?state=&code= redirect-back
 *
 * User-auth:
 *   GET    /api/users/me/sso-links         list current user's links
 *   DELETE /api/users/me/sso-links/{id}    unlink one
 *
 * Admin-auth (sso_providers.view / .manage):
 *   GET    /api/admin/sso/providers
 *   GET    /api/admin/sso/providers/{id}
 *   POST   /api/admin/sso/providers
 *   PUT    /api/admin/sso/providers/{id}
 *   DELETE /api/admin/sso/providers/{id}
 */
return function (Router $router, RouteContext $ctx): void {
    $authConfig = $ctx->config['auth'] ?? [];
    $jwtConfig = $authConfig['jwt'] ?? [];
    $jwtService = new JwtService(
        $ctx->connection,
        $jwtConfig['secret'] ?? 'default-secret-key-change-in-production',
        $jwtConfig['ttl'] ?? 3600,
        $jwtConfig['refresh_ttl'] ?? 604800,
    );

    $providerRepo = new SsoProviderRepository($ctx->connection);
    $linkRepo = new SsoUserLinkRepository($ctx->connection);
    $attemptRepo = new SsoLoginAttemptRepository($ctx->connection);
    $userRepo = new UserRepository($ctx->connection);
    $http = new OidcHttpClient();
    $service = new SsoService($providerRepo, $linkRepo, $attemptRepo, $userRepo, $http);
    $controller = new SsoController($service, $providerRepo, $linkRepo, $jwtService, $ctx->gate);

    // --- Public endpoints ---
    $router->get(
        '/api/auth/sso/providers',
        function () use ($controller) {
            return Response::json($controller->listActiveProviders());
        }
    );

    $router->post(
        '/api/auth/sso/{slug}/start',
        function (Request $request) use ($controller) {
            return Response::json($controller->start(
                (string) $request->getAttribute('slug'),
                $request->body()
            ));
        }
    );

    $router->get(
        '/api/auth/sso/callback',
        function (Request $request) use ($controller) {
            return Response::json($controller->callback($request->query()));
        }
    );

    // --- Authenticated user endpoints ---
    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->get(
            '/api/users/me/sso-links',
            function (Request $request) use ($controller) {
                return Response::json($controller->listMyLinks(
                    $request->getAttribute('user')
                ));
            }
        );

        $router->delete(
            '/api/users/me/sso-links/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->unlinkMyLink(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        // --- Admin provider management ---
        $router->get(
            '/api/admin/sso/providers',
            function (Request $request) use ($controller) {
                return Response::json($controller->adminListProviders(
                    $request->getAttribute('user')
                ));
            }
        );

        $router->get(
            '/api/admin/sso/providers/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->adminGetProvider(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/admin/sso/providers',
            function (Request $request) use ($controller) {
                return Response::created($controller->adminCreateProvider(
                    $request->getAttribute('user'),
                    $request->body()
                ));
            }
        );

        $router->put(
            '/api/admin/sso/providers/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->adminUpdateProvider(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/admin/sso/providers/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->adminDeleteProvider(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );
    });
};
