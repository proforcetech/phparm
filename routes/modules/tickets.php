<?php

use App\Services\Assets\SiteAssetRepository;
use App\Services\Contracts\ContractEntitlementRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSlaResolver;
use App\Services\Crm\SiteRepository;
use App\Services\Tickets\SlaClockService;
use App\Services\Tickets\TicketCategoryRepository;
use App\Services\Tickets\TicketCloseReasonRepository;
use App\Services\Tickets\TicketController;
use App\Services\Tickets\TicketEscalationRuleRepository;
use App\Services\Tickets\TicketEventRepository;
use App\Services\Tickets\TicketFailureCodeRepository;
use App\Services\Tickets\TicketQueueRepository;
use App\Services\Tickets\TicketRepository;
use App\Services\Tickets\TicketResolutionCodeRepository;
use App\Services\Tickets\TicketRoutingRuleRepository;
use App\Services\Tickets\TicketRoutingService;
use App\Services\Tickets\TicketSlaClockRepository;
use App\Services\Tickets\TicketSlaPolicyRepository;
use App\Services\Tickets\TicketWorkorderLinkRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Support-desk ticket endpoints (Phase 3.1 of docs/expansion-plan.md).
 *
 * Read perms: tickets.view / ticket_categories.view
 * Write perms: tickets.manage / tickets.assign / ticket_categories.manage
 */
return function (Router $router, RouteContext $ctx): void {
    $ticketsRepo = new TicketRepository($ctx->connection);
    $slaClocksRepo = new TicketSlaClockRepository($ctx->connection);
    $slaPoliciesRepo = new TicketSlaPolicyRepository($ctx->connection);
    $contractSlaResolver = new ContractSlaResolver(
        new ContractRepository($ctx->connection),
        new ContractEntitlementRepository($ctx->connection),
        $slaPoliciesRepo,
    );
    $slaService = new SlaClockService($slaClocksRepo, $slaPoliciesRepo, $contractSlaResolver);
    $assetsRepo = new SiteAssetRepository($ctx->connection);
    $routingRulesRepo = new TicketRoutingRuleRepository($ctx->connection);
    $routingService = new TicketRoutingService($routingRulesRepo, $assetsRepo);
    $queuesRepo = new TicketQueueRepository($ctx->connection);
    $escalationRulesRepo = new TicketEscalationRuleRepository($ctx->connection);
    $workorderLinksRepo = new TicketWorkorderLinkRepository($ctx->connection);
    $closeReasonsRepo = new TicketCloseReasonRepository($ctx->connection);
    $resolutionCodesRepo = new TicketResolutionCodeRepository($ctx->connection);
    $failureCodesRepo = new TicketFailureCodeRepository($ctx->connection);

    $controller = new TicketController(
        $ticketsRepo,
        new TicketCategoryRepository($ctx->connection),
        new TicketEventRepository($ctx->connection),
        new SiteRepository($ctx->connection),
        $assetsRepo,
        $ctx->gate,
        $ctx->auditLogger,
        $slaService,
        $slaPoliciesRepo,
        $routingService,
        $routingRulesRepo,
        $queuesRepo,
        $escalationRulesRepo,
        $workorderLinksRepo,
        $closeReasonsRepo,
        $resolutionCodesRepo,
        $failureCodesRepo
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // Categories
        $router->get('/api/ticket-categories', function (Request $request) use ($controller) {
            $divisionId = $request->queryParam('division_id');
            return Response::json($controller->listCategories(
                $request->getAttribute('user'),
                $divisionId !== null ? (int) $divisionId : null,
                (bool) $request->queryParam('include_inactive', false)
            ));
        });

        $router->post('/api/ticket-categories', function (Request $request) use ($controller) {
            return Response::created($controller->createCategory(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/ticket-categories/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateCategory(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/ticket-categories/{id}', function (Request $request) use ($controller) {
            $controller->deleteCategory(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Tickets
        $router->get('/api/tickets', function (Request $request) use ($controller) {
            return Response::json($controller->listTickets(
                $request->getAttribute('user'),
                [
                    'company_id' => $request->queryParam('company_id'),
                    'site_id' => $request->queryParam('site_id'),
                    'division_id' => $request->queryParam('division_id'),
                    'asset_id' => $request->queryParam('asset_id'),
                    'status' => $request->queryParam('status'),
                    'priority' => $request->queryParam('priority'),
                    'assigned_to_user_id' => $request->queryParam('assigned_to_user_id'),
                    'queue_id' => $request->queryParam('queue_id'),
                    'category_id' => $request->queryParam('category_id'),
                    'parent_ticket_id' => $request->queryParam('parent_ticket_id'),
                    'query' => $request->queryParam('query'),
                    'limit' => $request->queryParam('limit', 100),
                    'offset' => $request->queryParam('offset', 0),
                ]
            ));
        });

        $router->get('/api/tickets/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getTicket(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/tickets', function (Request $request) use ($controller) {
            return Response::created($controller->createTicket(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/tickets/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateTicket(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/tickets/{id}', function (Request $request) use ($controller) {
            $controller->deleteTicket(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        $router->post('/api/tickets/{id}/comments', function (Request $request) use ($controller) {
            return Response::created($controller->addComment(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        // SLA clocks snapshot for a ticket.
        $router->get('/api/tickets/{id}/sla', function (Request $request) use ($controller) {
            return Response::json($controller->getTicketSla(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        // SLA policy catalog.
        $router->get('/api/ticket-sla-policies', function (Request $request) use ($controller) {
            $divisionId = $request->queryParam('division_id');
            return Response::json($controller->listSlaPolicies(
                $request->getAttribute('user'),
                $divisionId !== null ? (int) $divisionId : null,
                (bool) $request->queryParam('active_only', true)
            ));
        });

        $router->post('/api/ticket-sla-policies', function (Request $request) use ($controller) {
            return Response::created($controller->createSlaPolicy(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/ticket-sla-policies/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateSlaPolicy(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/ticket-sla-policies/{id}', function (Request $request) use ($controller) {
            $controller->deleteSlaPolicy(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Routing rules catalog.
        $router->get('/api/ticket-routing-rules', function (Request $request) use ($controller) {
            return Response::json($controller->listRoutingRules(
                $request->getAttribute('user'),
                (bool) $request->queryParam('active_only', false)
            ));
        });

        $router->post('/api/ticket-routing-rules', function (Request $request) use ($controller) {
            return Response::created($controller->createRoutingRule(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/ticket-routing-rules/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateRoutingRule(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/ticket-routing-rules/{id}', function (Request $request) use ($controller) {
            $controller->deleteRoutingRule(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Ticket queues catalog.
        $router->get('/api/ticket-queues', function (Request $request) use ($controller) {
            $divisionId = $request->queryParam('division_id');
            return Response::json($controller->listQueues(
                $request->getAttribute('user'),
                $divisionId !== null ? (int) $divisionId : null,
                (bool) $request->queryParam('active_only', true)
            ));
        });

        $router->post('/api/ticket-queues', function (Request $request) use ($controller) {
            return Response::created($controller->createQueue(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/ticket-queues/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateQueue(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/ticket-queues/{id}', function (Request $request) use ($controller) {
            $controller->deleteQueue(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Escalation rules catalog.
        $router->get('/api/ticket-escalation-rules', function (Request $request) use ($controller) {
            return Response::json($controller->listEscalationRules(
                $request->getAttribute('user'),
                (bool) $request->queryParam('active_only', false)
            ));
        });

        $router->post('/api/ticket-escalation-rules', function (Request $request) use ($controller) {
            return Response::created($controller->createEscalationRule(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/ticket-escalation-rules/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateEscalationRule(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/ticket-escalation-rules/{id}', function (Request $request) use ($controller) {
            $controller->deleteEscalationRule(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Close reason catalog.
        $router->get('/api/ticket-close-reasons', function (Request $request) use ($controller) {
            return Response::json($controller->listCloseReasons(
                $request->getAttribute('user'),
                (bool) $request->queryParam('active_only', true)
            ));
        });

        $router->post('/api/ticket-close-reasons', function (Request $request) use ($controller) {
            return Response::created($controller->createCloseReason(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/ticket-close-reasons/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateCloseReason(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/ticket-close-reasons/{id}', function (Request $request) use ($controller) {
            $controller->deleteCloseReason(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Resolution code catalog.
        $router->get('/api/ticket-resolution-codes', function (Request $request) use ($controller) {
            return Response::json($controller->listResolutionCodes(
                $request->getAttribute('user'),
                (bool) $request->queryParam('active_only', true)
            ));
        });

        $router->post('/api/ticket-resolution-codes', function (Request $request) use ($controller) {
            return Response::created($controller->createResolutionCode(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/ticket-resolution-codes/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateResolutionCode(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/ticket-resolution-codes/{id}', function (Request $request) use ($controller) {
            $controller->deleteResolutionCode(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Failure code catalog.
        $router->get('/api/ticket-failure-codes', function (Request $request) use ($controller) {
            return Response::json($controller->listFailureCodes(
                $request->getAttribute('user'),
                (bool) $request->queryParam('active_only', true)
            ));
        });

        $router->post('/api/ticket-failure-codes', function (Request $request) use ($controller) {
            return Response::created($controller->createFailureCode(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/ticket-failure-codes/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateFailureCode(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/ticket-failure-codes/{id}', function (Request $request) use ($controller) {
            $controller->deleteFailureCode(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Ticket ↔ Workorder links.
        $router->get('/api/tickets/{id}/workorders', function (Request $request) use ($controller) {
            return Response::json($controller->listWorkorderLinks(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/tickets/{id}/workorders', function (Request $request) use ($controller) {
            return Response::created($controller->linkWorkorder(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/tickets/{id}/workorders/{link_id}', function (Request $request) use ($controller) {
            $controller->unlinkWorkorder(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                (int) $request->getAttribute('link_id')
            );
            return Response::noContent();
        });
    });
};
