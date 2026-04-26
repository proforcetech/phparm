<?php

use App\Services\Settings\ModuleSettingsController;
use App\Services\UserGroup\UserGroupController;
use App\Services\UserGroup\UserGroupService;
use App\Support\Auth\ModuleAccessService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Module settings & user groups.
 * Migrated from routes/api.php (lines 10072-10201) as part of Phase 0.1.
 */
return function (Router $router, RouteContext $ctx): void {
    // Accessible modules (any authenticated user) — registered before admin
    // routes so that the {key} pattern below does not intercept /accessible.
    $router->group([Middleware::auth()], function (Router $router) use ($ctx) {
        $router->get('/api/modules/accessible', function (Request $request) use ($ctx) {
            $user = $request->getAttribute('user');
            $moduleService = new ModuleAccessService($ctx->connection, $ctx->gate);
            $controller = new ModuleSettingsController($moduleService, $ctx->gate);

            return Response::json($controller->accessible($user));
        });
    });

    $router->group([Middleware::auth(), Middleware::role('admin')], function (Router $router) use ($ctx) {
        $moduleService = new ModuleAccessService($ctx->connection, $ctx->gate);
        $moduleController = new ModuleSettingsController($moduleService, $ctx->gate);
        $userGroupService = new UserGroupService($ctx->connection);
        $userGroupController = new UserGroupController($userGroupService, $ctx->gate);

        // Module settings
        $router->get('/api/modules', function (Request $request) use ($moduleController) {
            return Response::json($moduleController->index($request->getAttribute('user')));
        });

        $router->get('/api/modules/{key}', function (Request $request) use ($moduleController) {
            return Response::json($moduleController->show(
                $request->getAttribute('user'),
                (string) $request->getAttribute('key')
            ));
        });

        $router->put('/api/modules/{key}', function (Request $request) use ($moduleController) {
            return Response::json($moduleController->update(
                $request->getAttribute('user'),
                (string) $request->getAttribute('key'),
                $request->body()
            ));
        });

        $router->put('/api/modules', function (Request $request) use ($moduleController) {
            return Response::json($moduleController->bulkUpdate($request->getAttribute('user'), $request->body()));
        });

        // User groups
        $router->get('/api/user-groups', function (Request $request) use ($userGroupController) {
            return Response::json(['data' => $userGroupController->index($request->getAttribute('user'))]);
        });

        $router->post('/api/user-groups', function (Request $request) use ($userGroupController) {
            return Response::created($userGroupController->store($request->getAttribute('user'), $request->body()));
        });

        $router->get('/api/user-groups/{id}', function (Request $request) use ($userGroupController) {
            return Response::json($userGroupController->show(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->put('/api/user-groups/{id}', function (Request $request) use ($userGroupController) {
            return Response::json($userGroupController->update(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/user-groups/{id}', function (Request $request) use ($userGroupController) {
            $userGroupController->destroy(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );

            return Response::noContent();
        });

        // User group members
        $router->get('/api/user-groups/{id}/members', function (Request $request) use ($userGroupController) {
            return Response::json(['data' => $userGroupController->members(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            )]);
        });

        $router->post('/api/user-groups/{id}/members', function (Request $request) use ($userGroupController) {
            $body = $request->body();
            $userGroupController->addMember(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                (int) ($body['user_id'] ?? 0)
            );

            return Response::json(['success' => true]);
        });

        $router->delete('/api/user-groups/{groupId}/members/{userId}', function (Request $request) use ($userGroupController) {
            $userGroupController->removeMember(
                $request->getAttribute('user'),
                (int) $request->getAttribute('groupId'),
                (int) $request->getAttribute('userId')
            );

            return Response::noContent();
        });

        $router->put('/api/user-groups/{id}/members', function (Request $request) use ($userGroupController) {
            $body = $request->body();
            $userGroupController->setMembers(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $body['user_ids'] ?? []
            );

            return Response::json(['success' => true]);
        });

        $router->get('/api/user-groups/{id}/non-members', function (Request $request) use ($userGroupController) {
            return Response::json(['data' => $userGroupController->nonMembers(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->queryParam('search')
            )]);
        });

        // A user's groups
        $router->get('/api/users/{id}/groups', function (Request $request) use ($userGroupController) {
            return Response::json(['data' => $userGroupController->userGroups(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            )]);
        });

        $router->put('/api/users/{id}/groups', function (Request $request) use ($userGroupController) {
            $body = $request->body();
            $userGroupController->setUserGroups(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $body['group_ids'] ?? []
            );

            return Response::json(['success' => true]);
        });
    });
};
