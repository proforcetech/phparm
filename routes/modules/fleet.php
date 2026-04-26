<?php

use App\Services\Crm\CompanyRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Customer\CustomerRepository;
use App\Services\Fleet\FleetCostReportController;
use App\Services\Fleet\FleetCostReportRepository;
use App\Services\Fleet\FleetCostReportService;
use App\Services\Fleet\FleetExternalRepairController;
use App\Services\Fleet\FleetExternalRepairRepository;
use App\Services\Fleet\FleetExternalRepairService;
use App\Services\Fleet\FleetUnitAssignmentRepository;
use App\Services\Fleet\FleetUnitController;
use App\Services\Fleet\FleetUnitDowntimeController;
use App\Services\Fleet\FleetUnitDowntimeRepository;
use App\Services\Fleet\FleetUnitDowntimeService;
use App\Services\Fleet\FleetUnitReadingRepository;
use App\Services\Fleet\FleetUnitRepository;
use App\Services\Fleet\FleetUnitService;
use App\Services\Pm\PmFleetBindingController;
use App\Services\Pm\PmFleetBindingRepository;
use App\Services\Pm\PmFleetInheritanceService;
use App\Services\Pm\PmFrequencyService;
use App\Services\Pm\PmPlanRepository;
use App\Services\Pm\PmScheduleRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Fleet endpoints (Phase 7.1 of docs/expansion-plan.md).
 *
 * Read perms: fleet.view
 * Write perms: fleet.manage
 *
 * All routes require staff auth. Portal-facing fleet views (if any) will
 * be added in a later phase alongside per-portal-user driver assignment.
 */
return function (Router $router, RouteContext $ctx): void {
    $units = new FleetUnitRepository($ctx->connection);
    $readings = new FleetUnitReadingRepository($ctx->connection);
    $assignments = new FleetUnitAssignmentRepository($ctx->connection);
    // Phase 7.2 — wire the PM fleet-inheritance hook so a freshly-created
    // unit picks up its unit_type / unit-scope plans, and meter readings
    // advance meter-driven schedules.
    $pmBindings = new PmFleetBindingRepository($ctx->connection);
    $pmSchedules = new PmScheduleRepository($ctx->connection);
    $pmPlans = new PmPlanRepository($ctx->connection);
    $pmFreq = new PmFrequencyService();
    $pmInheritance = new PmFleetInheritanceService(
        $ctx->connection,
        $pmBindings,
        $pmSchedules,
        $pmPlans,
        $units,
        $pmFreq,
        $ctx->gate,
        $ctx->auditLogger,
    );
    $service = new FleetUnitService(
        $ctx->connection,
        $units,
        $readings,
        $assignments,
        new CompanyRepository($ctx->connection),
        new SiteRepository($ctx->connection),
        new CustomerRepository($ctx->connection),
        $ctx->gate,
        $ctx->auditLogger,
        $pmInheritance,
    );
    $controller = new FleetUnitController($service);
    $bindingController = new PmFleetBindingController($pmInheritance);

    // Phase 7.3 — downtime tracking. Runs alongside FleetUnitService so
    // the status-flip + window-open path is owned in one place rather
    // than spread across the unit CRUD surface.
    $downtimeRepo = new FleetUnitDowntimeRepository($ctx->connection);
    $downtimeService = new FleetUnitDowntimeService(
        $ctx->connection,
        $units,
        $downtimeRepo,
        $ctx->gate,
        $ctx->auditLogger,
    );
    $downtimeController = new FleetUnitDowntimeController($downtimeService);

    // Phase 7.5 — external vendor repair logs. Repository wired first
    // so the cost report can optionally aggregate its totals alongside
    // the internal workorder totals.
    $externalRepairRepo = new FleetExternalRepairRepository($ctx->connection);
    $externalRepairService = new FleetExternalRepairService(
        $ctx->connection,
        $units,
        $externalRepairRepo,
        $readings,
        $ctx->gate,
        $ctx->auditLogger,
    );
    $externalRepairController = new FleetExternalRepairController($externalRepairService);

    // Phase 7.4 — cost-per-mile / cost-per-hour reports. Read-only,
    // aggregates completed workorder cost against meter readings over a
    // date range. Shares the same gate (fleet.view) as the rest of the
    // fleet read surface. When ?include_external=1 is passed, Phase 7.5
    // external repair cost is unioned into the per-unit totals.
    $costReportRepo = new FleetCostReportRepository($ctx->connection);
    $costReportService = new FleetCostReportService($costReportRepo, $ctx->gate, $externalRepairRepo);
    $costReportController = new FleetCostReportController($costReportService);

    $router->group([Middleware::auth()], function (Router $router) use ($controller, $bindingController, $downtimeController, $costReportController, $externalRepairController) {
        // Listing requires ?company_id= since fleet data is per-tenant and
        // there's no platform-wide "all units" view.
        $router->get('/api/fleet/units', function (Request $request) use ($controller) {
            $companyId = (int) $request->queryParam('company_id');
            if ($companyId <= 0) {
                return Response::badRequest('company_id query param is required');
            }
            return Response::json($controller->listUnits(
                $request->getAttribute('user'),
                $companyId,
                [
                    'status' => $request->queryParam('status'),
                    'home_site_id' => $request->queryParam('home_site_id'),
                    'query' => $request->queryParam('query'),
                    'limit' => $request->queryParam('limit'),
                    'offset' => $request->queryParam('offset'),
                ],
            ));
        });

        $router->post('/api/fleet/units', function (Request $request) use ($controller) {
            $body = $request->body();
            $companyId = (int) ($body['company_id'] ?? 0);
            if ($companyId <= 0) {
                return Response::badRequest('company_id is required in the body');
            }
            return Response::created($controller->createUnit(
                $request->getAttribute('user'),
                $companyId,
                $body,
            ));
        });

        $router->get('/api/fleet/units/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getUnit(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->put('/api/fleet/units/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateUnit(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        // Soft-retire — readings/assignments persist for history.
        $router->delete('/api/fleet/units/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->retireUnit(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        // Readings (append-only meter history).
        $router->get('/api/fleet/units/{id}/readings', function (Request $request) use ($controller) {
            $rt = $request->queryParam('reading_type');
            $readingType = (is_string($rt) && $rt !== '') ? $rt : null;
            $limit = (int) ($request->queryParam('limit') ?? 100);
            return Response::json($controller->listReadings(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $readingType,
                $limit,
            ));
        });

        $router->post('/api/fleet/units/{id}/readings', function (Request $request) use ($controller) {
            return Response::created($controller->recordReading(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        // Assignments (append-only history, at most one current per unit).
        $router->get('/api/fleet/units/{id}/assignments', function (Request $request) use ($controller) {
            return Response::json($controller->listAssignments(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/fleet/units/{id}/assignments', function (Request $request) use ($controller) {
            return Response::created($controller->assignUnit(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->post('/api/fleet/units/{id}/assignments/end', function (Request $request) use ($controller) {
            return Response::json($controller->endAssignment(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        // ── PM fleet bindings (Phase 7.2) — plan ↔ unit / unit_type ──
        //
        // Namespaced under /api/pm/fleet-bindings since these records bind
        // PM plans to fleet targets, not fleet units themselves.
        $router->get('/api/pm/fleet-bindings', function (Request $request) use ($bindingController) {
            $companyId = (int) $request->queryParam('company_id');
            if ($companyId <= 0) {
                return Response::badRequest('company_id query param is required');
            }
            $activeOnly = $request->queryParam('active_only') !== null
                ? (bool) $request->queryParam('active_only')
                : false;
            return Response::json($bindingController->listForCompany(
                $request->getAttribute('user'),
                $companyId,
                $activeOnly,
            ));
        });

        $router->post('/api/pm/fleet-bindings', function (Request $request) use ($bindingController) {
            return Response::created($bindingController->create(
                $request->getAttribute('user'),
                $request->body(),
            ));
        });

        $router->put('/api/pm/fleet-bindings/{id}', function (Request $request) use ($bindingController) {
            return Response::json($bindingController->update(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->delete('/api/pm/fleet-bindings/{id}', function (Request $request) use ($bindingController) {
            return Response::json($bindingController->delete(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        // ── Downtime tracking (Phase 7.3) ──
        //
        // Open window semantics: GET /current returns the active window
        // or null; POST /start opens a new window (transactionally
        // closes any prior open one at the new start time); POST /end
        // stamps ended_at + flips status back to active when no other
        // windows remain open.
        $router->get('/api/fleet/units/{id}/downtime', function (Request $request) use ($downtimeController) {
            $limit = (int) ($request->queryParam('limit') ?? 100);
            return Response::json($downtimeController->listForUnit(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $limit,
            ));
        });

        $router->get('/api/fleet/units/{id}/downtime/current', function (Request $request) use ($downtimeController) {
            return Response::json($downtimeController->current(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/fleet/units/{id}/downtime/start', function (Request $request) use ($downtimeController) {
            return Response::created($downtimeController->start(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->post('/api/fleet/units/{id}/downtime/end', function (Request $request) use ($downtimeController) {
            return Response::json($downtimeController->end(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        // ── Cost reports (Phase 7.4) ──
        //
        // Date-ranged aggregate reports. Both endpoints require
        // company_id + from + to as query params. Pass YYYY-MM-DD
        // dates; the service expands them to full-day boundaries
        // (00:00:00 on from, 23:59:59 on to) so "from=today&to=today"
        // returns today's activity without the caller managing time
        // components.
        $router->get('/api/fleet/reports/cost-per-mile', function (Request $request) use ($costReportController) {
            $companyId = (int) $request->queryParam('company_id');
            if ($companyId <= 0) {
                return Response::badRequest('company_id query param is required');
            }
            $from = (string) ($request->queryParam('from') ?? '');
            $to = (string) ($request->queryParam('to') ?? '');
            if ($from === '' || $to === '') {
                return Response::badRequest('from and to query params are required (YYYY-MM-DD)');
            }
            $includeExternal = (bool) $request->queryParam('include_external');
            return Response::json($costReportController->costPerMile(
                $request->getAttribute('user'),
                $companyId,
                $from,
                $to,
                $includeExternal,
            ));
        });

        $router->get('/api/fleet/reports/cost-per-hour', function (Request $request) use ($costReportController) {
            $companyId = (int) $request->queryParam('company_id');
            if ($companyId <= 0) {
                return Response::badRequest('company_id query param is required');
            }
            $from = (string) ($request->queryParam('from') ?? '');
            $to = (string) ($request->queryParam('to') ?? '');
            if ($from === '' || $to === '') {
                return Response::badRequest('from and to query params are required (YYYY-MM-DD)');
            }
            $includeExternal = (bool) $request->queryParam('include_external');
            return Response::json($costReportController->costPerHour(
                $request->getAttribute('user'),
                $companyId,
                $from,
                $to,
                $includeExternal,
            ));
        });

        // ── External vendor repair logs (Phase 7.5) ──
        //
        // Unit-scoped list + create for day-to-day data entry, plus a
        // company-scoped list / filter surface for the fleet-wide "where
        // did our external vendor spend go" view. Resource updates +
        // deletes are id-scoped because external repairs are
        // independently addressable records (unlike readings, which
        // are append-only append-and-correct).
        $router->get('/api/fleet/units/{id}/external-repairs', function (Request $request) use ($externalRepairController) {
            $limit = (int) ($request->queryParam('limit') ?? 100);
            return Response::json($externalRepairController->listForUnit(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $limit,
            ));
        });

        $router->post('/api/fleet/units/{id}/external-repairs', function (Request $request) use ($externalRepairController) {
            return Response::created($externalRepairController->create(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->get('/api/fleet/external-repairs', function (Request $request) use ($externalRepairController) {
            $companyId = (int) $request->queryParam('company_id');
            if ($companyId <= 0) {
                return Response::badRequest('company_id query param is required');
            }
            $filters = [
                'vendor' => $request->queryParam('vendor'),
                'category' => $request->queryParam('category'),
                'from' => $request->queryParam('from'),
                'to' => $request->queryParam('to'),
                'limit' => $request->queryParam('limit'),
            ];
            return Response::json($externalRepairController->listForCompany(
                $request->getAttribute('user'),
                $companyId,
                $filters,
            ));
        });

        $router->get('/api/fleet/external-repairs/{id}', function (Request $request) use ($externalRepairController) {
            return Response::json($externalRepairController->get(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->put('/api/fleet/external-repairs/{id}', function (Request $request) use ($externalRepairController) {
            return Response::json($externalRepairController->update(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->delete('/api/fleet/external-repairs/{id}', function (Request $request) use ($externalRepairController) {
            return Response::json($externalRepairController->delete(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });
    });
};
