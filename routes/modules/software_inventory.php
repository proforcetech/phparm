<?php

use App\Services\SoftwareInventory\InstalledSoftwareRepository;
use App\Services\SoftwareInventory\LicenseAssignmentRepository;
use App\Services\SoftwareInventory\LicenseSeatRepository;
use App\Services\SoftwareInventory\SoftwareAssetRepository;
use App\Services\SoftwareInventory\SoftwareInventoryController;
use App\Services\SoftwareInventory\SoftwareInventoryService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Software CMDB endpoints — Phase 14 / M9 of docs/woms-expansion-plan.md.
 *
 * Read perm: software_inventory.view
 * Write perm: software_inventory.manage
 *
 * Endpoint groups:
 *   /api/software-titles            — software_assets catalog (publisher/title/sku)
 *   /api/software-license-pools     — license_seats (per-customer license pools)
 *   /api/software-license-assignments — license_assignments (seat ↔ user/asset)
 *   /api/software-installs          — installed_software (machine ↔ title)
 *   /api/software-compliance        — over-allocation + unlicensed feed
 *   /api/software-reconcile         — admin counter-rebuild action
 *
 * Response envelope: { data: <controller payload> } — same as the rest of
 * the per-module routes.
 */
return function (Router $router, RouteContext $ctx): void {
    $softwareAssets = new SoftwareAssetRepository($ctx->connection);
    $seats = new LicenseSeatRepository($ctx->connection);
    $assignments = new LicenseAssignmentRepository($ctx->connection);
    $installs = new InstalledSoftwareRepository($ctx->connection);
    $service = new SoftwareInventoryService(
        $ctx->connection,
        $softwareAssets,
        $seats,
        $assignments,
        $installs,
        $ctx->auditLogger,
    );
    $controller = new SoftwareInventoryController(
        $softwareAssets,
        $seats,
        $assignments,
        $installs,
        $service,
        $ctx->gate,
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {

        // ---- software titles --------------------------------------------------
        $router->get('/api/software-titles', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'publisher' => $request->queryParam('publisher'),
                'title' => $request->queryParam('title'),
                'category' => $request->queryParam('category'),
                'platform' => $request->queryParam('platform'),
                'is_active' => $request->queryParam('is_active'),
                'search' => $request->queryParam('search'),
                'include_shared' => $request->queryParam('include_shared'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);

            return Response::json([
                'data' => $controller->indexTitles(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/software-titles/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->showTitle(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/software-titles', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->storeTitle(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/software-titles/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->updateTitle(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->delete('/api/software-titles/{id}', function (Request $request) use ($controller) {
            $controller->destroyTitle(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::json(['data' => ['deleted' => true]]);
        });

        // ---- license pools ----------------------------------------------------
        $router->get('/api/software-license-pools', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'software_asset_id' => $request->queryParam('software_asset_id'),
                'status' => $request->queryParam('status'),
                'license_type' => $request->queryParam('license_type'),
                'expires_before' => $request->queryParam('expires_before'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);

            return Response::json([
                'data' => $controller->indexPools(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/software-license-pools/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->showPool(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/software-license-pools', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->storePool(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/software-license-pools/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->updatePool(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->delete('/api/software-license-pools/{id}', function (Request $request) use ($controller) {
            $controller->destroyPool(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::json(['data' => ['deleted' => true]]);
        });

        // ---- license assignments ---------------------------------------------
        $router->get('/api/software-license-assignments', function (Request $request) use ($controller) {
            $filters = [
                'license_seat_id' => $request->queryParam('license_seat_id'),
                'assignee_user_id' => $request->queryParam('assignee_user_id'),
                'assignee_site_asset_id' => $request->queryParam('assignee_site_asset_id'),
                'assignee_type' => $request->queryParam('assignee_type'),
                'active_only' => $request->queryParam('active_only'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);

            return Response::json([
                'data' => $controller->indexAssignments(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->post('/api/software-license-assignments', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->assign(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->post('/api/software-license-assignments/{id}/unassign', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->unassign(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        // ---- installed software ----------------------------------------------
        $router->get('/api/software-installs', function (Request $request) use ($controller) {
            $filters = [
                'site_asset_id' => $request->queryParam('site_asset_id'),
                'software_asset_id' => $request->queryParam('software_asset_id'),
                'source' => $request->queryParam('source'),
                'unlicensed_only' => $request->queryParam('unlicensed_only'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);

            return Response::json([
                'data' => $controller->indexInstalls(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->post('/api/software-installs', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->recordInstall(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->delete('/api/software-installs/{id}', function (Request $request) use ($controller) {
            $controller->removeInstall(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::json(['data' => ['deleted' => true]]);
        });

        $router->post('/api/software-installs/{id}/link', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->linkInstall(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        // ---- compliance & reconciliation -------------------------------------
        $router->get('/api/software-compliance', function (Request $request) use ($controller) {
            $customerId = $request->queryParam('customer_id');
            return Response::json([
                'data' => $controller->compliance(
                    $request->getAttribute('user'),
                    $customerId === null || $customerId === '' ? null : (int) $customerId
                ),
            ]);
        });

        $router->post('/api/software-reconcile', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->reconcile($request->getAttribute('user')),
            ]);
        });
    });
};
