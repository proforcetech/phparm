<?php

use App\Services\Workorder\ChangeOrderController;
use App\Services\Workorder\ChangeOrderRepository;
use App\Services\Workorder\ChangeOrderService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Workorder change order endpoints (Phase 10.2 of docs/expansion-plan.md).
 *
 * Read perm:    workorder_change_orders.view
 * Write perm:   workorder_change_orders.manage   (create/edit/items/submit/cancel)
 * Approve perm: workorder_change_orders.approve  (approve/reject — separation of duties)
 *
 * Resource families:
 *   /api/workorders/{id}/change-orders             list/create/summarize per WO
 *   /api/workorder-change-orders/{id}              fetch/update/delete one
 *   /api/workorder-change-orders/{id}/items        line items
 *   /api/workorder-change-orders/{id}/submit       draft → pending_approval
 *   /api/workorder-change-orders/{id}/approve      pending → approved
 *   /api/workorder-change-orders/{id}/reject       pending → rejected
 *   /api/workorder-change-orders/{id}/cancel       draft|pending → cancelled
 *   /api/workorder-change-order-items/{id}         single item update/delete
 */
return function (Router $router, RouteContext $ctx): void {
    $repo = new ChangeOrderRepository($ctx->connection);
    $service = new ChangeOrderService($repo, $ctx->gate);
    $controller = new ChangeOrderController($service);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // ── per-workorder list + create + summary ────────────────────────
        $router->get(
            '/api/workorders/{id}/change-orders',
            function (Request $request) use ($controller) {
                return Response::json($controller->listForWorkorder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorders/{id}/change-orders',
            function (Request $request) use ($controller) {
                return Response::created($controller->createChangeOrder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->get(
            '/api/workorders/{id}/change-orders/summary',
            function (Request $request) use ($controller) {
                return Response::json($controller->summarizeForWorkorder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        // ── single change order CRUD ─────────────────────────────────────
        $router->get(
            '/api/workorder-change-orders/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->getChangeOrder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->put(
            '/api/workorder-change-orders/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->updateChangeOrder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/workorder-change-orders/{id}',
            function (Request $request) use ($controller) {
                $controller->deleteChangeOrder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                );
                return Response::noContent();
            }
        );

        // ── line items ───────────────────────────────────────────────────
        $router->post(
            '/api/workorder-change-orders/{id}/items',
            function (Request $request) use ($controller) {
                return Response::created($controller->addItem(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->put(
            '/api/workorder-change-order-items/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->updateItem(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/workorder-change-order-items/{id}',
            function (Request $request) use ($controller) {
                $controller->deleteItem(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                );
                return Response::noContent();
            }
        );

        // ── lifecycle transitions ────────────────────────────────────────
        $router->post(
            '/api/workorder-change-orders/{id}/submit',
            function (Request $request) use ($controller) {
                return Response::json($controller->submit(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorder-change-orders/{id}/approve',
            function (Request $request) use ($controller) {
                return Response::json($controller->approve(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/workorder-change-orders/{id}/reject',
            function (Request $request) use ($controller) {
                return Response::json($controller->reject(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/workorder-change-orders/{id}/cancel',
            function (Request $request) use ($controller) {
                return Response::json($controller->cancel(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );
    });
};
