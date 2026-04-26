<?php

use App\Services\Assets\SiteAssetRepository;
use App\Services\Contracts\ContractBillingService;
use App\Services\Contracts\ContractConsumptionRepository;
use App\Services\Contracts\ContractEntitlementRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Pm\PmComplianceService;
use App\Services\Pm\PmController;
use App\Services\Pm\PmGenerationRepository;
use App\Services\Pm\PmLinkageService;
use App\Services\Pm\PmPlanRepository;
use App\Services\Pm\PmScheduleRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Preventative-maintenance endpoints (Phase 5.1 of docs/expansion-plan.md).
 *
 * Read perms: pm.view
 * Write perms: pm.manage
 */
return function (Router $router, RouteContext $ctx): void {
    $plans = new PmPlanRepository($ctx->connection);
    $schedules = new PmScheduleRepository($ctx->connection);
    $generations = new PmGenerationRepository($ctx->connection);
    $compliance = new PmComplianceService($schedules, $generations);
    $billing = new ContractBillingService(
        new ContractRepository($ctx->connection),
        new ContractEntitlementRepository($ctx->connection),
        new ContractConsumptionRepository($ctx->connection),
        $ctx->gate,
        $ctx->auditLogger,
    );
    $linkage = new PmLinkageService(
        $generations, $schedules, $billing, $ctx->gate, $ctx->auditLogger
    );
    $controller = new PmController(
        $plans,
        $schedules,
        new SiteRepository($ctx->connection),
        new SiteAssetRepository($ctx->connection),
        $ctx->gate,
        $ctx->auditLogger,
        $compliance,
        $linkage,
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // Plans.
        $router->get('/api/pm/plans', function (Request $request) use ($controller) {
            return Response::json($controller->listPlans(
                $request->getAttribute('user'),
                [
                    'company_id' => $request->queryParam('company_id'),
                    'division_id' => $request->queryParam('division_id'),
                    'is_active' => $request->queryParam('is_active'),
                    'query' => $request->queryParam('query'),
                ]
            ));
        });

        $router->get('/api/pm/plans/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getPlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/pm/plans', function (Request $request) use ($controller) {
            return Response::created($controller->createPlan(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/pm/plans/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updatePlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/pm/plans/{id}', function (Request $request) use ($controller) {
            $controller->deletePlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Schedules.
        $router->get('/api/pm/schedules', function (Request $request) use ($controller) {
            return Response::json($controller->listSchedules(
                $request->getAttribute('user'),
                [
                    'company_id' => $request->queryParam('company_id'),
                    'site_id' => $request->queryParam('site_id'),
                    'asset_id' => $request->queryParam('asset_id'),
                    'plan_id' => $request->queryParam('plan_id'),
                    'status' => $request->queryParam('status'),
                    'contract_id' => $request->queryParam('contract_id'),
                ]
            ));
        });

        $router->get('/api/pm/schedules/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getSchedule(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/pm/schedules', function (Request $request) use ($controller) {
            return Response::created($controller->createSchedule(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/pm/schedules/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateSchedule(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/pm/schedules/{id}', function (Request $request) use ($controller) {
            $controller->deleteSchedule(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Compliance (5.4).
        $router->get('/api/pm/compliance/overdue', function (Request $request) use ($controller) {
            return Response::json($controller->overdueReport(
                $request->getAttribute('user'),
                [
                    'as_of' => $request->queryParam('as_of'),
                    'company_id' => $request->queryParam('company_id'),
                    'site_id' => $request->queryParam('site_id'),
                ]
            ));
        });

        $router->get('/api/pm/schedules/{id}/compliance', function (Request $request) use ($controller) {
            return Response::json($controller->scheduleCompliance(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->queryParam('since'),
                $request->queryParam('until')
            ));
        });

        $router->get('/api/pm/compliance/companies/{companyId}', function (Request $request) use ($controller) {
            return Response::json($controller->companyCompliance(
                $request->getAttribute('user'),
                (int) $request->getAttribute('companyId'),
                $request->queryParam('since'),
                $request->queryParam('until')
            ));
        });

        // Completion + contract entitlement linkage (5.5).
        $router->post('/api/pm/generations/{id}/complete', function (Request $request) use ($controller) {
            return Response::json($controller->recordCompletion(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });
    });
};
