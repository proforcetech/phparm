<?php

use App\Services\ConsolidatedBilling\ConsolidatedBillingController;
use App\Services\ConsolidatedBilling\ConsolidatedBillingService;
use App\Services\ConsolidatedBilling\ConsolidatedStatementRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Consolidated monthly statement endpoints — Phase 17 / M11 of
 * docs/woms-expansion-plan.md.
 *
 * Read perm:   consolidated_billing.view
 * Write perm:  consolidated_billing.manage
 *
 * Endpoints:
 *   GET    /api/consolidated-statements                  — list (paginated)
 *   GET    /api/consolidated-statements/{id}             — header + child invoice rows
 *   POST   /api/consolidated-statements                  — generate for one customer
 *   POST   /api/consolidated-statements/run-batch        — run monthly batch
 *   POST   /api/consolidated-statements/{id}/mark-sent   — flip draft → sent
 *   POST   /api/consolidated-statements/{id}/cancel      — cancel + clear pointers
 *   DELETE /api/consolidated-statements/{id}/invoices/{invoiceId} — detach one invoice
 */
return function (Router $router, RouteContext $ctx): void {
    $repo = new ConsolidatedStatementRepository($ctx->connection);
    $service = new ConsolidatedBillingService($ctx->connection, $repo, $ctx->auditLogger);
    $controller = new ConsolidatedBillingController($service, $repo, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {

        $router->get('/api/consolidated-statements', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'status' => $request->queryParam('status'),
                'period_start' => $request->queryParam('period_start'),
                'period_end' => $request->queryParam('period_end'),
            ];
            $filters = array_filter($filters, static fn ($v) => $v !== null && $v !== '');
            $page = (int) ($request->queryParam('page') ?? 1);
            $perPage = (int) ($request->queryParam('per_page') ?? 25);
            return Response::json([
                'data' => $controller->index($request->getAttribute('user'), $filters, $page, $perPage),
            ]);
        });

        $router->get('/api/consolidated-statements/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                ),
            ]);
        });

        $router->post('/api/consolidated-statements', function (Request $request) use ($controller) {
            $body = $request->body();
            $customerId = (int) ($body['customer_id'] ?? 0);
            $periodStart = (string) ($body['period_start'] ?? '');
            $periodEnd = (string) ($body['period_end'] ?? '');
            $notes = isset($body['notes']) ? (string) $body['notes'] : null;
            return Response::json([
                'data' => $controller->generate(
                    $request->getAttribute('user'),
                    $customerId,
                    $periodStart,
                    $periodEnd,
                    $notes,
                ),
            ], 201);
        });

        $router->post('/api/consolidated-statements/run-batch', function (Request $request) use ($controller) {
            $body = $request->body();
            $periodStart = (string) ($body['period_start'] ?? '');
            $periodEnd = (string) ($body['period_end'] ?? '');
            return Response::json([
                'data' => $controller->runBatch(
                    $request->getAttribute('user'),
                    $periodStart,
                    $periodEnd,
                ),
            ]);
        });

        $router->post('/api/consolidated-statements/{id}/mark-sent', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->markSent(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                ),
            ]);
        });

        $router->post('/api/consolidated-statements/{id}/cancel', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->cancel(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                ),
            ]);
        });

        $router->delete('/api/consolidated-statements/{id}/invoices/{invoiceId}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->detachInvoice(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    (int) $request->getAttribute('invoiceId'),
                ),
            ]);
        });
    });
};
