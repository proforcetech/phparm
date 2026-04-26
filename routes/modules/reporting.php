<?php

use App\Services\Reporting\ReportCatalogService;
use App\Services\Reporting\ReportExecutionRepository;
use App\Services\Reporting\ReportExportService;
use App\Services\Reporting\ReportingController;
use App\Services\Reporting\SavedReportRepository;
use App\Services\Reporting\SavedReportService;
use App\Services\Reporting\ScheduledReportRepository;
use App\Services\Reporting\ScheduledReportService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Cross-cutting reporting/BI endpoints.
 *
 * Catalog + ad-hoc:
 *   GET    /api/reporting/catalog                  list all known report types
 *   GET    /api/reporting/catalog/{key}            describe one report type
 *   POST   /api/reporting/run                      run an ad-hoc report (no save)
 *   POST   /api/reporting/export                   run + export ad-hoc report
 *
 * Saved reports:
 *   GET    /api/reporting/saved                    list mine + shared
 *   POST   /api/reporting/saved                    create
 *   GET    /api/reporting/saved/{id}               get one
 *   PUT    /api/reporting/saved/{id}               update
 *   DELETE /api/reporting/saved/{id}               delete
 *   POST   /api/reporting/saved/{id}/run           run a saved report
 *   GET    /api/reporting/saved/{id}/export.{fmt}  run+download (csv|json)
 *   GET    /api/reporting/saved/{id}/executions    history of runs for this saved
 *
 * Schedules:
 *   GET    /api/reporting/schedules                list all (manage gate)
 *   POST   /api/reporting/schedules                create (manage gate)
 *   GET    /api/reporting/schedules/{id}           get one
 *   PUT    /api/reporting/schedules/{id}           update (manage gate)
 *   DELETE /api/reporting/schedules/{id}           delete (manage gate)
 *
 * Audit:
 *   GET    /api/reporting/executions               recent executions across system
 */
return function (Router $router, RouteContext $ctx): void {
    $catalog = new ReportCatalogService($ctx->connection);
    $savedRepo = new SavedReportRepository($ctx->connection);
    $scheduleRepo = new ScheduledReportRepository($ctx->connection);
    $executionRepo = new ReportExecutionRepository($ctx->connection);
    $exporter = new ReportExportService();
    $reportService = new SavedReportService($savedRepo, $catalog, $executionRepo, $ctx->gate);
    $scheduleService = new ScheduledReportService(
        $scheduleRepo,
        $savedRepo,
        $reportService,
        $exporter,
        $ctx->gate
    );
    $controller = new ReportingController(
        $catalog,
        $reportService,
        $scheduleService,
        $savedRepo,
        $executionRepo,
        $exporter,
        $ctx->gate
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // --- Catalog ---
        $router->get(
            '/api/reporting/catalog',
            function (Request $request) use ($controller) {
                return Response::json($controller->listCatalog($request->getAttribute('user')));
            }
        );

        $router->get(
            '/api/reporting/catalog/{key}',
            function (Request $request) use ($controller) {
                return Response::json($controller->describe(
                    $request->getAttribute('user'),
                    (string) $request->getAttribute('key')
                ));
            }
        );

        // --- Ad-hoc run / export ---
        $router->post(
            '/api/reporting/run',
            function (Request $request) use ($controller) {
                return Response::json($controller->runAdhoc(
                    $request->getAttribute('user'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/reporting/export',
            function (Request $request) use ($controller) {
                $payload = $controller->exportAdhoc($request->getAttribute('user'), $request->body());
                return Response::make($payload['body'], 200, [
                    'Content-Type' => $payload['content_type'],
                    'Content-Disposition' => 'attachment; filename="' . $payload['filename'] . '"',
                ]);
            }
        );

        // --- Saved reports ---
        $router->get(
            '/api/reporting/saved',
            function (Request $request) use ($controller) {
                return Response::json($controller->listSaved($request->getAttribute('user')));
            }
        );

        $router->post(
            '/api/reporting/saved',
            function (Request $request) use ($controller) {
                return Response::created($controller->createSaved(
                    $request->getAttribute('user'),
                    $request->body()
                ));
            }
        );

        $router->get(
            '/api/reporting/saved/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->getSaved(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->put(
            '/api/reporting/saved/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->updateSaved(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/reporting/saved/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->deleteSaved(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/reporting/saved/{id}/run',
            function (Request $request) use ($controller) {
                return Response::json($controller->runSaved(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->get(
            '/api/reporting/saved/{id}/export.{format}',
            function (Request $request) use ($controller) {
                $payload = $controller->exportSaved(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    (string) $request->getAttribute('format')
                );
                return Response::make($payload['body'], 200, [
                    'Content-Type' => $payload['content_type'],
                    'Content-Disposition' => 'attachment; filename="' . $payload['filename'] . '"',
                ]);
            }
        );

        $router->get(
            '/api/reporting/saved/{id}/executions',
            function (Request $request) use ($controller) {
                return Response::json($controller->listExecutionsForSaved(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        // --- Schedules ---
        $router->get(
            '/api/reporting/schedules',
            function (Request $request) use ($controller) {
                return Response::json($controller->listSchedules($request->getAttribute('user')));
            }
        );

        $router->post(
            '/api/reporting/schedules',
            function (Request $request) use ($controller) {
                return Response::created($controller->createSchedule(
                    $request->getAttribute('user'),
                    $request->body()
                ));
            }
        );

        $router->get(
            '/api/reporting/schedules/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->getSchedule(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->put(
            '/api/reporting/schedules/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->updateSchedule(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/reporting/schedules/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->deleteSchedule(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        // --- Audit ---
        $router->get(
            '/api/reporting/executions',
            function (Request $request) use ($controller) {
                return Response::json($controller->listExecutions($request->getAttribute('user')));
            }
        );
    });
};
