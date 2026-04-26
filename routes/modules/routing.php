<?php

use App\Services\Routing\GeoFenceEvaluator;
use App\Services\Routing\GeoFenceEventRepository;
use App\Services\Routing\GeoFenceEventService;
use App\Services\Routing\GeoFenceRepository;
use App\Services\Routing\GeoFenceService;
use App\Services\Routing\NearestNeighborRouteOptimizer;
use App\Services\Routing\RoutePlanRepository;
use App\Services\Routing\RoutePlanService;
use App\Services\Routing\RoutingController;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Routing endpoints (Phase 10.6) — geo-fences, fence events, route plans.
 *
 * Read perm:    geofences.view / route_plans.view
 * Manage perm:  geofences.manage / route_plans.manage
 * Record perm:  geofences.record_event   (mobile clients posting fixes,
 *                                         dispatch backfilling)
 * Execute perm: route_plans.execute      (technician arrive/depart/skip
 *                                         on stops without needing the
 *                                         dispatcher's full manage perm)
 *
 * Optimizer is bound to NearestNeighborRouteOptimizer by default. To swap
 * in an OSRM/or-tools-backed optimizer, replace the binding below.
 */
return function (Router $router, RouteContext $ctx): void {
    $evaluator = new GeoFenceEvaluator();
    $fenceRepo = new GeoFenceRepository($ctx->connection);
    $eventRepo = new GeoFenceEventRepository($ctx->connection);
    $planRepo = new RoutePlanRepository($ctx->connection);
    $optimizer = new NearestNeighborRouteOptimizer($evaluator);

    $fenceService = new GeoFenceService($fenceRepo, $ctx->gate);
    $eventService = new GeoFenceEventService($eventRepo, $fenceRepo, $evaluator, $ctx->gate);
    $planService = new RoutePlanService($planRepo, $optimizer, $ctx->gate);

    $controller = new RoutingController($fenceService, $eventService, $planService);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // ── geo_fences ──────────────────────────────────────────
        $router->get('/api/geo-fences', function (Request $request) use ($controller) {
            return Response::json($controller->listFences(
                $request->getAttribute('user'),
                $request->query()
            ));
        });

        $router->get('/api/geo-fences/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getFence(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/geo-fences', function (Request $request) use ($controller) {
            return Response::created($controller->createFence(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/geo-fences/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateFence(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/geo-fences/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->deleteFence(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        // ── geo_fence_events ────────────────────────────────────
        $router->get('/api/geo-fence-events', function (Request $request) use ($controller) {
            return Response::json($controller->listEvents(
                $request->getAttribute('user'),
                $request->query()
            ));
        });

        $router->post('/api/geo-fence-events', function (Request $request) use ($controller) {
            return Response::created($controller->recordExplicit(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->post('/api/geo-fence-events/from-position', function (Request $request) use ($controller) {
            return Response::created($controller->recordPosition(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->post('/api/geo-fence-events/evaluate', function (Request $request) use ($controller) {
            return Response::json($controller->evaluatePosition(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        // ── route_plans ─────────────────────────────────────────
        $router->get('/api/route-plans', function (Request $request) use ($controller) {
            return Response::json($controller->listPlans(
                $request->getAttribute('user'),
                $request->query()
            ));
        });

        $router->get('/api/route-plans/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getPlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/route-plans', function (Request $request) use ($controller) {
            return Response::created($controller->createPlan(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/route-plans/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updatePlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/route-plans/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->deletePlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/route-plans/{id}/activate', function (Request $request) use ($controller) {
            return Response::json($controller->activatePlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/route-plans/{id}/complete', function (Request $request) use ($controller) {
            return Response::json($controller->completePlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/route-plans/{id}/cancel', function (Request $request) use ($controller) {
            return Response::json($controller->cancelPlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/route-plans/{id}/optimize', function (Request $request) use ($controller) {
            return Response::json($controller->optimizePlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        // ── stops ───────────────────────────────────────────────
        $router->get('/api/route-plans/{id}/stops', function (Request $request) use ($controller) {
            return Response::json($controller->listStops(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/route-plans/{id}/stops', function (Request $request) use ($controller) {
            return Response::created($controller->addStop(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->get('/api/route-plan-stops/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getStop(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->put('/api/route-plan-stops/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateStop(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/route-plan-stops/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->deleteStop(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/route-plan-stops/{id}/en-route', function (Request $request) use ($controller) {
            return Response::json($controller->markStopEnRoute(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/route-plan-stops/{id}/arrived', function (Request $request) use ($controller) {
            return Response::json($controller->markStopArrived(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/route-plan-stops/{id}/completed', function (Request $request) use ($controller) {
            return Response::json($controller->markStopCompleted(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/route-plan-stops/{id}/skipped', function (Request $request) use ($controller) {
            return Response::json($controller->markStopSkipped(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });
    });
};
