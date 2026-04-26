<?php

use App\Services\CustomFields\CustomFieldController;
use App\Services\CustomFields\CustomFieldRepository;
use App\Services\CustomFields\CustomFieldService;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Custom fields engine — Phase 0.5 of docs/expansion-plan.md.
 *
 * Definitions describe per-(division, entity_type) field schemas; values
 * store the captured data keyed by (entity_type, entity_id, definition_id).
 * Writes require custom_fields.manage; reads require custom_fields.view.
 */
return function (Router $router, RouteContext $ctx): void {
    $controller = new CustomFieldController(
        new CustomFieldService(new CustomFieldRepository($ctx->connection)),
        $ctx->gate
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // Definitions
        $router->get('/api/custom-fields/definitions', function (Request $request) use ($controller) {
            $entityType = (string) $request->queryParam('entity_type', '');
            $divisionId = $request->queryParam('division_id');
            $includeInactive = (bool) $request->queryParam('include_inactive', false);

            return Response::json($controller->listDefinitions(
                $request->getAttribute('user'),
                $entityType,
                $divisionId !== null ? (int) $divisionId : null,
                $includeInactive
            ));
        });

        $router->post('/api/custom-fields/definitions', function (Request $request) use ($controller) {
            return Response::created($controller->createDefinition(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/custom-fields/definitions/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateDefinition(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/custom-fields/definitions/{id}', function (Request $request) use ($controller) {
            $controller->deleteDefinition(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Values
        $router->get('/api/custom-fields/values', function (Request $request) use ($controller) {
            $entityType = (string) $request->queryParam('entity_type', '');
            $entityId = (int) $request->queryParam('entity_id', 0);
            $divisionId = $request->queryParam('division_id');

            return Response::json($controller->getValues(
                $request->getAttribute('user'),
                $entityType,
                $entityId,
                $divisionId !== null ? (int) $divisionId : null
            ));
        });

        $router->put('/api/custom-fields/values', function (Request $request) use ($controller) {
            return Response::json($controller->saveValues(
                $request->getAttribute('user'),
                $request->body()
            ));
        });
    });
};
