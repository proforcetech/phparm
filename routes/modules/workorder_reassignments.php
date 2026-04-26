<?php

use App\Services\Workorder\ReassignmentController;
use App\Services\Workorder\ReassignmentRepository;
use App\Services\Workorder\ReassignmentService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Workorder reassignment-request endpoints (Phase 10.4).
 *
 * Read perm:    workorder_reassignment_requests.view
 * Create perm:  workorder_reassignment_requests.create   (file the request)
 * Manage perm:  workorder_reassignment_requests.manage   (approve/decline/
 *                                                        fulfil/direct
 *                                                        reassignment)
 *
 * Resource families:
 *   /api/workorders/{id}/reassignment-requests              list/create per WO
 *   /api/workorders/{id}/reassignment-history               per-WO audit log
 *   /api/workorders/{id}/reassign-now                       direct manager
 *                                                          reassign (skip
 *                                                          request workflow)
 *   /api/workorder-reassignment-requests                    cross-cutting list
 *   /api/workorder-reassignment-requests/{id}               fetch/update
 *   /api/workorder-reassignment-requests/{id}/approve       pending → approved
 *   /api/workorder-reassignment-requests/{id}/decline       pending → declined
 *   /api/workorder-reassignment-requests/{id}/cancel        pending|approved → cancelled
 *   /api/workorder-reassignment-requests/{id}/fulfil        approved → fulfilled
 */
return function (Router $router, RouteContext $ctx): void {
    $repo = new ReassignmentRepository($ctx->connection);
    $service = new ReassignmentService($repo, $ctx->gate);
    $controller = new ReassignmentController($service);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // ── per-WO request list + create ────────────────────────
        $router->get(
            '/api/workorders/{id}/reassignment-requests',
            function (Request $request) use ($controller) {
                return Response::json($controller->listRequestsForWorkorder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorders/{id}/reassignment-requests',
            function (Request $request) use ($controller) {
                return Response::created($controller->createRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        // ── per-WO history (audit log of every reassignment) ────
        $router->get(
            '/api/workorders/{id}/reassignment-history',
            function (Request $request) use ($controller) {
                return Response::json($controller->listHistoryForWorkorder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        // ── direct reassignment (manager bypass of request flow) ────
        $router->post(
            '/api/workorders/{id}/reassign-now',
            function (Request $request) use ($controller) {
                return Response::created($controller->reassignDirectly(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        // ── cross-cutting list (filters by status/urgency/requestor/reason) ────
        $router->get(
            '/api/workorder-reassignment-requests',
            function (Request $request) use ($controller) {
                return Response::json($controller->listRequests(
                    $request->getAttribute('user'),
                    $request->query()
                ));
            }
        );

        // ── single request CRUD ─────────────────────────────────
        $router->get(
            '/api/workorder-reassignment-requests/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->getRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->put(
            '/api/workorder-reassignment-requests/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->updateRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        // ── lifecycle transitions ───────────────────────────────
        $router->post(
            '/api/workorder-reassignment-requests/{id}/approve',
            function (Request $request) use ($controller) {
                return Response::json($controller->approveRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorder-reassignment-requests/{id}/decline',
            function (Request $request) use ($controller) {
                return Response::json($controller->declineRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/workorder-reassignment-requests/{id}/cancel',
            function (Request $request) use ($controller) {
                return Response::json($controller->cancelRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorder-reassignment-requests/{id}/fulfil',
            function (Request $request) use ($controller) {
                return Response::json($controller->fulfilRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );
    });
};
