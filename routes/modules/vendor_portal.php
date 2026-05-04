<?php

use App\Services\Portal\PortalUploadStorage;
use App\Services\Procurement\PurchaseOrderDocumentRepository;
use App\Services\Procurement\PurchaseOrderRepository;
use App\Services\Procurement\VendorPortalController;
use App\Services\Procurement\VendorPortalService;
use App\Services\Procurement\VendorPortalTokenRepository;
use App\Services\Procurement\VendorRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Vendor self-service portal endpoints (Phase 18 / C1 of
 * docs/woms-expansion-plan.md).
 *
 * Two surfaces:
 *
 *   1) Staff-side token management — guarded by Middleware::auth() so the
 *      regular JWT/role stack applies. procurement.manage / procurement.view
 *      are checked inside the service.
 *        POST   /api/vendors/{id}/portal-tokens          issue (returns plaintext once)
 *        GET    /api/vendors/{id}/portal-tokens          list
 *        DELETE /api/vendor-portal-tokens/{id}           revoke
 *        GET    /api/purchase-orders/{id}/documents      staff view of upload bundle
 *
 *   2) Vendor-facing self-service — bearer token only, NO staff JWT. The
 *      token identifies the vendor; the po.vendor_id check on every read /
 *      mutation prevents cross-tenant access if a token leaks.
 *        GET    /api/vendor-portal/me
 *        GET    /api/vendor-portal/purchase-orders[?status=...]
 *        GET    /api/vendor-portal/purchase-orders/{id}
 *        POST   /api/vendor-portal/purchase-orders/{id}/acknowledge
 *        POST   /api/vendor-portal/purchase-order-lines/{id}/ship
 *        GET    /api/vendor-portal/purchase-orders/{id}/documents
 *        POST   /api/vendor-portal/purchase-orders/{id}/documents (multipart)
 *        DELETE /api/vendor-portal/documents/{id}
 *
 * Throttle the public surface aggressively (the token IS the auth, so a
 * brute-force sweep is the only realistic abuse vector). 60 req/min/IP,
 * matching the subcontractor portal cadence.
 */
return function (Router $router, RouteContext $ctx): void {
    $vendorRepo = new VendorRepository($ctx->connection);
    $poRepo = new PurchaseOrderRepository($ctx->connection);
    $tokenRepo = new VendorPortalTokenRepository($ctx->connection);
    $docRepo = new PurchaseOrderDocumentRepository($ctx->connection);
    $uploadStorage = new PortalUploadStorage(
        // Vendor portal uploads go under /uploads/vendor-portal so they're
        // segregated on disk from sub-portal and customer-portal uploads,
        // even though they share the same partitioning scheme. Easier to
        // apply per-tenant retention policy.
        rootDir: dirname(__DIR__, 2) . '/public/uploads/vendor-portal',
        publicBaseUrl: '/uploads/vendor-portal',
    );
    $service = new VendorPortalService(
        $vendorRepo,
        $poRepo,
        $tokenRepo,
        $docRepo,
        $uploadStorage,
        $ctx->gate,
    );
    $controller = new VendorPortalController($service);

    // ─────────────────────────────────────── staff-authenticated routes ────

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->post(
            '/api/vendors/{id}/portal-tokens',
            function (Request $request) use ($controller) {
                return Response::created($controller->issueToken(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ));
            }
        );

        $router->get(
            '/api/vendors/{id}/portal-tokens',
            function (Request $request) use ($controller) {
                $includeRevoked = strtolower((string) $request->queryParam('include_revoked', '')) === '1'
                    || strtolower((string) $request->queryParam('include_revoked', '')) === 'true';
                return Response::json($controller->listTokens(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $includeRevoked,
                ));
            }
        );

        $router->delete(
            '/api/vendor-portal-tokens/{id}',
            function (Request $request) use ($controller) {
                $reason = $request->input('reason');
                $controller->revokeToken(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    is_string($reason) && $reason !== '' ? $reason : null,
                );
                return Response::noContent();
            }
        );

        $router->get(
            '/api/purchase-orders/{id}/documents',
            function (Request $request) use ($controller) {
                return Response::json($controller->listPoDocumentsForStaff(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                ));
            }
        );
    });

    // ─────────────────────────────────────── token-authenticated routes ────

    $extractToken = static function (Request $request): ?string {
        $bearer = $request->bearerToken();
        if ($bearer !== null && $bearer !== '') {
            return $bearer;
        }
        $hdr = $request->header('X-VENDOR-PORTAL-TOKEN');
        if ($hdr !== null && $hdr !== '') {
            return $hdr;
        }
        $q = $request->queryParam('token');
        if (is_string($q) && $q !== '') {
            return $q;
        }
        return null;
    };

    $router->group([Middleware::throttle(60, 60)], function (Router $router) use ($service, $controller, $extractToken) {
        $auth = static function (Request $request) use ($service, $extractToken): array {
            $plaintext = $extractToken($request);
            if ($plaintext === null) {
                throw new \App\Support\Auth\UnauthorizedException('Vendor portal token required');
            }
            $resolved = $service->authenticate($plaintext, $request->getClientIp());
            if ($resolved === null) {
                throw new \App\Support\Auth\UnauthorizedException('Invalid or expired token');
            }
            return $resolved;
        };

        $router->get('/api/vendor-portal/me', function (Request $request) use ($auth, $controller) {
            $a = $auth($request);
            return Response::json($controller->me($a['token'], $a['vendor']));
        });

        $router->get('/api/vendor-portal/purchase-orders', function (Request $request) use ($auth, $controller) {
            $a = $auth($request);
            $status = $request->queryParam('status');
            return Response::json($controller->listMyPos(
                $a['token'],
                is_string($status) && $status !== '' ? $status : null,
            ));
        });

        $router->get('/api/vendor-portal/purchase-orders/{id}', function (Request $request) use ($auth, $controller) {
            $a = $auth($request);
            return Response::json($controller->getMyPo(
                $a['token'],
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post(
            '/api/vendor-portal/purchase-orders/{id}/acknowledge',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                return Response::json($controller->acknowledgePo(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                ));
            }
        );

        $router->post(
            '/api/vendor-portal/purchase-order-lines/{id}/ship',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                return Response::json($controller->markLineShipped(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ));
            }
        );

        $router->get(
            '/api/vendor-portal/purchase-orders/{id}/documents',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                return Response::json($controller->listMyDocuments(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                ));
            }
        );

        $router->post(
            '/api/vendor-portal/purchase-orders/{id}/documents',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                $file = $request->file('file');
                if (!is_array($file)) {
                    throw new \InvalidArgumentException('file is required');
                }
                return Response::created($controller->uploadDocument(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                    $file,
                    $request->body(),
                ));
            }
        );

        $router->delete('/api/vendor-portal/documents/{id}', function (Request $request) use ($auth, $controller) {
            $a = $auth($request);
            $controller->deleteOwnDocument($a['token'], (int) $request->getAttribute('id'));
            return Response::noContent();
        });
    });
};
