<?php

use App\Services\Assets\AssetController;
use App\Services\Assets\AssetDocumentRepository;
use App\Services\Assets\AssetLifecycleService;
use App\Services\Assets\AssetLinkRepository;
use App\Services\Assets\AssetQrService;
use App\Services\Assets\AssetTypeRepository;
use App\Services\Assets\SiteAssetRepository;
use App\Services\CapitalPlan\CapitalScoringModelRepository;
use App\Services\Crm\SiteRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Installed-asset endpoints (Phase 2.1 of docs/expansion-plan.md).
 *
 * Read perms: assets.view / asset_types.view
 * Write perms: assets.manage / asset_types.manage
 */
return function (Router $router, RouteContext $ctx): void {
    // Asset documents land under storage/private so they aren't web-accessible.
    // The asset controller rebuilds the full path from the row's relative
    // storage_path + this root.
    $documentStoragePath = dirname(__DIR__, 2) . '/storage/private/asset-documents';
    $siteAssetRepo = new SiteAssetRepository($ctx->connection);
    // QR payload URL base — the sticker text resolves to {APP_URL}/api/qr/scan/{token}.
    $publicBaseUrl = (string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost');
    $qrService = new AssetQrService($siteAssetRepo, $publicBaseUrl);
    $lifecycleService = new AssetLifecycleService($siteAssetRepo);

    $controller = new AssetController(
        new AssetTypeRepository($ctx->connection),
        $siteAssetRepo,
        new AssetLinkRepository($ctx->connection),
        new AssetDocumentRepository($ctx->connection),
        new SiteRepository($ctx->connection),
        $ctx->gate,
        $ctx->auditLogger,
        $documentStoragePath,
        $qrService,
        $lifecycleService,
        // Phase 9.2 — per-site lifecycle now resolves the division's tunable
        // scoring model so categorization stays consistent with the aging
        // report (Phase 9.1).
        new CapitalScoringModelRepository($ctx->connection),
    );

    // Public scan endpoint — deliberately outside the auth group so a field
    // scan works without a login. The response is intentionally narrow.
    $router->get('/api/qr/scan/{token}', function (Request $request) use ($controller) {
        return Response::json($controller->publicScanQr((string) $request->getAttribute('token')));
    });

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // Asset types catalog
        $router->get('/api/asset-types', function (Request $request) use ($controller) {
            $divisionId = $request->queryParam('division_id');
            return Response::json($controller->listAssetTypes(
                $request->getAttribute('user'),
                $divisionId !== null ? (int) $divisionId : null,
                (bool) $request->queryParam('include_inactive', false)
            ));
        });

        $router->post('/api/asset-types', function (Request $request) use ($controller) {
            return Response::created($controller->createAssetType(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/asset-types/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateAssetType(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/asset-types/{id}', function (Request $request) use ($controller) {
            $controller->deleteAssetType(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Site assets
        // Phase 2.5 of docs/expansion-plan.md: per-site lifecycle roll-up.
        $router->get('/api/sites/{id}/assets/lifecycle', function (Request $request) use ($controller) {
            return Response::json($controller->listSiteAssetLifecycle(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->get('/api/sites/{id}/assets', function (Request $request) use ($controller) {
            return Response::json($controller->listAssetsForSite(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                [
                    'asset_type_id' => $request->queryParam('asset_type_id'),
                    'service_line_id' => $request->queryParam('service_line_id'),
                    'status' => $request->queryParam('status'),
                    'parent_asset_id' => $request->queryParam('parent_asset_id'),
                    'query' => $request->queryParam('query'),
                    // Phase 2.4 — floorplan + network filters.
                    'building' => $request->queryParam('building'),
                    'floor' => $request->queryParam('floor'),
                    'room' => $request->queryParam('room'),
                    'rack' => $request->queryParam('rack'),
                    'ip_address' => $request->queryParam('ip_address'),
                    'mac_address' => $request->queryParam('mac_address'),
                    'vlan' => $request->queryParam('vlan'),
                    'limit' => $request->queryParam('limit', 100),
                    'offset' => $request->queryParam('offset', 0),
                ]
            ));
        });

        // Cross-site asset search — used by the SubjectPicker on transactional
        // forms. Filters mirror listAssetsForSite but site_id is optional.
        $router->get('/api/assets', function (Request $request) use ($controller) {
            return Response::json($controller->searchAssets(
                $request->getAttribute('user'),
                [
                    'site_id' => $request->queryParam('site_id'),
                    'service_line_id' => $request->queryParam('service_line_id'),
                    'asset_type_id' => $request->queryParam('asset_type_id'),
                    'status' => $request->queryParam('status'),
                    'query' => $request->queryParam('query'),
                    'building' => $request->queryParam('building'),
                    'floor' => $request->queryParam('floor'),
                    'room' => $request->queryParam('room'),
                    'rack' => $request->queryParam('rack'),
                    'ip_address' => $request->queryParam('ip_address'),
                    'mac_address' => $request->queryParam('mac_address'),
                    'vlan' => $request->queryParam('vlan'),
                    'limit' => $request->queryParam('limit', 25),
                    'offset' => $request->queryParam('offset', 0),
                ]
            ));
        });

        $router->get('/api/assets/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getAsset(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/assets', function (Request $request) use ($controller) {
            return Response::created($controller->createAsset(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/assets/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateAsset(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/assets/{id}', function (Request $request) use ($controller) {
            $controller->deleteAsset(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Asset links (polymorphic WO/inspection/contract/etc)
        $router->get('/api/assets/{id}/links', function (Request $request) use ($controller) {
            return Response::json($controller->listAssetLinks(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/assets/{id}/links', function (Request $request) use ($controller) {
            return Response::created($controller->createAssetLink(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/asset-links/{id}', function (Request $request) use ($controller) {
            $controller->deleteAssetLink(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Asset documents (Phase 2.2 of docs/expansion-plan.md). Upload endpoint
        // expects multipart with a `file` field and optional doc_type/notes.
        $router->get('/api/assets/{id}/documents', function (Request $request) use ($controller) {
            return Response::json($controller->listAssetDocuments(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->queryParam('doc_type')
            ));
        });

        $router->post('/api/assets/{id}/documents', function (Request $request) use ($controller) {
            return Response::created($controller->uploadAssetDocument(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->uploadedFiles(),
                $request->body()
            ));
        });

        $router->delete('/api/asset-documents/{id}', function (Request $request) use ($controller) {
            $controller->deleteAssetDocument(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Phase 2.3 of docs/expansion-plan.md: authenticated QR render. The
        // PNG encodes the public /api/qr/scan/{token} URL — printing it on a
        // sticker turns any camera into a read-only field lookup.
        $router->get('/api/assets/{id}/qr.png', function (Request $request) use ($controller) {
            $scale = (int) $request->queryParam('scale', 8);
            $png = $controller->generateAssetQr(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $scale
            );
            return Response::make($png, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => (string) strlen($png),
                'Cache-Control' => 'private, max-age=0',
            ]);
        });
    });
};
