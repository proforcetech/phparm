<?php

use App\Services\Procurement\PurchaseOrderController;
use App\Services\Procurement\PurchaseOrderRepository;
use App\Services\Procurement\PurchaseOrderService;
use App\Services\Procurement\VendorController;
use App\Services\Procurement\VendorRepository;
use App\Services\Procurement\VendorService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Procurement endpoints (Phase 18 / S5 of docs/woms-expansion-plan.md).
 *
 * Vendor master:
 *   GET    /api/vendors                  list with status/query/consignment filters
 *   POST   /api/vendors                  create
 *   GET    /api/vendors/{id}             show
 *   PATCH  /api/vendors/{id}             update
 *   DELETE /api/vendors/{id}             delete (RESTRICTed by FK if POs exist)
 *
 * Purchase orders:
 *   GET    /api/purchase-orders                  list with vendor/status/wo/customer filters
 *   POST   /api/purchase-orders                  create draft
 *   GET    /api/purchase-orders/{id}             header + lines + receipts
 *   PATCH  /api/purchase-orders/{id}             update header (locked once received)
 *   POST   /api/purchase-orders/{id}/lines       add line
 *   PATCH  /api/purchase-order-lines/{id}        update line (cannot drop qty below received)
 *   DELETE /api/purchase-order-lines/{id}        delete line (only if not received against)
 *   POST   /api/purchase-orders/{id}/send        DRAFT → SENT
 *   POST   /api/purchase-orders/{id}/close       RECEIVED → CLOSED
 *   POST   /api/purchase-orders/{id}/cancel      cancel (only if no qty received)
 *   POST   /api/purchase-orders/{id}/receive     create receipt + receipt lines + writeback
 *
 * Permissions enforced inside services:
 *   procurement.view     all reads
 *   procurement.manage   vendor mgmt + PO author/edit/transition
 *   procurement.receive  receive endpoint only (parts staff get this)
 */
return function (Router $router, RouteContext $ctx): void {
    $vendorRepo = new VendorRepository($ctx->connection);
    $poRepo = new PurchaseOrderRepository($ctx->connection);

    $vendorService = new VendorService($vendorRepo, $ctx->gate, $ctx->auditLogger);
    $poService = new PurchaseOrderService(
        $ctx->connection,
        $poRepo,
        $vendorRepo,
        $ctx->gate,
        $ctx->auditLogger,
    );

    $vendorController = new VendorController($vendorService);
    $poController = new PurchaseOrderController($poService, $poRepo);

    $router->group([Middleware::auth()], function (Router $router) use ($vendorController, $poController) {
        // ──────────────── vendors ────
        $router->get('/api/vendors', function (Request $request) use ($vendorController) {
            $filters = [
                'status' => $request->queryParam('status'),
                'query' => $request->queryParam('query'),
                'consignment_only' => (bool) $request->queryParam('consignment_only'),
                'limit' => (int) $request->queryParam('limit', 100),
                'offset' => (int) $request->queryParam('offset', 0),
            ];
            return Response::json($vendorController->index(
                $request->getAttribute('user'),
                $filters,
            ));
        });

        $router->post('/api/vendors', function (Request $request) use ($vendorController) {
            return Response::created($vendorController->create(
                $request->getAttribute('user'),
                $request->body(),
            ));
        });

        $router->get('/api/vendors/{id}', function (Request $request) use ($vendorController) {
            return Response::json($vendorController->show(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->patch('/api/vendors/{id}', function (Request $request) use ($vendorController) {
            return Response::json($vendorController->update(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->delete('/api/vendors/{id}', function (Request $request) use ($vendorController) {
            $vendorController->delete(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            );
            return Response::noContent();
        });

        // ──────────────── purchase orders ────
        $router->get('/api/purchase-orders', function (Request $request) use ($poController) {
            $filters = [
                'vendor_id' => (int) $request->queryParam('vendor_id', 0),
                'status' => $request->queryParam('status'),
                'workorder_id' => (int) $request->queryParam('workorder_id', 0),
                'customer_id' => (int) $request->queryParam('customer_id', 0),
                'query' => $request->queryParam('query'),
                'limit' => (int) $request->queryParam('limit', 100),
                'offset' => (int) $request->queryParam('offset', 0),
            ];
            return Response::json($poController->index(
                $request->getAttribute('user'),
                $filters,
            ));
        });

        $router->post('/api/purchase-orders', function (Request $request) use ($poController) {
            return Response::created($poController->create(
                $request->getAttribute('user'),
                $request->body(),
            ));
        });

        $router->get('/api/purchase-orders/{id}', function (Request $request) use ($poController) {
            return Response::json($poController->show(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->patch('/api/purchase-orders/{id}', function (Request $request) use ($poController) {
            return Response::json($poController->update(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->post('/api/purchase-orders/{id}/lines', function (Request $request) use ($poController) {
            return Response::created($poController->addLine(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->patch('/api/purchase-order-lines/{id}', function (Request $request) use ($poController) {
            return Response::json($poController->updateLine(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->delete('/api/purchase-order-lines/{id}', function (Request $request) use ($poController) {
            $poController->removeLine(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            );
            return Response::noContent();
        });

        $router->post('/api/purchase-orders/{id}/send', function (Request $request) use ($poController) {
            return Response::json($poController->send(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/purchase-orders/{id}/close', function (Request $request) use ($poController) {
            return Response::json($poController->close(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/purchase-orders/{id}/cancel', function (Request $request) use ($poController) {
            return Response::json($poController->cancel(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->post('/api/purchase-orders/{id}/receive', function (Request $request) use ($poController) {
            return Response::json($poController->receive(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });
    });
};
