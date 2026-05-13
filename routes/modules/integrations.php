<?php

use App\Services\Integrations\IntegrationController;
use App\Services\Integrations\IntegrationService;
use App\Services\Integrations\IntegrationSyncLogRepository;
use App\Services\Integrations\IntegrationWebhookEventRepository;
use App\Services\Integrations\IntegrationWebhookService;
use App\Services\Integrations\ThirdParty\AccessControlAdapter;
use App\Services\Integrations\ThirdParty\GenericTelematicsAdapter;
use App\Services\Integrations\ThirdParty\GoogleMapsAdapter;
use App\Services\Integrations\ThirdParty\IntegrationAdapterRegistry;
use App\Services\Integrations\ThirdParty\MapboxAdapter;
use App\Services\Integrations\ThirdParty\QuickBooksOnlineAdapter;
use App\Services\Integrations\ThirdParty\TelecomMonitoringAdapter;
use App\Services\Integrations\ThirdParty\XeroAdapter;
use App\Services\Integrations\ThirdPartyIntegrationRepository;
use App\Support\Crypto\FieldCipher;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Cross-cutting third-party integrations.
 *
 * Authenticated catalog + management:
 *   GET    /api/integrations/providers           list available adapters
 *   GET    /api/integrations/providers/{key}     describe one adapter
 *   GET    /api/integrations                     list registered integrations
 *   POST   /api/integrations                     register a new connection
 *   GET    /api/integrations/{id}                get one
 *   PUT    /api/integrations/{id}                update settings/credentials
 *   POST   /api/integrations/{id}/test           test connection
 *   POST   /api/integrations/{id}/sync           run a sync now
 *   POST   /api/integrations/{id}/disconnect     mark disabled, leaves row
 *   DELETE /api/integrations/{id}                remove the row entirely
 *   GET    /api/integrations/{id}/logs           sync history
 *   GET    /api/integrations/{id}/webhooks       webhook receipt log
 *
 * Public webhook receipt (no auth — providers can't carry our JWT):
 *   POST   /api/integrations/webhooks/{provider} receive an inbound webhook
 *
 * Adapter registration is centralized here. New providers: add the
 * class under src/Services/Integrations/ThirdParty/, instantiate it,
 * and call $registry->register(...).
 */
return function (Router $router, RouteContext $ctx): void {
    $registry = new IntegrationAdapterRegistry();
    $registry->register(new QuickBooksOnlineAdapter());
    $registry->register(new XeroAdapter());
    $registry->register(new GoogleMapsAdapter());
    $registry->register(new MapboxAdapter());
    $registry->register(new GenericTelematicsAdapter());
    $registry->register(new TelecomMonitoringAdapter());
    $registry->register(new AccessControlAdapter());

    $repo = new ThirdPartyIntegrationRepository($ctx->connection);
    $logs = new IntegrationSyncLogRepository($ctx->connection);
    $events = new IntegrationWebhookEventRepository($ctx->connection);
    // R-06 / AUD-071 — third-party integration credentials live under
    // their own domain key (INTEGRATION_CREDENTIALS_ENCRYPTION_KEY) so a
    // leak of the CRM site-codes key does not expose API tokens.
    $cipher = new FieldCipher(FieldCipher::DOMAIN_INTEGRATION_CREDENTIALS);
    $service = new IntegrationService($repo, $logs, $registry, $cipher, $ctx->gate);
    $webhooks = new IntegrationWebhookService($events, $repo, $registry, $ctx->gate);
    $controller = new IntegrationController($service, $webhooks, $registry, $repo, $ctx->gate);

    // Public webhook endpoint — no auth middleware. Provider posts here
    // and signs with HMAC; signature verification is the adapter's job
    // once it processes the row.
    $router->post(
        '/api/integrations/webhooks/{provider}',
        function (Request $request) use ($controller) {
            $headers = [];
            foreach (['X-Event-Type', 'X-Event-Id', 'X-Signature', 'User-Agent', 'Content-Type'] as $h) {
                $v = $request->header($h);
                if ($v !== null) {
                    $headers[$h] = $v;
                }
            }
            return Response::json($controller->receiveWebhook(
                (string) $request->getAttribute('provider'),
                $request->rawBody(),
                $headers
            ), 202);
        }
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        $router->get(
            '/api/integrations/providers',
            function (Request $request) use ($controller) {
                return Response::json($controller->listProviders($request->getAttribute('user')));
            }
        );

        $router->get(
            '/api/integrations/providers/{key}',
            function (Request $request) use ($controller) {
                return Response::json($controller->describeProvider(
                    $request->getAttribute('user'),
                    (string) $request->getAttribute('key')
                ));
            }
        );

        $router->get(
            '/api/integrations',
            function (Request $request) use ($controller) {
                return Response::json($controller->listIntegrations($request->getAttribute('user')));
            }
        );

        $router->post(
            '/api/integrations',
            function (Request $request) use ($controller) {
                return Response::created($controller->register(
                    $request->getAttribute('user'),
                    $request->body()
                ));
            }
        );

        $router->get(
            '/api/integrations/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->getIntegration(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->put(
            '/api/integrations/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->post(
            '/api/integrations/{id}/test',
            function (Request $request) use ($controller) {
                return Response::json($controller->test(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/integrations/{id}/sync',
            function (Request $request) use ($controller) {
                return Response::json($controller->sync(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/integrations/{id}/disconnect',
            function (Request $request) use ($controller) {
                return Response::json($controller->disconnect(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->delete(
            '/api/integrations/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->delete(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->get(
            '/api/integrations/{id}/logs',
            function (Request $request) use ($controller) {
                return Response::json($controller->listLogs(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->get(
            '/api/integrations/{id}/webhooks',
            function (Request $request) use ($controller) {
                return Response::json($controller->listWebhookEvents(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );
    });
};
