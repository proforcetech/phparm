<?php

use App\Services\ChainRollup\ChainRollupController;
use App\Services\ChainRollup\ChainRollupService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Multi-site chain rollup endpoints — Phase 17 / S4 of
 * docs/woms-expansion-plan.md.
 *
 * Read perm: chain_rollup.view
 *
 * Endpoints:
 *   GET /api/chain-rollup/chains                         — list active chains
 *   GET /api/chain-rollup/{companyId}?from=&to=          — rollup for one chain
 */
return function (Router $router, RouteContext $ctx): void {
    $service = new ChainRollupService($ctx->connection);
    $controller = new ChainRollupController($service, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {

        $router->get('/api/chain-rollup/chains', function (Request $request) use ($controller) {
            $search = $request->queryParam('search');
            return Response::json([
                'data' => $controller->listChains(
                    $request->getAttribute('user'),
                    $search !== null && $search !== '' ? (string) $search : null,
                ),
            ]);
        });

        $router->get('/api/chain-rollup/{companyId}', function (Request $request) use ($controller) {
            $from = (string) ($request->queryParam('from')
                ?: date('Y-m-d', strtotime('first day of -2 month')));
            $to = (string) ($request->queryParam('to') ?: date('Y-m-d'));
            return Response::json([
                'data' => $controller->rollup(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('companyId'),
                    $from,
                    $to,
                ),
            ]);
        });
    });
};
