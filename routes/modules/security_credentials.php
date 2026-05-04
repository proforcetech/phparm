<?php

use App\Services\Assets\SiteAssetRepository;
use App\Services\Security\AccessScheduleRepository;
use App\Services\Security\CredentialDoorRepository;
use App\Services\Security\CredentialRegisterController;
use App\Services\Security\CredentialRegisterRepository;
use App\Services\Security\CredentialRegisterService;
use App\Services\Security\ProgrammingLogRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Security credential register endpoints — Phase 16 / S1 of
 * docs/woms-expansion-plan.md.
 *
 * Read perm:  security_credentials.view
 * Write perm: security_credentials.manage
 *
 * Endpoint groups:
 *   /api/security/credentials                              — list + create
 *   /api/security/credentials/{id}                         — show + update + delete
 *   /api/security/credentials/{id}/status                  — suspend / revoke / reactivate
 *   /api/security/credentials/{id}/doors                   — list + grant doors
 *   /api/security/credential-doors/{id}                    — update assignment
 *   /api/security/credential-doors/{id}/revoke             — revoke assignment
 *   /api/security/access-schedules                         — list + create
 *   /api/security/access-schedules/{id}                    — show + update + delete
 *   /api/security/programming-logs                         — customer-wide audit feed
 */
return function (Router $router, RouteContext $ctx): void {
    $credentialRepo = new CredentialRegisterRepository($ctx->connection);
    $doorRepo = new CredentialDoorRepository($ctx->connection);
    $scheduleRepo = new AccessScheduleRepository($ctx->connection);
    $logRepo = new ProgrammingLogRepository($ctx->connection);
    $siteAssets = new SiteAssetRepository($ctx->connection);
    $service = new CredentialRegisterService(
        $ctx->connection,
        $credentialRepo,
        $doorRepo,
        $scheduleRepo,
        $logRepo,
        $siteAssets,
        $ctx->auditLogger,
    );
    $controller = new CredentialRegisterController(
        $credentialRepo,
        $doorRepo,
        $scheduleRepo,
        $logRepo,
        $service,
        $ctx->gate,
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {

        // ---- credentials --------------------------------------------------
        $router->get('/api/security/credentials', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'site_id' => $request->queryParam('site_id'),
                'status' => $request->queryParam('status'),
                'credential_type' => $request->queryParam('credential_type'),
                'search' => $request->queryParam('search'),
                'expires_before' => $request->queryParam('expires_before'),
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

        $router->get('/api/security/credentials/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/security/credentials', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->store(
                    $request->getAttribute('user'),
                    $request->body(),
                    $request->getClientIp()
                ),
            ]);
        });

        $router->put('/api/security/credentials/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                    $request->getClientIp()
                ),
            ]);
        });

        $router->post('/api/security/credentials/{id}/status', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->changeStatus(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                    $request->getClientIp()
                ),
            ]);
        });

        $router->delete('/api/security/credentials/{id}', function (Request $request) use ($controller) {
            $controller->destroy(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->getClientIp()
            );
            return Response::noContent();
        });

        // ---- doors --------------------------------------------------------
        $router->get('/api/security/credentials/{id}/doors', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->listDoors(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/security/credentials/{id}/doors', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->grantDoor(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                    $request->getClientIp()
                ),
            ]);
        });

        $router->put('/api/security/credential-doors/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->updateDoor(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                    $request->getClientIp()
                ),
            ]);
        });

        $router->post('/api/security/credential-doors/{id}/revoke', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->revokeDoor(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                    $request->getClientIp()
                ),
            ]);
        });

        // ---- access schedules ---------------------------------------------
        $router->get('/api/security/access-schedules', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'is_active' => $request->queryParam('is_active'),
                'search' => $request->queryParam('search'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 100);
            $clean = [];
            foreach ($filters as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $clean[$k] = $k === 'is_active' ? (bool) $v : $v;
            }

            return Response::json([
                'data' => $controller->listSchedules(
                    $request->getAttribute('user'),
                    $clean,
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/security/access-schedules/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->showSchedule(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/security/access-schedules', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->storeSchedule(
                    $request->getAttribute('user'),
                    $request->body(),
                    $request->getClientIp()
                ),
            ]);
        });

        $router->put('/api/security/access-schedules/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->updateSchedule(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                    $request->getClientIp()
                ),
            ]);
        });

        $router->delete('/api/security/access-schedules/{id}', function (Request $request) use ($controller) {
            $controller->destroySchedule(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->getClientIp()
            );
            return Response::noContent();
        });

        // ---- programming-log audit feed -----------------------------------
        $router->get('/api/security/programming-logs', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'site_id' => $request->queryParam('site_id'),
                'target_type' => $request->queryParam('target_type'),
                'target_id' => $request->queryParam('target_id'),
                'action' => $request->queryParam('action'),
                'programmed_from' => $request->queryParam('programmed_from'),
                'programmed_to' => $request->queryParam('programmed_to'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 100);

            return Response::json([
                'data' => $controller->listLogs(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });
    });
};
