<?php

use App\Services\ServiceRoutes\PhotoVerifier;
use App\Services\ServiceRoutes\RouteStopRepository;
use App\Services\ServiceRoutes\RouteVisitGenerator;
use App\Services\ServiceRoutes\RouteVisitPhotoRepository;
use App\Services\ServiceRoutes\RouteVisitRepository;
use App\Services\ServiceRoutes\RouteVisitService;
use App\Services\ServiceRoutes\ServiceRouteController;
use App\Services\ServiceRoutes\ServiceRouteRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Recurring service-route endpoints — Phase 15 / M7 of
 * docs/woms-expansion-plan.md.
 *
 * Read perm:    service_routes.view
 * Write perm:   service_routes.manage    (route + stop definitions, generator
 *                                          trigger, dispatcher reassign)
 * Execute perm: service_routes.execute   (mobile state transitions, photo
 *                                          recording, QR scan)
 *
 * Endpoint groups:
 *   /api/service-routes                          — route CRUD
 *   /api/service-routes/{id}/stops               — stop list/create
 *   /api/service-routes/{id}/stops/reorder       — drag-to-reorder bulk write
 *   /api/service-routes/{id}/generate            — manual trigger of generator
 *   /api/route-stops/{id}                        — stop update/delete
 *   /api/route-visits                            — visit list (filter by date,
 *                                                   route, stop, status, etc.)
 *   /api/route-visits/{id}                       — visit detail + photos
 *   /api/route-visits/{id}/transition            — state machine (en_route,
 *                                                   arrived, completed,
 *                                                   skipped, missed)
 *   /api/route-visits/{id}/reassign              — dispatcher reassign
 *   /api/route-visits/{id}/photos                — list/upload visit photos
 *   /api/route-visit-photos/{id}                 — delete a photo
 *   /api/route-visits/scan/{token}               — QR-token scan resolver
 *
 * Response envelope: { data: <controller payload> } — same as other modules.
 */
return function (Router $router, RouteContext $ctx): void {
    $routes = new ServiceRouteRepository($ctx->connection);
    $stops = new RouteStopRepository($ctx->connection);
    $visits = new RouteVisitRepository($ctx->connection);
    $photos = new RouteVisitPhotoRepository($ctx->connection);
    $verifier = new PhotoVerifier(
        $ctx->connection,
        $visits,
        $photos,
        $stops,
        $routes,
    );
    $visitService = new RouteVisitService(
        $ctx->connection,
        $routes,
        $stops,
        $visits,
        $photos,
        $ctx->auditLogger,
        $verifier,
    );
    $generator = new RouteVisitGenerator($routes, $stops, $visits);
    $controller = new ServiceRouteController(
        $routes,
        $stops,
        $visits,
        $photos,
        $visitService,
        $generator,
        $ctx->gate,
        $verifier,
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {

        // ---- routes -------------------------------------------------------
        $router->get('/api/service-routes', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'status' => $request->queryParam('status'),
                'service_line_id' => $request->queryParam('service_line_id'),
                'default_assigned_user_id' => $request->queryParam('default_assigned_user_id'),
                'recurrence_type' => $request->queryParam('recurrence_type'),
                'search' => $request->queryParam('search'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 50);

            return Response::json([
                'data' => $controller->index(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/service-routes/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/service-routes', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->store(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/service-routes/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->delete('/api/service-routes/{id}', function (Request $request) use ($controller) {
            $controller->destroy(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        $router->post('/api/service-routes/{id}/generate', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->runGenerator(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        // ---- stops --------------------------------------------------------
        $router->get('/api/service-routes/{id}/stops', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->listStops(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/service-routes/{id}/stops', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->createStop(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->post('/api/service-routes/{id}/stops/reorder', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->reorderStops(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/route-stops/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->updateStop(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->delete('/api/route-stops/{id}', function (Request $request) use ($controller) {
            $controller->deleteStop(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // ---- visits -------------------------------------------------------
        $router->get('/api/route-visits', function (Request $request) use ($controller) {
            $filters = [
                'service_route_id' => $request->queryParam('service_route_id'),
                'route_stop_id' => $request->queryParam('route_stop_id'),
                'workorder_id' => $request->queryParam('workorder_id'),
                'assigned_user_id' => $request->queryParam('assigned_user_id'),
                'customer_id' => $request->queryParam('customer_id'),
                'site_id' => $request->queryParam('site_id'),
                'status' => $request->queryParam('status'),
                'scheduled_from' => $request->queryParam('scheduled_from'),
                'scheduled_to' => $request->queryParam('scheduled_to'),
            ];
            $statuses = $request->queryParam('statuses');
            if (is_string($statuses) && $statuses !== '') {
                $filters['statuses'] = array_filter(array_map('trim', explode(',', $statuses)));
            }
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 100);

            return Response::json([
                'data' => $controller->listVisits(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== '' && $v !== []),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/route-visits/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->showVisit(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/route-visits/{id}/transition', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->transitionVisit(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->post('/api/route-visits/{id}/reassign', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->reassignVisit(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        // ---- QR scan ------------------------------------------------------
        $router->get('/api/route-visits/scan/{token}', function (Request $request) use ($controller) {
            $token = (string) $request->getAttribute('token');
            $resolved = $controller->scan($request->getAttribute('user'), $token);
            if ($resolved === null) {
                return Response::notFound('route_visit not found for token');
            }
            return Response::json(['data' => $resolved]);
        });

        // ---- photos -------------------------------------------------------
        $router->get('/api/route-visits/{id}/photos', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->listPhotos(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/route-visits/{id}/photos', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->recordPhoto(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        // Multipart variant for the mobile/PWA flow — accepts a `media` file
        // upload and stores it under public/uploads/route-visits/.
        $router->post('/api/route-visits/{id}/photos/upload', function (Request $request) use ($controller) {
            $file = $request->file('media') ?? [];
            return Response::created([
                'data' => $controller->uploadPhoto(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    is_array($file) ? $file : [],
                    $request->body()
                ),
            ]);
        });

        $router->delete('/api/route-visit-photos/{id}', function (Request $request) use ($controller) {
            $controller->deletePhoto(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });
    });
};
