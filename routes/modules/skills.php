<?php

use App\Services\Skills\SkillMatrixController;
use App\Services\Skills\SkillMatrixService;
use App\Services\Skills\SkillRepository;
use App\Services\Skills\UserSkillRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Technician skill matrix endpoints — Phase 17 / S11 of
 * docs/woms-expansion-plan.md.
 *
 * Read perm:  skills.view
 * Write perm: skills.manage
 *
 * Endpoint groups:
 *   /api/skills                                  — catalog list + create
 *   /api/skills/{id}                             — show + update + delete
 *   /api/skills/matrix                           — combined catalog + roster + grid
 *   /api/users/{id}/skills                       — list per-technician
 *   /api/users/{userId}/skills/{skillId}         — grant + revoke single cell
 */
return function (Router $router, RouteContext $ctx): void {
    $skillRepo = new SkillRepository($ctx->connection);
    $userSkillRepo = new UserSkillRepository($ctx->connection);
    $service = new SkillMatrixService(
        $ctx->connection,
        $skillRepo,
        $userSkillRepo,
        $ctx->auditLogger,
    );
    $controller = new SkillMatrixController(
        $ctx->connection,
        $skillRepo,
        $userSkillRepo,
        $service,
        $ctx->gate,
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {

        $router->get('/api/skills/matrix', function (Request $request) use ($controller) {
            $filters = [
                'role' => $request->queryParam('role'),
                'search' => $request->queryParam('search'),
                'service_line_id' => $request->queryParam('service_line_id'),
                'primary_service_line_id' => $request->queryParam('primary_service_line_id'),
            ];
            return Response::json([
                'data' => $controller->matrix(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== '')
                ),
            ]);
        });

        $router->get('/api/skills', function (Request $request) use ($controller) {
            $filters = [
                'service_line_id' => $request->queryParam('service_line_id'),
                'category' => $request->queryParam('category'),
                'is_active' => $request->queryParam('is_active'),
                'search' => $request->queryParam('search'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 250);

            return Response::json([
                'data' => $controller->index(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/skills/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/skills', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->store(
                    $request->getAttribute('user'),
                    $request->body()
                ),
            ]);
        });

        $router->put('/api/skills/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ),
            ]);
        });

        $router->delete('/api/skills/{id}', function (Request $request) use ($controller) {
            $controller->destroy(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        $router->get('/api/users/{id}/skills', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->listForUser(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->put('/api/users/{userId}/skills/{skillId}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->grant(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('userId'),
                    (int) $request->getAttribute('skillId'),
                    $request->body()
                ),
            ]);
        });

        $router->delete('/api/users/{userId}/skills/{skillId}', function (Request $request) use ($controller) {
            $controller->revoke(
                $request->getAttribute('user'),
                (int) $request->getAttribute('userId'),
                (int) $request->getAttribute('skillId')
            );
            return Response::noContent();
        });
    });
};
