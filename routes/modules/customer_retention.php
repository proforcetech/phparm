<?php

use App\Services\Customer\CustomerRepository;
use App\Services\Customer\CustomerRetentionReportController;
use App\Services\Customer\CustomerRetentionReportService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;
use App\Support\Webhooks\WebhookDispatcher;

/**
 * Customer retention report module.
 * Migrated from routes/api.php as part of Phase 0.1 of docs/expansion-plan.md.
 */
return function (Router $router, RouteContext $ctx): void {
    $webhookConfig = $ctx->config['customer_retention']['webhooks'] ?? [];
    $webhooks = new WebhookDispatcher(
        !empty($webhookConfig['enabled']) ? ($webhookConfig['endpoints'] ?? []) : [],
        (string) ($webhookConfig['secret'] ?? ''),
        (int) ($webhookConfig['timeout'] ?? 5),
        $ctx->auditLogger
    );

    $controller = new CustomerRetentionReportController(
        new CustomerRetentionReportService(
            new CustomerRepository($ctx->connection),
            $webhooks
        ),
        $ctx->gate
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->get('/api/reports/customer-retention', function (Request $request) use ($controller) {
            $user = $request->getAttribute('user');
            $params = [
                'months' => $request->queryParam('months', 6),
                'limit' => $request->queryParam('limit', 50),
                'offset' => $request->queryParam('offset', 0),
                'query' => $request->queryParam('query'),
            ];

            return Response::json($controller->index($user, $params));
        });

        $router->get('/api/reports/customer-retention/export', function (Request $request) use ($controller) {
            $user = $request->getAttribute('user');
            $params = [
                'months' => $request->queryParam('months', 6),
                'format' => $request->queryParam('format', 'csv'),
                'query' => $request->queryParam('query'),
            ];

            return Response::json($controller->export($user, $params));
        });

        $router->post('/api/reports/customer-retention/hooks', function (Request $request) use ($controller) {
            $user = $request->getAttribute('user');

            return Response::json($controller->dispatchCampaign($user, $request->body()));
        });
    });
};
