<?php

use App\Services\ChangeManagement\CabApprovalRepository;
use App\Services\ChangeManagement\ChangeRequestController;
use App\Services\ChangeManagement\ChangeRequestRepository;
use App\Services\ChangeManagement\ChangeRequestService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Change management endpoints — Phase 14 / S3 of docs/woms-expansion-plan.md.
 *
 * Read perm: change_management.view
 * Write perm: change_management.manage
 *
 * Endpoint groups:
 *   /api/change-requests                    — RFC list/create/show/update
 *   /api/change-requests/{id}/transition    — state-machine move
 *   /api/change-requests/{id}/approvals     — CAB vote list + tally
 *   /api/change-requests/{id}/votes         — record/overwrite a vote
 *   /api/change-requests/{id}/decide        — auto-resolve from CAB tally
 *   /api/change-window                      — calendar query
 *
 * Response envelope: { data: <controller payload> } — same as other modules.
 */
return function (Router $router, RouteContext $ctx): void {
    $changes = new ChangeRequestRepository($ctx->connection);
    $approvals = new CabApprovalRepository($ctx->connection);
    $service = new ChangeRequestService(
        $ctx->connection,
        $changes,
        $approvals,
        $ctx->auditLogger,
    );
    $controller = new ChangeRequestController(
        $changes,
        $approvals,
        $service,
        $ctx->gate,
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {

        // ---- list / show / write -----------------------------------------
        $router->get('/api/change-requests', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'status' => $request->queryParam('status'),
                'change_type' => $request->queryParam('change_type'),
                'risk_level' => $request->queryParam('risk_level'),
                'requested_by_user_id' => $request->queryParam('requested_by_user_id'),
                'assigned_to_user_id' => $request->queryParam('assigned_to_user_id'),
                'originating_ticket_id' => $request->queryParam('originating_ticket_id'),
                'affected_site_asset_id' => $request->queryParam('affected_site_asset_id'),
                'window_start' => $request->queryParam('window_start'),
                'window_end' => $request->queryParam('window_end'),
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

        $router->get('/api/change-requests/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/change-requests', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->store(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/change-requests/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        // ---- state machine ----------------------------------------------
        $router->post('/api/change-requests/{id}/transition', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->transition(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        // ---- CAB approvals ----------------------------------------------
        $router->get('/api/change-requests/{id}/approvals', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->listApprovals(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/change-requests/{id}/votes', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->recordVote(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->post('/api/change-requests/{id}/decide', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->decideFromCab(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        // ---- change-window calendar -------------------------------------
        $router->get('/api/change-window', function (Request $request) use ($controller) {
            $start = (string) $request->queryParam('start', '');
            $end = (string) $request->queryParam('end', '');
            return Response::json([
                'data' => $controller->window(
                    $request->getAttribute('user'),
                    $start,
                    $end
                ),
            ]);
        });
    });
};
