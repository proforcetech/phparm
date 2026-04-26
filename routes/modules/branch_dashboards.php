<?php

use App\Services\Dashboard\BranchDashboardController;
use App\Services\Dashboard\BranchDashboardService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Branch/region dashboards bootstrap — Phase 0.6 of docs/expansion-plan.md.
 *
 * Surfaces per-division KPIs so each service line can see its own book of
 * business. Writes not applicable — read-only aggregation.
 */
return function (Router $router, RouteContext $ctx): void {
    $controller = new BranchDashboardController(
        new BranchDashboardService($ctx->connection),
        $ctx->gate
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->get('/api/branches/dashboards/overview', function (Request $request) use ($controller) {
            return Response::json($controller->overview($request->getAttribute('user')));
        });

        $router->get('/api/branches/{id}/dashboard', function (Request $request) use ($controller) {
            return Response::json($controller->forDivision(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });
    });
};
