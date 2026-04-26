<?php

use App\Services\Retention\RetentionController;
use App\Services\Retention\RetentionPolicyRepository;
use App\Services\Retention\RetentionRunRepository;
use App\Services\Retention\RetentionRunner;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Data retention policy + run management endpoints.
 *
 * Permissions:
 *   retention.view    list/show policies + recent runs
 *   retention.manage  create/update/delete policies
 *   retention.run     execute (or dry-run) a policy on demand
 */
return function (Router $router, RouteContext $ctx): void {
    $policyRepo = new RetentionPolicyRepository($ctx->connection);
    $runRepo = new RetentionRunRepository($ctx->connection);
    $runner = new RetentionRunner($ctx->connection, $policyRepo, $runRepo, $ctx->gate);
    $controller = new RetentionController($policyRepo, $runRepo, $runner, $ctx->gate);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->get(
            '/api/retention/policies',
            function (Request $request) use ($controller) {
                return Response::json($controller->listPolicies($request->getAttribute('user')));
            }
        );

        $router->get(
            '/api/retention/policies/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->getPolicy(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/retention/policies',
            function (Request $request) use ($controller) {
                return Response::created($controller->createPolicy(
                    $request->getAttribute('user'),
                    $request->body()
                ));
            }
        );

        $router->put(
            '/api/retention/policies/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->updatePolicy(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/retention/policies/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->deletePolicy(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/retention/policies/{id}/run',
            function (Request $request) use ($controller) {
                return Response::json($controller->runPolicy(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/retention/run-all',
            function (Request $request) use ($controller) {
                return Response::json($controller->runAll(
                    $request->getAttribute('user'),
                    $request->body()
                ));
            }
        );

        $router->get(
            '/api/retention/runs',
            function (Request $request) use ($controller) {
                return Response::json($controller->listRuns(
                    $request->getAttribute('user'),
                    $request->query()
                ));
            }
        );
    });
};
