<?php

use App\Services\TradeKpis\TradeKpiController;
use App\Services\TradeKpis\TradeKpiService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Trade-specific KPI dashboard endpoints — Phase 17 / S10 of
 * docs/woms-expansion-plan.md.
 *
 * Read perm: trade_kpis.view
 *
 * Endpoints:
 *   GET /api/trade-kpis/service-lines                             — service-line picker
 *   GET /api/trade-kpis/{serviceLineId}?from=&to=                 — KPI bundle
 */
return function (Router $router, RouteContext $ctx): void {
    $service = new TradeKpiService($ctx->connection);
    $controller = new TradeKpiController($service, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {

        $router->get('/api/trade-kpis/service-lines', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->listServiceLines($request->getAttribute('user')),
            ]);
        });

        $router->get('/api/trade-kpis/{serviceLineId}', function (Request $request) use ($controller) {
            $from = (string) ($request->queryParam('from')
                ?: date('Y-m-d', strtotime('first day of -2 month')));
            $to = (string) ($request->queryParam('to') ?: date('Y-m-d'));
            return Response::json([
                'data' => $controller->bundle(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('serviceLineId'),
                    $from,
                    $to,
                ),
            ]);
        });
    });
};
