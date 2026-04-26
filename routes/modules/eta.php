<?php

use App\Services\Customer\CustomerRepository;
use App\Services\Portal\PortalEtaPromiseController;
use App\Services\Portal\PortalEtaPromiseRepository;
use App\Services\Portal\PortalEtaPromiseService;
use App\Services\Tickets\TicketRepository;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Staff-facing ETA promise endpoints (Phase 6.7 of docs/expansion-plan.md).
 *
 * Read perms: tickets.view / workorders.view
 * Write perms: tickets.manage / workorders.manage
 *
 * Portal-facing read endpoints live in routes/modules/portal.php (they
 * share the same PortalEtaPromiseService but use Middleware::portalAuth
 * instead of Middleware::auth() so portal sessions can reach them).
 *
 * The PortalAuthService dependency is passed in as null at this layer —
 * the staff-write code paths never invoke the portal scope loaders, so
 * a no-op subclass would work but the service already guards against
 * null portalAuth never being touched on the staff surface.
 */
return function (Router $router, RouteContext $ctx): void {
    $promiseRepo = new PortalEtaPromiseRepository($ctx->connection);
    $ticketRepo = new TicketRepository($ctx->connection);
    $workorderRepo = new WorkorderRepository($ctx->connection, $ctx->auditLogger);
    $customerRepo = new CustomerRepository($ctx->connection);

    // Staff-only surface — portalAuth is never invoked here, but the
    // service constructor still demands a PortalAuthService. Reuse the
    // same instance portal.php constructs, but in the staff context the
    // scope loaders (loadScoped*) are never called so we can pass a bare
    // one.
    $authConfig = $ctx->config['auth'] ?? [];
    $jwtConfig = $authConfig['jwt'] ?? [];
    $jwtService = new \App\Support\Auth\JwtService(
        $ctx->connection,
        $jwtConfig['secret'] ?? 'default-secret-key-change-in-production',
        $jwtConfig['ttl'] ?? 3600,
        $jwtConfig['refresh_ttl'] ?? 604800,
    );
    $portalAuth = new \App\Services\Portal\PortalAuthService(
        $ctx->connection,
        new \App\Services\Portal\PortalAccountRepository($ctx->connection),
        new \App\Services\Crm\CompanyRepository($ctx->connection),
        new \App\Services\Crm\SiteRepository($ctx->connection),
        $jwtService,
        new \App\Support\Auth\PasswordPolicy($authConfig),
        $ctx->gate,
        $ctx->auditLogger,
        $authConfig,
    );

    $service = new PortalEtaPromiseService(
        $ctx->connection,
        $promiseRepo,
        $ticketRepo,
        $workorderRepo,
        $customerRepo,
        $portalAuth,
        $ctx->gate,
        $ctx->auditLogger,
    );
    $controller = new PortalEtaPromiseController($service);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // Publish / cancel — tickets
        $router->post('/api/eta/tickets/{id}/promises', function (Request $request) use ($controller) {
            return Response::created($controller->publishForTicket(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->delete('/api/eta/tickets/{id}/promises/current', function (Request $request) use ($controller) {
            $controller->cancelCurrentForTicket(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            );
            return Response::noContent();
        });

        $router->get('/api/eta/tickets/{id}/promises', function (Request $request) use ($controller) {
            return Response::json($controller->listHistoryForTicketStaff(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        // Publish / cancel — workorders
        $router->post('/api/eta/workorders/{id}/promises', function (Request $request) use ($controller) {
            return Response::created($controller->publishForWorkorder(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->delete('/api/eta/workorders/{id}/promises/current', function (Request $request) use ($controller) {
            $controller->cancelCurrentForWorkorder(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            );
            return Response::noContent();
        });

        $router->get('/api/eta/workorders/{id}/promises', function (Request $request) use ($controller) {
            return Response::json($controller->listHistoryForWorkorderStaff(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });
    });
};
