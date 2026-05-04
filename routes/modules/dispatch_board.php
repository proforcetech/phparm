<?php

use App\Services\DispatchBoard\DispatchBoardController;
use App\Services\DispatchBoard\DispatchBoardService;
use App\Services\Skills\SkillRepository;
use App\Services\Skills\SkillMatrixService;
use App\Services\Skills\UserSkillRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Multi-trade dispatch board endpoints — Phase 17 / M10 of
 * docs/woms-expansion-plan.md.
 *
 * Read perm:   dispatch_board.view
 * Assign perm: dispatch_board.assign
 *
 * Endpoint groups:
 *   /api/dispatch-board                          — board payload
 *   /api/dispatch-board/{wo}/assign              — drop a tech onto a WO
 */
return function (Router $router, RouteContext $ctx): void {
    $skillRepo = new SkillRepository($ctx->connection);
    $userSkillRepo = new UserSkillRepository($ctx->connection);
    $skillMatrix = new SkillMatrixService(
        $ctx->connection,
        $skillRepo,
        $userSkillRepo,
        $ctx->auditLogger,
    );
    $service = new DispatchBoardService(
        $ctx->connection,
        $skillMatrix,
        $ctx->auditLogger,
    );
    $controller = new DispatchBoardController($service, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {

        $router->get('/api/dispatch-board', function (Request $request) use ($controller) {
            $filters = [
                'service_line_id' => $request->queryParam('service_line_id'),
                'status' => $request->queryParam('status'),
                'priority' => $request->queryParam('priority'),
                'unassigned_only' => $request->queryParam('unassigned_only'),
                'date_from' => $request->queryParam('date_from'),
                'date_to' => $request->queryParam('date_to'),
            ];
            $filters = array_filter(
                $filters,
                static fn ($v) => $v !== null && $v !== ''
            );
            if (isset($filters['unassigned_only'])) {
                $filters['unassigned_only'] = filter_var($filters['unassigned_only'], FILTER_VALIDATE_BOOLEAN);
            }
            return Response::json([
                'data' => $controller->board($request->getAttribute('user'), $filters),
            ]);
        });

        $router->post('/api/dispatch-board/{wo}/assign', function (Request $request) use ($controller) {
            $body = $request->body();
            $tid = $body['technician_id'] ?? null;
            $tid = ($tid === null || $tid === '') ? null : (int) $tid;
            $controller->assign(
                $request->getAttribute('user'),
                (int) $request->getAttribute('wo'),
                $tid
            );
            return Response::noContent();
        });
    });
};
