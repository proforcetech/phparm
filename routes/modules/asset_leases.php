<?php

use App\Services\Assets\AssetLeaseController;
use App\Services\Assets\AssetLeaseRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Asset lease endpoints — Phase 13 (M3) of docs/woms-expansion-plan.md.
 *
 * Read perm: asset_leases.view
 * Write perm: asset_leases.manage
 *
 * Response envelope mirrors the Phase 12 property_management module:
 *   { data: <controller payload> }
 */
return function (Router $router, RouteContext $ctx): void {
    $repository = new AssetLeaseRepository($ctx->connection);
    $controller = new AssetLeaseController($repository, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->get('/api/asset-leases', function (Request $request) use ($controller) {
            $filters = [
                'site_asset_id' => $request->queryParam('site_asset_id'),
                'customer_id' => $request->queryParam('customer_id'),
                'status' => $request->queryParam('status'),
                'expires_before' => $request->queryParam('expires_before'),
                'expires_after' => $request->queryParam('expires_after'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);

            return Response::json([
                'data' => $controller->index(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/asset-leases/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/asset-leases', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->store(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/asset-leases/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->post('/api/asset-leases/{id}/decision', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->decide(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->post('/api/asset-leases/{id}/terminate', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->terminate(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });
    });
};
