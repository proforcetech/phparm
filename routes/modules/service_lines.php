<?php

use App\Services\ServiceLine\ServiceLineController;
use App\Services\ServiceLine\ServiceLineRepository;
use App\Services\ServiceLine\ServiceLineService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Service lines (trade taxonomy) — Phase 11 of docs/woms-expansion-plan.md.
 *
 * Read endpoints are authenticated-only so every module's UI can fetch the
 * list for sidebar pickers. Write endpoints require
 * settings.service_lines.manage, which at the role level is admin-only.
 *
 * Patterned after routes/modules/divisions.php — keep the two in sync.
 * Response envelope matches divisions: { data: <controller payload> }.
 */
return function (Router $router, RouteContext $ctx): void {
    $repository = new ServiceLineRepository($ctx->connection);
    $service = new ServiceLineService($repository, $ctx->connection);
    $controller = new ServiceLineController($service, $repository, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->get('/api/service-lines', function (Request $request) use ($controller) {
            $includeInactive = (bool) $request->queryParam('include_inactive', false);
            return Response::json([
                'data' => $controller->index(
                    $request->getAttribute('user'),
                    $includeInactive
                ),
            ]);
        });

        $router->get('/api/service-lines/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/service-lines', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->store(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/service-lines/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->get('/api/me/service-lines', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->mine(
                    $request->getAttribute('user')
                ),
            ]);
        });

        $router->put('/api/me/service-lines/primary', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->setPrimary(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        // Admin endpoints — manage another user's service-line memberships.
        // All gated on settings.service_lines.manage at the controller level.
        // Replaces the SQL-only ops gap documented in
        // docs/woms/phase-11-service-lines.md §6.3.
        $router->get('/api/users/{id}/service-lines', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->showForUser(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/users/{id}/service-lines', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->addMembership(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->delete(
            '/api/users/{id}/service-lines/{serviceLineId}',
            function (Request $request) use ($controller) {
                return Response::json([
                    'data' => $controller->removeMembership(
                        $request->getAttribute('user'),
                        (int) $request->getAttribute('id'),
                        (int) $request->getAttribute('serviceLineId')
                    ),
                ]);
            }
        );

        $router->put(
            '/api/users/{id}/service-lines/primary',
            function (Request $request) use ($controller) {
                return Response::json([
                    'data' => $controller->setPrimaryForUser(
                        $request->getAttribute('user'),
                        (int) $request->getAttribute('id'),
                        $request->body()
                    ),
                ]);
            }
        );
    });
};
