<?php

use App\Services\Subcontractor\SubcontractorController;
use App\Services\Subcontractor\SubcontractorPortalPasswordSetupRepository;
use App\Services\Subcontractor\SubcontractorPortalPasswordSetupService;
use App\Services\Subcontractor\SubcontractorPortalTokenRepository;
use App\Services\Subcontractor\SubcontractorRepository;
use App\Services\Subcontractor\SubcontractorService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationLogRepository;
use App\Support\Notifications\TemplateEngine;

/**
 * Subcontractor endpoints (Phase 10.1 of docs/expansion-plan.md).
 *
 * Read perm:  subcontractors.view
 * Write perm: subcontractors.manage
 *
 * Three resource families:
 *   /api/subcontractors              vendor master CRUD + division approvals
 *   /api/subcontractors/assignable   filtered picker for the WO routing UI
 *   /api/workorders/{id}/subcontractors
 *                                    per-WO assignments (creation lives here so
 *                                    the WO id is in the path, not the body)
 *   /api/subcontractor-assignments/* update / state-transition / delete by id
 */
return function (Router $router, RouteContext $ctx): void {
    $repo = new SubcontractorRepository($ctx->connection);
    $service = new SubcontractorService($repo, $ctx->gate);
    $controller = new SubcontractorController($service);
    $notificationsConfig = $ctx->config['notifications'] ?? (require __DIR__ . '/../../config/notifications.php');
    $setupService = new SubcontractorPortalPasswordSetupService(
        $repo,
        new SubcontractorPortalPasswordSetupRepository($ctx->connection),
        new SubcontractorPortalTokenRepository($ctx->connection),
        $ctx->gate,
        new NotificationDispatcher(
            $notificationsConfig,
            new TemplateEngine(),
            new NotificationLogRepository($ctx->connection),
            $ctx->auditLogger,
        ),
    );

    $baseUrlResolver = static function (Request $request): string {
        $configured = trim((string) env('APP_URL', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = $request->header('X-Forwarded-Proto') ?? 'https';
        $host = $request->header('Host') ?? 'localhost';
        return rtrim($scheme . '://' . $host, '/');
    };

    $shopNameResolver = static function () use ($ctx): string {
        return (string) $ctx->settingsRepository->get('shop.name', 'Auto Repair Shop');
    };

    $router->group([Middleware::auth()], function (Router $router) use (
        $controller,
        $service,
        $setupService,
        $baseUrlResolver,
        $shopNameResolver
    ) {
        // ── subcontractor master CRUD ──────────────────────────────────────
        $router->get('/api/subcontractors', function (Request $request) use ($controller) {
            $filters = [
                'status' => $request->queryParam('status'),
                'search' => $request->queryParam('search'),
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ];
            $division = $request->queryParam('division_id');
            if ($division !== null && $division !== '') {
                $filters['division_id'] = (int) $division;
            }
            return Response::json($controller->listSubcontractors(
                $request->getAttribute('user'),
                array_filter($filters, static fn($v) => $v !== null && $v !== '')
            ));
        });

        $router->get('/api/subcontractors/assignable', function (Request $request) use ($controller) {
            $division = $request->queryParam('division_id');
            return Response::json($controller->listAssignable(
                $request->getAttribute('user'),
                $division !== null && $division !== '' ? (int) $division : null
            ));
        });

        $router->post('/api/subcontractors', function (Request $request) use (
            $controller,
            $setupService,
            $baseUrlResolver,
            $shopNameResolver
        ) {
            $body = $request->body();
            $response = $controller->createSubcontractor(
                $request->getAttribute('user'),
                $body
            );
            $sub = $response['data'] ?? [];
            if (!empty($sub['portal_login_enabled']) && empty($sub['portal_password_set'])) {
                $response['portal_setup'] = $setupService->sendSetupLink(
                    $request->getAttribute('user'),
                    (int) $sub['id'],
                    $baseUrlResolver($request),
                    $shopNameResolver(),
                );
                $response['portal_setup_email_sent'] = $response['portal_setup']['email_sent'];
                $response['portal_setup_email_error'] = $response['portal_setup']['email_error'];
            }
            return Response::created($response);
        });

        $router->get('/api/subcontractors/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getSubcontractor(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->put('/api/subcontractors/{id}', function (Request $request) use (
            $controller,
            $service,
            $setupService,
            $baseUrlResolver,
            $shopNameResolver
        ) {
            $actor = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $before = $service->findSubcontractor($actor, $id);
            $body = $request->body();
            $response = $controller->updateSubcontractor(
                $request->getAttribute('user'),
                $id,
                $body
            );
            $sub = $response['data'] ?? [];
            $enableRequested = array_key_exists('portal_login_enabled', $body)
                && (bool) $body['portal_login_enabled'];
            $passwordProvided = array_key_exists('portal_password', $body)
                && trim((string) $body['portal_password']) !== '';
            $shouldSendSetup = $enableRequested
                && !$passwordProvided
                && !$before->portal_login_enabled
                && !empty($sub['portal_login_enabled'])
                && empty($sub['portal_password_set']);
            if ($shouldSendSetup) {
                $response['portal_setup'] = $setupService->sendSetupLink(
                    $actor,
                    (int) $sub['id'],
                    $baseUrlResolver($request),
                    $shopNameResolver(),
                );
                $response['portal_setup_email_sent'] = $response['portal_setup']['email_sent'];
                $response['portal_setup_email_error'] = $response['portal_setup']['email_error'];
            }
            return Response::json($response);
        });

        $router->post(
            '/api/subcontractors/{id}/portal-password-setup',
            function (Request $request) use ($setupService, $baseUrlResolver, $shopNameResolver) {
                return Response::created([
                    'data' => $setupService->sendSetupLink(
                        $request->getAttribute('user'),
                        (int) $request->getAttribute('id'),
                        $baseUrlResolver($request),
                        $shopNameResolver(),
                    ),
                ]);
            }
        );

        $router->delete('/api/subcontractors/{id}', function (Request $request) use ($controller) {
            $controller->deleteSubcontractor(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // ── per-workorder assignments ──────────────────────────────────────
        $router->get(
            '/api/workorders/{id}/subcontractors',
            function (Request $request) use ($controller) {
                return Response::json($controller->listWorkorderAssignments(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorders/{id}/subcontractors',
            function (Request $request) use ($controller) {
                return Response::created($controller->createAssignment(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        // ── individual assignment ops ─────────────────────────────────────
        $router->get('/api/subcontractor-assignments', function (Request $request) use ($controller) {
            $filters = array_filter([
                'status' => $request->queryParam('status'),
                'subcontractor_id' => $request->queryParam('subcontractor_id'),
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ], static fn($v) => $v !== null && $v !== '');
            return Response::json($controller->listAssignments(
                $request->getAttribute('user'),
                $filters
            ));
        });

        $router->get(
            '/api/subcontractor-assignments/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->getAssignment(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->put(
            '/api/subcontractor-assignments/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->updateAssignment(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/subcontractor-assignments/{id}/transition',
            function (Request $request) use ($controller) {
                return Response::json($controller->transitionAssignment(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/subcontractor-assignments/{id}',
            function (Request $request) use ($controller) {
                $controller->deleteAssignment(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                );
                return Response::noContent();
            }
        );
    });
};
