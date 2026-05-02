<?php

use App\Services\Assets\AssetAcquisitionController;
use App\Services\Assets\AssetAcquisitionRepository;
use App\Services\Assets\AssetAcquisitionService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Asset acquisition workflow endpoints — Phase 13 (M4) of
 * docs/woms-expansion-plan.md.
 *
 * Read perm:     asset_acquisitions.view
 * Write perm:    asset_acquisitions.manage   (most transitions)
 * Activate perm: asset_acquisitions.activate (final go-live, separated so
 *                                             admins control CMDB linkage)
 *
 * Response envelope mirrors the rest of Phase 12/13:  { data: <payload> }
 *
 * Transition endpoints all return the updated acquisition. Audit history
 * (entity_type='asset_acquisition', event='acquisition.transitioned') is
 * the source of truth for the timeline view.
 */
return function (Router $router, RouteContext $ctx): void {
    $repository = new AssetAcquisitionRepository($ctx->connection);
    $service = new AssetAcquisitionService($repository, $ctx->gate, $ctx->auditLogger);
    $controller = new AssetAcquisitionController($service, $repository, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // ---- read ----
        $router->get('/api/asset-acquisitions', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'site_id' => $request->queryParam('site_id'),
                'service_line_id' => $request->queryParam('service_line_id'),
                'asset_type_id' => $request->queryParam('asset_type_id'),
                'status' => $request->queryParam('status'),
                'estimate_id' => $request->queryParam('estimate_id'),
                'install_workorder_id' => $request->queryParam('install_workorder_id'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);

            return Response::json([
                'data' => $controller->index(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage,
                ),
            ]);
        });

        $router->get('/api/asset-acquisitions/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                ),
            ]);
        });

        // ---- write ----
        $router->post('/api/asset-acquisitions', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->store(
                    $request->getAttribute('user'),
                    $request->body(),
                ),
            ]);
        });

        $router->put('/api/asset-acquisitions/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        // ---- transitions ----
        // Each step is a separate endpoint so the UI gets clear affordances
        // and audit events are scoped tightly. No catch-all PATCH /status.
        $router->post('/api/asset-acquisitions/{id}/quote', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->attachQuote(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-acquisitions/{id}/approve', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->approve(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-acquisitions/{id}/reject', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->reject(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-acquisitions/{id}/po', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->issuePo(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-acquisitions/{id}/receive', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->markReceived(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-acquisitions/{id}/schedule-install', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->scheduleInstall(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-acquisitions/{id}/install', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->markInstalled(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-acquisitions/{id}/activate', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->activate(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-acquisitions/{id}/cancel', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->cancel(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });
    });
};
