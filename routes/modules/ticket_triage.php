<?php

use App\Services\Tickets\HeuristicTicketTriageScorer;
use App\Services\Tickets\TicketRepository;
use App\Services\Tickets\TicketTriageController;
use App\Services\Tickets\TicketTriageRepository;
use App\Services\Tickets\TicketTriageService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Ticket triage suggestion endpoints (Phase 10.5).
 *
 * Read perm:     ticket_triage.view
 * Generate perm: ticket_triage.generate    (run a scorer over a ticket;
 *                                          marks any prior pending
 *                                          suggestion for that ticket as
 *                                          'stale' before inserting)
 * Manage perm:   ticket_triage.manage      (accept/reject — accept writes
 *                                          back to the ticket row)
 *
 * Resource families:
 *   /api/tickets/{id}/triage-suggestions             list per-ticket history
 *   /api/tickets/{id}/triage-suggestions/generate    run scorer & persist
 *   /api/ticket-triage-suggestions                   pending queue widget
 *   /api/ticket-triage-suggestions/{id}              fetch single
 *   /api/ticket-triage-suggestions/{id}/accept       pending → accepted
 *   /api/ticket-triage-suggestions/{id}/reject       pending → rejected
 *
 * The scorer binding is the shipped HeuristicTicketTriageScorer. To swap
 * in an AI provider in production, replace the line below with whatever
 * implementation of TicketTriageScorerInterface the install ships.
 */
return function (Router $router, RouteContext $ctx): void {
    $repo = new TicketTriageRepository($ctx->connection);
    $tickets = new TicketRepository($ctx->connection);
    $scorer = new HeuristicTicketTriageScorer();
    $service = new TicketTriageService($repo, $tickets, $scorer, $ctx->gate);
    $controller = new TicketTriageController($service);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // ── per-ticket history list + generation trigger ────────
        $router->get(
            '/api/tickets/{id}/triage-suggestions',
            function (Request $request) use ($controller) {
                return Response::json($controller->listForTicket(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/tickets/{id}/triage-suggestions/generate',
            function (Request $request) use ($controller) {
                return Response::created($controller->generateForTicket(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        // ── triage queue widget (cross-cutting pending list) ────
        $router->get(
            '/api/ticket-triage-suggestions',
            function (Request $request) use ($controller) {
                return Response::json($controller->listPending(
                    $request->getAttribute('user'),
                    $request->query()
                ));
            }
        );

        // ── single suggestion fetch + decisions ─────────────────
        $router->get(
            '/api/ticket-triage-suggestions/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->getSuggestion(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/ticket-triage-suggestions/{id}/accept',
            function (Request $request) use ($controller) {
                return Response::json($controller->acceptSuggestion(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/ticket-triage-suggestions/{id}/reject',
            function (Request $request) use ($controller) {
                return Response::json($controller->rejectSuggestion(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );
    });
};
