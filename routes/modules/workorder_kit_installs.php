<?php

use App\Services\Estimate\BundleService;
use App\Services\Inventory\InventoryTransactionRepository;
use App\Services\Inventory\InventoryVehicleCompatibilityRepository;
use App\Services\Workorder\Kit\WorkorderKitInstallController;
use App\Services\Workorder\Kit\WorkorderKitInstallRepository;
use App\Services\Workorder\Kit\WorkorderKitInstallService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Workorder kit/bundle install endpoints (Phase 10.8).
 *
 * Permissions:
 *   workorder_kits.view     read installs + items (all GETs)
 *   workorder_kits.install  plan + transition planned→installed
 *   workorder_kits.cancel   cancel a planned/installed install
 *   workorder_kits.manage   hard-delete a planned install
 *
 * The estimate-side bundle workflow (BundleService::applyToEstimate) is
 * unchanged; this surface is the parallel WO-side flow with inventory
 * consumption + reversible cancellation.
 */
return function (Router $router, RouteContext $ctx): void {
    $compatibilityRepo = new InventoryVehicleCompatibilityRepository($ctx->connection);
    $bundleService = new BundleService($ctx->connection, $compatibilityRepo);
    $txRepo = new InventoryTransactionRepository($ctx->connection);
    $repo = new WorkorderKitInstallRepository($ctx->connection);
    $service = new WorkorderKitInstallService(
        $ctx->connection,
        $repo,
        $bundleService,
        $txRepo,
        $ctx->gate
    );
    $controller = new WorkorderKitInstallController($service);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->get(
            '/api/workorders/{id}/kit-installs',
            function (Request $request) use ($controller) {
                return Response::json($controller->listForWorkorder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->get(
            '/api/workorder-jobs/{id}/kit-installs',
            function (Request $request) use ($controller) {
                return Response::json($controller->listForJob(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->get(
            '/api/workorder-kit-installs/planned',
            function (Request $request) use ($controller) {
                return Response::json($controller->listPlanned(
                    $request->getAttribute('user'),
                    $request->query()
                ));
            }
        );

        $router->get(
            '/api/workorder-kit-installs/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->getInstall(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorders/{id}/kit-installs',
            function (Request $request) use ($controller) {
                return Response::created($controller->plan(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/workorder-kit-installs/{id}/install',
            function (Request $request) use ($controller) {
                return Response::json($controller->install(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/workorder-kit-installs/{id}/cancel',
            function (Request $request) use ($controller) {
                return Response::json($controller->cancel(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/workorder-kit-installs/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->delete(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );
    });
};
