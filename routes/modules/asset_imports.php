<?php

use App\Services\Assets\AssetImportController;
use App\Services\Assets\AssetImportRepository;
use App\Services\Assets\AssetImportService;
use App\Services\Assets\AssetTypeRepository;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Crm\SiteRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Bulk asset import endpoints (Phase 18 / S12 of docs/woms-expansion-plan.md).
 *
 * Workflow surfaces:
 *   POST   /api/asset-imports                 upload csv → create header
 *   GET    /api/asset-imports                 list recent jobs
 *   GET    /api/asset-imports/{id}            header + status counts
 *   PATCH  /api/asset-imports/{id}            update mapping / defaults
 *   POST   /api/asset-imports/{id}/validate   dry-run, populate parsed_data
 *   POST   /api/asset-imports/{id}/apply      INSERT validated rows
 *   POST   /api/asset-imports/{id}/cancel     mark cancelled
 *   GET    /api/asset-imports/{id}/rows       paginated row list (filter: status)
 *
 * Read perm: assets.view
 * Write perm: assets.manage (enforced inside the service)
 */
return function (Router $router, RouteContext $ctx): void {
    $importRepo = new AssetImportRepository($ctx->connection);
    $assetRepo = new SiteAssetRepository($ctx->connection);
    $typeRepo = new AssetTypeRepository($ctx->connection);
    $siteRepo = new SiteRepository($ctx->connection);

    $service = new AssetImportService(
        $ctx->connection,
        $importRepo,
        $assetRepo,
        $typeRepo,
        $siteRepo,
        $ctx->gate,
        $ctx->auditLogger,
    );
    $controller = new AssetImportController($service);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->post('/api/asset-imports', function (Request $request) use ($controller) {
            $file = $request->file('file');
            $payload = $request->body();
            return Response::created($controller->upload(
                $request->getAttribute('user'),
                is_array($payload) ? $payload : [],
                is_array($file) ? $file : null,
            ));
        });

        $router->get('/api/asset-imports', function (Request $request) use ($controller) {
            $limit = (int) ($request->queryParam('limit', 50));
            return Response::json($controller->listImports(
                $request->getAttribute('user'),
                $limit,
            ));
        });

        $router->get('/api/asset-imports/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getDetail(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->patch('/api/asset-imports/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateMapping(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->post('/api/asset-imports/{id}/validate', function (Request $request) use ($controller) {
            return Response::json($controller->validate(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/asset-imports/{id}/apply', function (Request $request) use ($controller) {
            return Response::json($controller->apply(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/asset-imports/{id}/cancel', function (Request $request) use ($controller) {
            return Response::json($controller->cancel(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->get('/api/asset-imports/{id}/rows', function (Request $request) use ($controller) {
            $status = $request->queryParam('status');
            $limit = (int) ($request->queryParam('limit', 1000));
            $offset = (int) ($request->queryParam('offset', 0));
            return Response::json($controller->listRows(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                is_string($status) && $status !== '' ? $status : null,
                $limit,
                $offset,
            ));
        });
    });
};
