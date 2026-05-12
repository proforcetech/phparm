<?php

use App\Services\Division\DivisionController;
use App\Services\Division\DivisionRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Divisions (service lines) — Phase 0.2 of docs/expansion-plan.md.
 *
 * Read endpoints are authenticated-only so every module's UI can fetch the
 * list for pickers. Write endpoints require settings.divisions.manage, which
 * at the role level is admin-only until the policy refactor (Phase 0.3).
 */
return function (Router $router, RouteContext $ctx): void {
    $controller = new DivisionController(
        new DivisionRepository($ctx->connection),
        $ctx->gate
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->get('/api/divisions', function (Request $request) use ($controller) {
            $includeInactive = (bool) $request->queryParam('include_inactive', false);
            return Response::json([
                'data' => $controller->index($request->getAttribute('user'), $includeInactive),
            ]);
        });

        $router->get('/api/divisions/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/divisions', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->store($request->getAttribute('user'), $request->body()),
            ]);
        });

        $router->put('/api/divisions/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->delete('/api/divisions/{id}', function (Request $request) use ($controller) {
            $controller->destroy(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });
    });
};
