<?php

use App\Services\Auth\TrustedDeviceController;
use App\Services\Auth\TrustedDeviceRepository;
use App\Services\Auth\TrustedDeviceService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Trusted-device management endpoints.
 *
 * Self-service:
 *   GET    /api/users/me/trusted-devices            list my devices
 *   DELETE /api/users/me/trusted-devices            revoke ALL my devices
 *   DELETE /api/users/me/trusted-devices/{id}       revoke one
 *
 * Admin (trusted_devices.manage):
 *   GET    /api/users/{userId}/trusted-devices
 *   DELETE /api/users/{userId}/trusted-devices
 */
return function (Router $router, RouteContext $ctx): void {
    $repo = new TrustedDeviceRepository($ctx->connection);
    $service = new TrustedDeviceService($repo, $ctx->gate);
    $controller = new TrustedDeviceController($service, $repo, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // Self-service
        $router->get(
            '/api/users/me/trusted-devices',
            function (Request $request) use ($controller) {
                return Response::json($controller->listMine(
                    $request->getAttribute('user')
                ));
            }
        );

        $router->delete(
            '/api/users/me/trusted-devices',
            function (Request $request) use ($controller) {
                return Response::json($controller->revokeAllMine(
                    $request->getAttribute('user')
                ));
            }
        );

        $router->delete(
            '/api/users/me/trusted-devices/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->revoke(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        // Admin
        $router->get(
            '/api/users/{userId}/trusted-devices',
            function (Request $request) use ($controller) {
                return Response::json($controller->listForUser(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('userId')
                ));
            }
        );

        $router->delete(
            '/api/users/{userId}/trusted-devices',
            function (Request $request) use ($controller) {
                return Response::json($controller->revokeAllForUser(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('userId')
                ));
            }
        );
    });
};
