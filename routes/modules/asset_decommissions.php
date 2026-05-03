<?php

use App\Services\Assets\AssetDecommissionController;
use App\Services\Assets\AssetDecommissionRepository;
use App\Services\Assets\AssetDecommissionService;
use App\Services\Assets\SiteAssetRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Asset decommission workflow endpoints — Phase 13 (M5) of
 * docs/woms-expansion-plan.md.
 *
 * Read perm:    asset_decommissions.view
 * Write perm:   asset_decommissions.manage  (most transitions)
 * Retire perm:  asset_decommissions.retire  (terminal step — admin only,
 *                                            because it ALSO retires the
 *                                            underlying site_asset)
 *
 * Response envelope mirrors the rest of Phase 12/13:  { data: <payload> }
 *
 * Transition endpoints all return the updated decommission. Audit history
 * (entity_type='asset_decommission', event='decommission.transitioned') is
 * the source of truth for the timeline view.
 */
return function (Router $router, RouteContext $ctx): void {
    $repository = new AssetDecommissionRepository($ctx->connection);
    $siteAssets = new SiteAssetRepository($ctx->connection);
    $service = new AssetDecommissionService($repository, $siteAssets, $ctx->gate, $ctx->auditLogger);
    $controller = new AssetDecommissionController($service, $repository, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // ---- read ----
        $router->get('/api/asset-decommissions', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'site_asset_id' => $request->queryParam('site_asset_id'),
                'status' => $request->queryParam('status'),
                'requires_wipe' => $request->queryParam('requires_wipe'),
                'recovery_method' => $request->queryParam('recovery_method'),
                'requested_by_user_id' => $request->queryParam('requested_by_user_id'),
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

        $router->get('/api/asset-decommissions/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                ),
            ]);
        });

        // ---- write ----
        $router->post('/api/asset-decommissions', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->store(
                    $request->getAttribute('user'),
                    $request->body(),
                ),
            ]);
        });

        $router->put('/api/asset-decommissions/{id}', function (Request $request) use ($controller) {
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
        $router->post('/api/asset-decommissions/{id}/wipe/start', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->startWipe(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-decommissions/{id}/wipe/complete', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->completeWipe(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-decommissions/{id}/recovery/start', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->startRecovery(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-decommissions/{id}/recovery/complete', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->completeRecovery(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-decommissions/{id}/entitlements', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->updateEntitlements(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-decommissions/{id}/audit', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->markAudited(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-decommissions/{id}/retire', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->retire(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ),
            ]);
        });

        $router->post('/api/asset-decommissions/{id}/cancel', function (Request $request) use ($controller) {
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
