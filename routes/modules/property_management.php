<?php

use App\Services\PropertyManagement\TenantBillingResolver;
use App\Services\PropertyManagement\TenantController;
use App\Services\PropertyManagement\TenantLeaseController;
use App\Services\PropertyManagement\TenantLeaseRepository;
use App\Services\PropertyManagement\TenantMaintenanceRequestController;
use App\Services\PropertyManagement\TenantMaintenanceRequestRepository;
use App\Services\PropertyManagement\TenantRepository;
use App\Services\PropertyManagement\UnitController;
use App\Services\PropertyManagement\UnitRepository;
use App\Services\Workorder\WorkorderRepository;
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
    $maintenanceRequestRepository = new TenantMaintenanceRequestRepository($ctx->connection);
    $billingResolver = new TenantBillingResolver($leaseRepository);
    $workorderRepository = new WorkorderRepository($ctx->connection, $ctx->auditLogger);

    $unitController = new UnitController($unitRepository, $ctx->gate);
    $tenantController = new TenantController($tenantRepository, $ctx->gate);
    $leaseController = new TenantLeaseController($leaseRepository, $ctx->gate);
    $maintenanceRequestController = new TenantMaintenanceRequestController(
        $maintenanceRequestRepository,
        $tenantRepository,
        $leaseRepository,
        $unitRepository,
        $billingResolver,
        $workorderRepository,
        $ctx->connection,
        $ctx->gate,
    );

    $router->group([Middleware::auth()], function (Router $router) use (
        $unitController,
        $tenantController,
        $leaseController,
        $maintenanceRequestController
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

        // -------------------- tenant portal: maintenance requests --------------------
        // These endpoints authenticate via the standard JWT but resolve the
        // user's tenant identity via Tenant.portal_user_id. No role gate is
        // required — being a linked tenant is the gate.

        $router->get('/api/tenant/me', function (Request $request) use ($maintenanceRequestController) {
            return Response::json([
                'data' => $maintenanceRequestController->me(
                    $request->getAttribute('user')
                ),
            ]);
        });

        $router->get('/api/tenant/maintenance-requests', function (Request $request) use ($maintenanceRequestController) {
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);
            return Response::json([
                'data' => $maintenanceRequestController->listMine(
                    $request->getAttribute('user'),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->post('/api/tenant/maintenance-requests', function (Request $request) use ($maintenanceRequestController) {
            return Response::created([
                'data' => $maintenanceRequestController->create(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->post('/api/tenant/maintenance-requests/{id}/cancel', function (Request $request) use ($maintenanceRequestController) {
            return Response::json([
                'data' => $maintenanceRequestController->cancelMine(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        // -------------------- staff queue: maintenance requests --------------------
        // Gated on property.units.view (read) / property.units.manage (write).

        $router->get('/api/maintenance-requests', function (Request $request) use ($maintenanceRequestController) {
            $filters = [
                'status' => $request->queryParam('status'),
                'unit_id' => $request->queryParam('unit_id'),
                'tenant_id' => $request->queryParam('tenant_id'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);
            return Response::json([
                'data' => $maintenanceRequestController->staffList(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->post('/api/maintenance-requests/{id}/triage', function (Request $request) use ($maintenanceRequestController) {
            return Response::json([
                'data' => $maintenanceRequestController->triage(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/maintenance-requests/{id}/decline', function (Request $request) use ($maintenanceRequestController) {
            return Response::json([
                'data' => $maintenanceRequestController->decline(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->post('/api/maintenance-requests/{id}/convert-to-workorder', function (Request $request) use ($maintenanceRequestController) {
            return Response::json([
                'data' => $maintenanceRequestController->convertToWorkorder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });
    });
};
