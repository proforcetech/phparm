<?php

use App\Services\Portal\PortalUploadStorage;
use App\Services\Subcontractor\SubcontractorAssignmentPodRepository;
use App\Services\Subcontractor\SubcontractorPortalController;
use App\Services\Subcontractor\SubcontractorPortalService;
use App\Services\Subcontractor\SubcontractorPortalTokenRepository;
use App\Services\Subcontractor\SubcontractorRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Subcontractor self-service portal endpoints (Phase 18 / C2 of
 * docs/woms-expansion-plan.md).
 *
 * Two surfaces:
 *
 *   1) Staff-side token management — guarded by Middleware::auth() so the
 *      regular JWT/role stack applies. subcontractors.manage is checked
 *      inside the service.
 *        POST   /api/subcontractors/{id}/portal-tokens     issue (returns plaintext once)
 *        GET    /api/subcontractors/{id}/portal-tokens     list
 *        DELETE /api/subcontractor-portal-tokens/{id}      revoke
 *        GET    /api/subcontractor-assignments/{id}/pods   staff view of POD bundle
 *
 *   2) Sub-facing self-service — bearer token only, NO staff JWT. The token
 *      identifies the sub; the assignment.subcontractor_id check on every
 *      mutation prevents cross-tenant access if a token leaks.
 *        GET    /api/sub-portal/me
 *        GET    /api/sub-portal/assignments[?status=...]
 *        GET    /api/sub-portal/assignments/{id}
 *        POST   /api/sub-portal/assignments/{id}/accept
 *        POST   /api/sub-portal/assignments/{id}/decline
 *        POST   /api/sub-portal/assignments/{id}/start
 *        POST   /api/sub-portal/assignments/{id}/complete  (+ optional cost/notes body)
 *        PATCH  /api/sub-portal/assignments/{id}           (cost / description)
 *        GET    /api/sub-portal/assignments/{id}/pods
 *        POST   /api/sub-portal/assignments/{id}/pods      (multipart file upload)
 *        POST   /api/sub-portal/assignments/{id}/notes     (text-only POD entry)
 *        DELETE /api/sub-portal/pods/{id}
 *
 * Throttle the public surface aggressively (the token IS the auth, so a
 * brute-force sweep is the only realistic abuse vector). 60 req/min/IP.
 */
return function (Router $router, RouteContext $ctx): void {
    $subRepo = new SubcontractorRepository($ctx->connection);
    $tokenRepo = new SubcontractorPortalTokenRepository($ctx->connection);
    $podRepo = new SubcontractorAssignmentPodRepository($ctx->connection);
    $uploadStorage = new PortalUploadStorage(
        // Sub portal uploads go under /uploads/sub-portal so they're segregated
        // on disk from customer-portal uploads, even though both use the same
        // partitioning scheme. Easier to apply per-tenant retention policy.
        rootDir: dirname(__DIR__, 2) . '/public/uploads/sub-portal',
        publicBaseUrl: '/uploads/sub-portal',
    );
    $service = new SubcontractorPortalService(
        $subRepo,
        $tokenRepo,
        $podRepo,
        $uploadStorage,
        $ctx->gate,
    );
    $controller = new SubcontractorPortalController($service);

    // ─────────────────────────────────────── staff-authenticated routes ────

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->post(
            '/api/subcontractors/{id}/portal-tokens',
            function (Request $request) use ($controller) {
                return Response::created($controller->issueToken(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ));
            }
        );

        $router->get(
            '/api/subcontractors/{id}/portal-tokens',
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
            '/api/subcontractor-portal-tokens/{id}',
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
            '/api/subcontractor-assignments/{id}/pods',
            function (Request $request) use ($controller) {
                return Response::json($controller->listAssignmentPodsForStaff(
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
        $hdr = $request->header('X-SUB-PORTAL-TOKEN');
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
                throw new \App\Support\Auth\UnauthorizedException('Subcontractor portal token required');
            }
            $resolved = $service->authenticate($plaintext, $request->getClientIp());
            if ($resolved === null) {
                throw new \App\Support\Auth\UnauthorizedException('Invalid or expired token');
            }
            return $resolved;
        };

        $router->get('/api/sub-portal/me', function (Request $request) use ($auth, $controller) {
            $a = $auth($request);
            return Response::json($controller->me($a['token'], $a['subcontractor']));
        });

        $router->get('/api/sub-portal/assignments', function (Request $request) use ($auth, $controller) {
            $a = $auth($request);
            $status = $request->queryParam('status');
            return Response::json($controller->listMyAssignments(
                $a['token'],
                is_string($status) && $status !== '' ? $status : null,
            ));
        });

        $router->get('/api/sub-portal/assignments/{id}', function (Request $request) use ($auth, $controller) {
            $a = $auth($request);
            return Response::json($controller->getMyAssignment(
                $a['token'],
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post(
            '/api/sub-portal/assignments/{id}/accept',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                return Response::json($controller->actOnAssignment(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                    'accept',
                    $request->body(),
                ));
            }
        );

        $router->post(
            '/api/sub-portal/assignments/{id}/decline',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                return Response::json($controller->actOnAssignment(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                    'decline',
                    $request->body(),
                ));
            }
        );

        $router->post(
            '/api/sub-portal/assignments/{id}/start',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                return Response::json($controller->actOnAssignment(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                    'start',
                    $request->body(),
                ));
            }
        );

        $router->post(
            '/api/sub-portal/assignments/{id}/complete',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                return Response::json($controller->actOnAssignment(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                    'complete',
                    $request->body(),
                ));
            }
        );

        $router->patch(
            '/api/sub-portal/assignments/{id}',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                return Response::json($controller->updateMyAssignment(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ));
            }
        );

        $router->get(
            '/api/sub-portal/assignments/{id}/pods',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                return Response::json($controller->listMyPods(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                ));
            }
        );

        $router->post(
            '/api/sub-portal/assignments/{id}/pods',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                $file = $request->file('file');
                if (!is_array($file)) {
                    throw new \InvalidArgumentException('file is required');
                }
                return Response::created($controller->uploadPod(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                    $file,
                    $request->body(),
                ));
            }
        );

        $router->post(
            '/api/sub-portal/assignments/{id}/notes',
            function (Request $request) use ($auth, $controller) {
                $a = $auth($request);
                return Response::created($controller->addNote(
                    $a['token'],
                    (int) $request->getAttribute('id'),
                    $request->body(),
                ));
            }
        );

        $router->delete('/api/sub-portal/pods/{id}', function (Request $request) use ($auth, $controller) {
            $a = $auth($request);
            $controller->deleteOwnPod($a['token'], (int) $request->getAttribute('id'));
            return Response::noContent();
        });
    });
};
