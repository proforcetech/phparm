<?php

use App\Services\Security\SecurityEventController;
use App\Services\Security\SecurityEventLogger;
use App\Services\Security\SecurityEventRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * SOC security event endpoints.
 *
 * Permissions:
 *   security_events.view    list/show events + summary
 *   security_events.manage  manually record events from admin tools
 */
return function (Router $router, RouteContext $ctx): void {
    $repo = new SecurityEventRepository($ctx->connection);
    $logger = new SecurityEventLogger($repo);
    $controller = new SecurityEventController($repo, $logger, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->get(
            '/api/security-events',
            function (Request $request) use ($controller) {
                return Response::json($controller->index(
                    $request->getAttribute('user'),
                    $request->query()
                ));
            }
        );

        $router->get(
            '/api/security-events/summary',
            function (Request $request) use ($controller) {
                return Response::json($controller->summary(
                    $request->getAttribute('user'),
                    $request->query()
                ));
            }
        );

        $router->get(
            '/api/security-events/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/security-events',
            function (Request $request) use ($controller) {
                return Response::created($controller->record(
                    $request->getAttribute('user'),
                    $request->body()
                ));
            }
        );
    });
};
