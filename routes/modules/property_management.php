<?php

use App\Services\PropertyManagement\TenantController;
use App\Services\PropertyManagement\TenantLeaseController;
use App\Services\PropertyManagement\TenantLeaseRepository;
use App\Services\PropertyManagement\TenantRepository;
use App\Services\PropertyManagement\UnitController;
use App\Services\PropertyManagement\UnitRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Property Management vertical (units / tenants / leases) — Phase 12 of
 * docs/woms-expansion-plan.md.
 *
 * Read endpoints require `property.<resource>.view`; write endpoints require
 * `property.<resource>.manage`. Permissions are enforced inside each
 * controller method (mirrors ServiceLineController), not at the route layer,
 * so a future tenant-portal slice can reuse the same controllers behind a
 * different gate without duplicating routes.
 *
 * Response envelope follows the divisions/service_lines convention:
 *   { data: <controller payload> }
 */
return function (Router $router, RouteContext $ctx): void {
    $unitRepository = new UnitRepository($ctx->connection);
    $tenantRepository = new TenantRepository($ctx->connection);
    $leaseRepository = new TenantLeaseRepository($ctx->connection);

    $unitController = new UnitController($unitRepository, $ctx->gate);
    $tenantController = new TenantController($tenantRepository, $ctx->gate);
    $leaseController = new TenantLeaseController($leaseRepository, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use (
        $unitController,
        $tenantController,
        $leaseController
    ) {
        // -------------------- units --------------------
        $router->get('/api/units', function (Request $request) use ($unitController) {
            $filters = [
                'site_id' => $request->queryParam('site_id'),
                'status' => $request->queryParam('status'),
                'unit_type' => $request->queryParam('unit_type'),
                'search' => $request->queryParam('search'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);

            return Response::json([
                'data' => $unitController->index(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/units/{id}', function (Request $request) use ($unitController) {
            return Response::json([
                'data' => $unitController->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/units', function (Request $request) use ($unitController) {
            return Response::created([
                'data' => $unitController->store(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/units/{id}', function (Request $request) use ($unitController) {
            return Response::json([
                'data' => $unitController->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->delete('/api/units/{id}', function (Request $request) use ($unitController) {
            return Response::json([
                'data' => $unitController->destroy(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        // -------------------- tenants --------------------
        $router->get('/api/tenants', function (Request $request) use ($tenantController) {
            $filters = [
                'status' => $request->queryParam('status'),
                'company_id' => $request->queryParam('company_id'),
                'search' => $request->queryParam('search'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);

            return Response::json([
                'data' => $tenantController->index(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/tenants/{id}', function (Request $request) use ($tenantController) {
            return Response::json([
                'data' => $tenantController->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/tenants', function (Request $request) use ($tenantController) {
            return Response::created([
                'data' => $tenantController->store(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/tenants/{id}', function (Request $request) use ($tenantController) {
            return Response::json([
                'data' => $tenantController->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        // -------------------- tenant leases --------------------
        $router->get('/api/tenant-leases', function (Request $request) use ($leaseController) {
            $filters = [
                'tenant_id' => $request->queryParam('tenant_id'),
                'unit_id' => $request->queryParam('unit_id'),
                'status' => $request->queryParam('status'),
                'active_on' => $request->queryParam('active_on'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);

            return Response::json([
                'data' => $leaseController->index(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/tenant-leases/{id}', function (Request $request) use ($leaseController) {
            return Response::json([
                'data' => $leaseController->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/tenant-leases', function (Request $request) use ($leaseController) {
            return Response::created([
                'data' => $leaseController->store(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/tenant-leases/{id}', function (Request $request) use ($leaseController) {
            return Response::json([
                'data' => $leaseController->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });
    });
};
