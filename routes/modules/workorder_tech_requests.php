<?php

use App\Services\Workorder\TechRequestController;
use App\Services\Workorder\TechRequestRepository;
use App\Services\Workorder\TechRequestService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Workorder additional-tech request endpoints (Phase 10.3).
 *
 * Read perm:    workorder_tech_requests.view
 * Create perm:  workorder_tech_requests.create   (file the request)
 * Manage perm:  workorder_tech_requests.manage   (approve/decline/fulfil/
 *                                                 add+remove direct techs)
 *
 * Resource families:
 *   /api/workorders/{id}/tech-requests              list/create per WO
 *   /api/workorders/{id}/additional-techs           list/add per WO
 *   /api/workorder-tech-requests                    cross-cutting list (filters)
 *   /api/workorder-tech-requests/{id}               fetch/update one
 *   /api/workorder-tech-requests/{id}/approve       pending → approved
 *   /api/workorder-tech-requests/{id}/decline       pending → declined
 *   /api/workorder-tech-requests/{id}/cancel        pending|approved → cancelled
 *   /api/workorder-tech-requests/{id}/fulfil        approved → fulfilled (assigns user)
 *   /api/workorder-additional-techs/{id}            update one (role/notes)
 *   /api/workorder-additional-techs/{id}/remove     soft-remove from WO
 */
return function (Router $router, RouteContext $ctx): void {
    $repo = new TechRequestRepository($ctx->connection);
    $service = new TechRequestService($repo, $ctx->gate);
    $controller = new TechRequestController($service);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // ── per-WO request list + create ────────────────────────
        $router->get(
            '/api/workorders/{id}/tech-requests',
            function (Request $request) use ($controller) {
                return Response::json($controller->listRequestsForWorkorder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorders/{id}/tech-requests',
            function (Request $request) use ($controller) {
                return Response::created($controller->createRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        // ── cross-cutting list (filters by status / urgency / requestor) ────
        $router->get(
            '/api/workorder-tech-requests',
            function (Request $request) use ($controller) {
                return Response::json($controller->listRequests(
                    $request->getAttribute('user'),
                    $request->query()
                ));
            }
        );

        // ── single request CRUD ─────────────────────────────────
        $router->get(
            '/api/workorder-tech-requests/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->getRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->put(
            '/api/workorder-tech-requests/{id}',
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
            '/api/workorder-tech-requests/{id}/approve',
            function (Request $request) use ($controller) {
                return Response::json($controller->approveRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorder-tech-requests/{id}/decline',
            function (Request $request) use ($controller) {
                return Response::json($controller->declineRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/workorder-tech-requests/{id}/cancel',
            function (Request $request) use ($controller) {
                return Response::json($controller->cancelRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorder-tech-requests/{id}/fulfil',
            function (Request $request) use ($controller) {
                return Response::json($controller->fulfilRequest(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        // ── additional techs on a WO ────────────────────────────
        $router->get(
            '/api/workorders/{id}/additional-techs',
            function (Request $request) use ($controller) {
                return Response::json($controller->listTechsForWorkorder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->query()
                ));
            }
        );

        $router->post(
            '/api/workorders/{id}/additional-techs',
            function (Request $request) use ($controller) {
                return Response::created($controller->addTech(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->put(
            '/api/workorder-additional-techs/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->updateTech(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/workorder-additional-techs/{id}/remove',
            function (Request $request) use ($controller) {
                return Response::json($controller->removeTech(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );
    });
};
