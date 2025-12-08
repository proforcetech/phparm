<?php

use App\Support\Http\Router;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\Middleware;
use App\Support\Auth\AccessGate;
use App\Support\Auth\RolePermissions;
use App\Support\Audit\AuditLogger;
use App\Support\Webhooks\WebhookDispatcher;

/**
 * API Routes Definition
 *
 * @param Router $router
 * @param array<string, mixed> $config
 * @param \App\Database\Connection $connection
 */
return function (Router $router, array $config, $connection) {
    $authConfig = $config['auth'];
    $authService = new \App\Support\Auth\AuthService(
        $connection,
        new RolePermissions($authConfig['roles']),
        new \App\Support\Auth\PasswordResetRepository(
            $connection,
            (int) ($authConfig['passwords']['expire_minutes'] ?? 60)
        ),
        new \App\Support\Auth\EmailVerificationRepository(
            $connection,
            (int) ($authConfig['verification']['token_ttl_hours'] ?? 48)
        ),
        $authConfig
    );

    // Health check (public)
    $router->get('/health', function (Request $request) use ($connection) {
        $health = [
            'app' => 'Automotive Repair Shop Management System',
            'environment' => env('APP_ENV', 'production'),
            'database' => 'not connected',
        ];

        try {
            $connection->pdo();
            $health['database'] = 'connected';
        } catch (Throwable $e) {
            $health['database'] = 'connection failed: ' . $e->getMessage();
        }

        return Response::json($health);
    });

    $paymentConfig = require __DIR__ . '/../config/payments.php';

    // API info (public)
    $router->get('/', function () {
        return Response::json([
            'name' => 'Automotive Repair Shop Management API',
            'version' => '1.0.0',
            'endpoints' => [
                'health' => '/health',
                'auth' => '/api/auth/*',
                'customers' => '/api/customers',
                'vehicles' => '/api/vehicles',
                'estimates' => '/api/estimates',
                'invoices' => '/api/invoices',
                'inventory' => '/api/inventory',
                'appointments' => '/api/appointments',
                'service-types' => '/api/service-types',
            ],
        ]);
    });

    // Authentication routes (public)
    $router->post('/api/auth/login', function (Request $request) use ($config, $connection) {
        $email = $request->input('email');
        $password = $request->input('password');

        if (!$email || !$password) {
            return Response::badRequest('Email and password required');
        }

        $user = $authService->staffLogin((string) $email, (string) $password);

        if ($user === null) {
            return Response::unauthorized('Invalid credentials');
        }

        // Start session and capture session identifier so the SPA can treat
        // the login as authenticated. The frontend currently expects a
        // `token` field, so we return the PHP session ID to keep the
        // existing client-side logic working.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $user->id;
        $_SESSION['user'] = $user->toArray();
        $sessionId = session_id();

        return Response::json([
            'user' => $user->toArray(),
            'token' => $sessionId,
            'message' => 'Login successful',
        ]);
    });

    $router->post('/api/auth/customer-login', function (Request $request) use ($authService) {
        $email = $request->input('email');
        $password = $request->input('password');

        if (!$email || !$password) {
            return Response::badRequest('Email and password required');
        }

        $user = $authService->customerPortalLogin((string) $email, (string) $password);

        if ($user === null) {
            return Response::unauthorized('Invalid credentials');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['user_id'] = $user->id;
        $_SESSION['user'] = $user->toArray();
        $_SESSION['portal_nonce'] = $_SESSION['portal_nonce'] ?? bin2hex(random_bytes(16));
        $sessionId = session_id();

        return Response::json([
            'user' => $user->toArray(),
            'token' => $sessionId,
            'nonce' => $_SESSION['portal_nonce'],
            'api_base' => '/api',
            'message' => 'Login successful',
        ]);
    });

    $router->post('/api/auth/logout', function (Request $request) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();

        return Response::json(['message' => 'Logged out successfully']);
    });

    $router->get('/api/auth/me', function (Request $request) {
        $user = $request->getAttribute('user');

        if (!$user) {
            return Response::unauthorized('Not authenticated');
        }

        return Response::json(['user' => $user->toArray()]);
    })->middleware(Middleware::auth());

    $router->get('/api/customer-portal/bootstrap', function (Request $request) {
        $user = $request->getAttribute('user');

        if ($user === null || !$user instanceof \App\Models\User) {
            return Response::unauthorized('Not authenticated');
        }

        if ($user->role !== 'customer') {
            return Response::unauthorized('Customer access required');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['portal_nonce'] = $_SESSION['portal_nonce'] ?? bin2hex(random_bytes(16));

        return Response::json([
            'user' => $user->toArray(),
            'token' => session_id(),
            'nonce' => $_SESSION['portal_nonce'],
            'api_base' => '/api',
        ]);
    })->middleware([Middleware::auth(), Middleware::role('customer')]);

    $router->group([Middleware::auth(), Middleware::role('customer')], function (Router $router) use ($connection) {
        $preferenceController = new \App\Services\Reminder\ReminderPreferenceController(
            new \App\Services\Reminder\ReminderPreferenceService($connection),
            new \App\Services\Customer\CustomerRepository($connection)
        );
        $customerVehicleService = new \App\Services\Customer\CustomerVehicleService($connection);

        $router->get('/api/customer/reminder-preferences', function (Request $request) use ($preferenceController) {
            $user = $request->getAttribute('user');

            if ($user === null || !$user instanceof \App\Models\User) {
                return Response::unauthorized('Not authenticated');
            }

            $data = $preferenceController->showForCustomer($user);

            return Response::json($data);
        });

        $router->put('/api/customer/reminder-preferences', function (Request $request) use ($preferenceController) {
            $user = $request->getAttribute('user');

            if ($user === null || !$user instanceof \App\Models\User) {
                return Response::unauthorized('Not authenticated');
            }

            $data = $preferenceController->upsertForCustomer($user, $request->body());

            return Response::json($data);
        });

        $router->get('/api/customer/vehicles', function (Request $request) use ($customerVehicleService) {
            $user = $request->getAttribute('user');

            if ($user === null || !$user instanceof \App\Models\User) {
                return Response::unauthorized('Not authenticated');
            }

            if ($user->customer_id === null) {
                return Response::badRequest('Customer profile missing');
            }

            $vehicles = $customerVehicleService->listVehicles($user->customer_id);

            return Response::json(['data' => $vehicles]);
        });

        $router->post('/api/customer/vehicles', function (Request $request) use ($customerVehicleService) {
            $user = $request->getAttribute('user');

            if ($user === null || !$user instanceof \App\Models\User) {
                return Response::unauthorized('Not authenticated');
            }

            if ($user->customer_id === null) {
                return Response::badRequest('Customer profile missing');
            }

            $vehicle = $customerVehicleService->attachVehicle($user->customer_id, $request->body());

            return Response::created($vehicle);
        });
    });

    // Payment webhook endpoints (public - no authentication required)
    $paymentConfig = require __DIR__ . '/../config/payments.php';
    $gatewayFactory = new \App\Services\Payment\PaymentGatewayFactory($paymentConfig);
    $webhookPaymentService = new \App\Services\Invoice\PaymentProcessingService($connection, $gatewayFactory);

    $router->post('/api/webhooks/payments/stripe', function (Request $request) use ($webhookPaymentService) {
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $data = $webhookPaymentService->handleWebhook('stripe', $request->body(), $signature);
        return Response::json($data);
    });

    $router->post('/api/webhooks/payments/square', function (Request $request) use ($webhookPaymentService) {
        $signature = $_SERVER['HTTP_X_SQUARE_SIGNATURE'] ?? '';
        $data = $webhookPaymentService->handleWebhook('square', $request->body(), $signature);
        return Response::json($data);
    });

    $router->post('/api/webhooks/payments/paypal', function (Request $request) use ($webhookPaymentService) {
        $signature = $_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? '';
        $data = $webhookPaymentService->handleWebhook('paypal', $request->body(), $signature);
        return Response::json($data);
    });

    // Initialize AccessGate for protected routes
    $gate = new AccessGate(new RolePermissions($config['auth']['roles']));

    // Shared settings repository with seeded defaults
    $settingsRepository = new \App\Support\SettingsRepository($connection);
    $settingsRepository->seedDefaults($config['settings']['defaults']);

    // Dashboard routes (authenticated)
    $router->group([Middleware::auth()], function (Router $router) use ($config, $connection, $gate, $settingsRepository) {

        $dashboardService = new \App\Services\Dashboard\DashboardService($connection);
        $dashboardController = new \App\Services\Dashboard\DashboardController($dashboardService);

        $router->get('/api/dashboard', function (Request $request) use ($dashboardController) {
            $params = [
                'start' => $request->queryParam('start'),
                'end' => $request->queryParam('end'),
                'timezone' => $request->queryParam('timezone', 'UTC'),
            ];

            $data = $dashboardController->handleKpis($params);
            return Response::json($data);
        });

        $router->get('/api/dashboard/charts', function (Request $request) use ($dashboardController) {
            $params = [
                'start' => $request->queryParam('start'),
                'end' => $request->queryParam('end'),
                'timezone' => $request->queryParam('timezone', 'UTC'),
            ];

            $data = $dashboardController->handleMonthlyTrends($params);
            return Response::json($data);
        });

        $router->get('/api/dashboard/charts/service-types', function (Request $request) use ($dashboardController) {
            $params = [
                'start' => $request->queryParam('start'),
                'end' => $request->queryParam('end'),
                'timezone' => $request->queryParam('timezone', 'UTC'),
                'limit' => $request->queryParam('limit', 10),
            ];

            $data = $dashboardController->handleServiceTypeBreakdown($params);
            return Response::json($data);
        });

        // PartsTech integration
        $auditConfig = require __DIR__ . '/../config/audit.php';
        $partsTechService = new \App\Services\Integrations\PartsTechService(
            $settingsRepository,
            new \App\Support\Audit\AuditLogger($connection, $auditConfig)
        );

        $router->post('/api/partstech/vin', function (Request $request) use ($partsTechService) {
            try {
                $vin = (string) ($request->body()['vin'] ?? '');
                $data = $partsTechService->decodeVin($vin);
                return Response::json($data);
            } catch (InvalidArgumentException $exception) {
                return Response::badRequest($exception->getMessage());
            }
        });

        $router->post('/api/partstech/search', function (Request $request) use ($partsTechService) {
            try {
                $payload = $request->body();
                $query = (string) ($payload['query'] ?? '');
                $vehicle = is_array($payload['vehicle'] ?? null) ? $payload['vehicle'] : [];
                $results = $partsTechService->searchParts($query, $vehicle);
                return Response::json(['results' => $results]);
            } catch (InvalidArgumentException $exception) {
                return Response::badRequest($exception->getMessage());
            }
        });
    });

    // Customer routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {

        $customerRepository = new \App\Services\Customer\CustomerRepository($connection);
        $customerController = new \App\Services\Customer\CustomerController($customerRepository, $gate);

        $router->get('/api/customers', function (Request $request) use ($customerController) {
            $user = $request->getAttribute('user');
            $filters = [
                'query' => $request->queryParam('query'),
                'commercial' => $request->queryParam('commercial'),
                'tax_exempt' => $request->queryParam('tax_exempt'),
            ];
            $limit = (int) ($request->queryParam('limit') ?? 50);
            $offset = (int) ($request->queryParam('offset') ?? 0);

            $data = $customerController->index($user, $filters, $limit, $offset);
            return Response::json($data);
        });

        $router->get('/api/customers/{id}', function (Request $request) use ($customerController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $customerController->show($user, $id);
            return Response::json($data);
        });

        $router->post('/api/customers', function (Request $request) use ($customerController) {
            $user = $request->getAttribute('user');
            $data = $customerController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/customers/{id}', function (Request $request) use ($customerController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $customerController->update($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/customers/{id}', function (Request $request) use ($customerController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $customerController->destroy($user, $id);
            return Response::noContent();
        });

        $router->get('/api/customers/{id}/vehicles', function (Request $request) use ($customerController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $customerController->listVehicles($user, $id);
            return Response::json($data);
        });

        $router->post('/api/customers/{id}/vehicles', function (Request $request) use ($customerController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $customerController->attachVehicle($user, $id, $request->body());
            return Response::created($data);
        });

        $router->put('/api/customers/{id}/vehicles/{vehicleId}', function (Request $request) use ($customerController) {
            $user = $request->getAttribute('user');
            $customerId = (int) $request->getAttribute('id');
            $vehicleId = (int) $request->getAttribute('vehicleId');

            $data = $customerController->updateVehicle($user, $customerId, $vehicleId, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/customers/{id}/vehicles/{vehicleId}', function (Request $request) use ($customerController) {
            $user = $request->getAttribute('user');
            $customerId = (int) $request->getAttribute('id');
            $vehicleId = (int) $request->getAttribute('vehicleId');

            $customerController->deleteVehicle($user, $customerId, $vehicleId);
            return Response::noContent();
        });
    });

    // Service Type routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {

        $serviceTypeController = new \App\Services\ServiceType\ServiceTypeController(
            new \App\Services\ServiceType\ServiceTypeRepository($connection),
            $gate
        );

        $router->get('/api/service-types', function (Request $request) use ($serviceTypeController) {
            $user = $request->getAttribute('user');
            $filters = [
                'active' => $request->queryParam('active'),
                'query' => $request->queryParam('query'),
            ];

            $data = $serviceTypeController->index($user, $filters);
            return Response::json($data);
        });

        $router->post('/api/service-types', function (Request $request) use ($serviceTypeController) {
            $user = $request->getAttribute('user');
            $data = $serviceTypeController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/service-types/{id}', function (Request $request) use ($serviceTypeController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $serviceTypeController->update($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/service-types/{id}', function (Request $request) use ($serviceTypeController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $serviceTypeController->destroy($user, $id);
            return Response::noContent();
        });
    });

    // Vehicle Master routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {

        $vehicleRepository = new \App\Services\Vehicle\VehicleMasterRepository($connection);

        // VIN decoder setup
        $vinDecoder = new \App\Services\Vehicle\NhtsaVinDecoder();
        $vinDecoderService = new \App\Services\Vehicle\VinDecoderService($vinDecoder);
        $normalizationJob = new \App\Services\Vehicle\VehicleNormalizationJob($connection, $vehicleRepository, $vinDecoder);

        $vehicleController = new \App\Services\Vehicle\VehicleMasterController(
            $vehicleRepository,
            $gate,
            null, // importer
            null, // cascade
            $vinDecoderService,
            $normalizationJob
        );

        $router->get('/api/vehicles/years', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            return Response::json($vehicleController->years($user));
        });

        $router->get('/api/vehicles/{year}/makes', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $year = (int) $request->getAttribute('year');

            return Response::json($vehicleController->makes($user, $year));
        });

        $router->get('/api/vehicles/{year}/{make}/models', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $year = (int) $request->getAttribute('year');
            $make = (string) $request->getAttribute('make');

            return Response::json($vehicleController->models($user, $year, $make));
        });

        $router->get('/api/vehicles/{year}/{make}/{model}/engines', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $year = (int) $request->getAttribute('year');
            $make = (string) $request->getAttribute('make');
            $model = (string) $request->getAttribute('model');

            return Response::json($vehicleController->engines($user, $year, $make, $model));
        });

        $router->get('/api/vehicles/{year}/{make}/{model}/{engine}/transmissions', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $year = (int) $request->getAttribute('year');
            $make = (string) $request->getAttribute('make');
            $model = (string) $request->getAttribute('model');
            $engine = (string) $request->getAttribute('engine');

            return Response::json($vehicleController->transmissions($user, $year, $make, $model, $engine));
        });

        $router->get('/api/vehicles/{year}/{make}/{model}/{engine}/{transmission}/drives', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $year = (int) $request->getAttribute('year');
            $make = (string) $request->getAttribute('make');
            $model = (string) $request->getAttribute('model');
            $engine = (string) $request->getAttribute('engine');
            $transmission = (string) $request->getAttribute('transmission');

            return Response::json($vehicleController->drives($user, $year, $make, $model, $engine, $transmission));
        });

        $router->get('/api/vehicles/{year}/{make}/{model}/{engine}/{transmission}/{drive}/trims', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $year = (int) $request->getAttribute('year');
            $make = (string) $request->getAttribute('make');
            $model = (string) $request->getAttribute('model');
            $engine = (string) $request->getAttribute('engine');
            $transmission = (string) $request->getAttribute('transmission');
            $drive = (string) $request->getAttribute('drive');

            return Response::json($vehicleController->trims($user, $year, $make, $model, $engine, $transmission, $drive));
        });

        $router->get('/api/vehicles', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $filters = [
                'year' => $request->queryParam('year'),
                'make' => $request->queryParam('make'),
                'model' => $request->queryParam('model'),
            ];

            $data = $vehicleController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/vehicles/{id}', function (Request $request, int $id) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $data = $vehicleController->show($user, $id);
            return Response::json($data);
        });

        $router->post('/api/vehicles', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $data = $vehicleController->store($user, $request->body());
            return Response::created($data);
        });

        // VIN decoder endpoints
        $router->post('/api/vehicles/decode-vin', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $data = $vehicleController->decodeVin($user, $request->body());
            return Response::json($data);
        });

        $router->post('/api/vehicles/validate-vin', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $data = $vehicleController->validateVin($user, $request->body());
            return Response::json($data);
        });

        // Vehicle normalization endpoint
        $router->post('/api/vehicles/normalize', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $data = $vehicleController->runNormalization($user, $request->body());
            return Response::json($data);
        });
    });

    // Inventory routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {

        $inventoryRepository = new \App\Services\Inventory\InventoryItemRepository($connection);
        $inventoryController = new \App\Services\Inventory\InventoryItemController($inventoryRepository, $gate);

        $router->get('/api/inventory', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $filters = [
                'query' => $request->queryParam('query'),
                'category' => $request->queryParam('category'),
                'low_stock_only' => $request->queryParam('low_stock') === 'true',
            ];

            $data = $inventoryController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/dashboard/inventory/low-stock', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $limit = max(1, (int) ($request->queryParam('limit') ?? 5));

            $data = $inventoryController->lowStockTile($user, $limit);

            return Response::json($data);
        });

        $router->get('/api/inventory/low-stock', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $params = [
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
                'query' => $request->queryParam('query'),
                'category' => $request->queryParam('category'),
                'location' => $request->queryParam('location'),
            ];

            $data = $inventoryController->lowStock($user, $params);

            return Response::json($data);
        });

        $router->get('/api/inventory/{id}', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $inventoryController->show($user, $id);
            return Response::json($data);
        });

        $router->post('/api/inventory', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $data = $inventoryController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/inventory/{id}', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $inventoryController->update($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/inventory/{id}', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $inventoryController->destroy($user, $id);
            return Response::noContent();
        });
    });

    // Estimate routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {

        $bundleController = new \App\Services\Estimate\BundleController(
            new \App\Services\Estimate\BundleService($connection),
            $gate
        );

        $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection);
        $estimateController = new \App\Services\Estimate\EstimateController($estimateRepository, $gate);

        $router->get('/api/bundles', function (Request $request) use ($bundleController) {
            $user = $request->getAttribute('user');
            $filters = [
                'query' => $request->queryParam('query'),
                'active' => $request->queryParam('active'),
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ];

            $data = $bundleController->index($user, $filters);
            return Response::json(['data' => $data]);
        });

        $router->get('/api/bundles/{id}', function (Request $request) use ($bundleController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $bundleController->show($user, $id);
            return Response::json($data);
        });

        $router->post('/api/bundles', function (Request $request) use ($bundleController) {
            $user = $request->getAttribute('user');
            $data = $bundleController->store($user, $request->body());

            return Response::created($data);
        });

        $router->put('/api/bundles/{id}', function (Request $request) use ($bundleController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $bundleController->update($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/bundles/{id}', function (Request $request) use ($bundleController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $bundleController->destroy($user, $id);
            return Response::noContent();
        });

        $router->get('/api/estimates/bundles/{id}/items', function (Request $request) use ($bundleController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $items = $bundleController->fetchItemsForEstimate($user, $id);
            return Response::json(['items' => $items]);
        });

        $router->get('/api/estimates', function (Request $request) use ($estimateController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'customer_id' => $request->queryParam('customer_id'),
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ];

            $data = $estimateController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/estimates/{id}', function (Request $request) use ($estimateController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $estimateController->show($user, $id);
            return Response::json($data);
        });

        $router->post('/api/estimates', function (Request $request) use ($estimateController) {
            $user = $request->getAttribute('user');
            $data = $estimateController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/estimates/{id}', function (Request $request) use ($estimateController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $estimateController->update($user, $id, $request->body());
            return Response::json($data);
        });
    });

    // Reminder Campaign routes
    $router->group([Middleware::auth(), Middleware::role('admin', 'manager')], function (Router $router) use ($connection, $config) {
        $notificationConfig = require __DIR__ . '/../config/notifications.php';
        $templateEngine = new \App\Support\Notifications\TemplateEngine();
        $notificationLogs = new \App\Support\Notifications\NotificationLogRepository($connection);
        $notifications = new \App\Support\Notifications\NotificationDispatcher($notificationConfig, $templateEngine, $notificationLogs);
        $preferenceService = new \App\Services\Reminder\ReminderPreferenceService($connection);
        $campaignService = new \App\Services\Reminder\ReminderCampaignService($connection);
        $logService = new \App\Services\Reminder\ReminderLogService($connection);
        $scheduler = new \App\Services\Reminder\ReminderScheduler(
            $connection,
            $campaignService,
            $preferenceService,
            $notifications,
            $logService,
            $templateEngine
        );
        $controller = new \App\Services\Reminder\ReminderCampaignController($campaignService, $scheduler, $logService);

        $router->get('/api/reminders', function () use ($controller) {
            $data = $controller->index();
            return Response::json($data);
        });

        $router->post('/api/reminders', function (Request $request) use ($controller) {
            $user = $request->getAttribute('user');
            $data = $controller->store($request->body(), $user->id);
            return Response::created($data->toArray());
        });

        $router->put('/api/reminders/{id}', function (Request $request) use ($controller) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $campaign = $controller->update($id, $request->body(), $user->id);
            return Response::json($campaign?->toArray());
        });

        $router->post('/api/reminders/{id}/pause', function (Request $request) use ($controller) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $campaign = $controller->pause($id, $user->id);
            return Response::json($campaign?->toArray());
        });

        $router->post('/api/reminders/{id}/activate', function (Request $request) use ($controller) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $campaign = $controller->activate($id, $user->id);
            return Response::json($campaign?->toArray());
        });

        $router->post('/api/reminders/{id}/run', function (Request $request) use ($controller) {
            $user = $request->getAttribute('user');
            $controller->runNow((int) $request->getAttribute('id'), $user->id);
            return Response::json(['status' => 'queued']);
        });

        $router->get('/api/reminders/{id}/logs', function (Request $request) use ($controller) {
            $id = (int) $request->getAttribute('id');
            $limit = (int) $request->queryParam('limit', 50);

            $logs = $controller->logs($id, $limit);
            return Response::json($logs);
        });
    });

    // Health Status routes
    $router->group([Middleware::auth(), Middleware::role('admin')], function (Router $router) use ($connection) {

        $router->get('/api/system/health', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            $healthService = new \App\Services\Health\HealthStatusService($connection);
            $status = $healthService->check();
            return Response::json($status);
        });
    });

    // Public invoice routes
    $publicGatewayFactory = new \App\Services\Payment\PaymentGatewayFactory($paymentConfig);
    $publicInvoiceController = new \App\Services\Invoice\InvoicePublicController(
        new \App\Services\Invoice\InvoiceService($connection),
        new \App\Services\Invoice\PaymentProcessingService($connection, $publicGatewayFactory),
        new \App\Support\Pdf\InvoicePdfGenerator($connection)
    );

    $router->get('/public/invoices/{token}', function (Request $request) use ($publicInvoiceController) {
        $token = (string) $request->getAttribute('token');
        $invoice = $publicInvoiceController->show($token);
        return Response::json($invoice);
    });

    $router->post('/public/invoices/{token}/checkout', function (Request $request) use ($publicInvoiceController) {
        $token = (string) $request->getAttribute('token');
        $data = $publicInvoiceController->createCheckout($token, $request->body());
        return Response::json($data);
    });

    $router->get('/public/invoices/{token}/pdf', function (Request $request) use ($publicInvoiceController, $config) {
        $token = (string) $request->getAttribute('token');
        $settings = [
            'shop_name' => $config['settings']['shop_name'] ?? 'Auto Repair Shop',
            'shop_address' => $config['settings']['shop_address'] ?? '',
            'shop_phone' => $config['settings']['shop_phone'] ?? '',
            'shop_email' => $config['settings']['shop_email'] ?? '',
            'invoice_terms' => $config['settings']['invoice_terms'] ?? '',
        ];

        $pdfContent = $publicInvoiceController->downloadPdf($token, $settings);

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="invoice-' . $token . '.pdf"');
        header('Content-Length: ' . strlen($pdfContent));
        echo $pdfContent;
        exit;
    });

    // Invoice routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $config) {

        // Payment gateway setup
        $gatewayFactory = new \App\Services\Payment\PaymentGatewayFactory($paymentConfig);

        $invoiceController = new \App\Services\Invoice\InvoiceController(
            new \App\Services\Invoice\InvoiceService($connection),
            new \App\Services\Invoice\PaymentProcessingService($connection, $gatewayFactory),
            $gate,
            new \App\Support\Pdf\InvoicePdfGenerator($connection)
        );

        $router->get('/api/invoices', function (Request $request) use ($invoiceController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'customer_id' => $request->queryParam('customer_id'),
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ];
            $data = $invoiceController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/invoices/{id}', function (Request $request) use ($invoiceController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $invoiceController->show($user, $id);
            return Response::json($data);
        });

        $router->post('/api/invoices/from-estimate', function (Request $request) use ($invoiceController) {
            $user = $request->getAttribute('user');
            $data = $invoiceController->createFromEstimate($user, $request->body());
            return Response::created($data);
        });

        $router->post('/api/invoices', function (Request $request) use ($invoiceController) {
            $user = $request->getAttribute('user');
            $data = $invoiceController->store($user, $request->body());
            return Response::created($data);
        });

        $router->patch('/api/invoices/{id}/status', function (Request $request) use ($invoiceController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $invoiceController->updateStatus($user, $id, $request->body());
            return Response::json($data);
        });

        $router->post('/api/invoices/{id}/checkout', function (Request $request) use ($invoiceController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $invoiceController->createCheckout($user, $id, $request->body());
            return Response::json($data);
        });

        $router->post('/api/invoices/{id}/refund', function (Request $request) use ($invoiceController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $invoiceController->refundPayment($user, $id, $request->body());
            return Response::json($data);
        });

        $router->get('/api/payment/gateways', function (Request $request) use ($invoiceController) {
            $user = $request->getAttribute('user');
            $data = $invoiceController->getAvailableGateways($user);
            return Response::json($data);
        });

        $router->get('/api/invoices/{id}/pdf', function (Request $request) use ($invoiceController, $config) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $settings = [
                'shop_name' => $config['settings']['shop_name'] ?? 'Auto Repair Shop',
                'shop_address' => $config['settings']['shop_address'] ?? '',
                'shop_phone' => $config['settings']['shop_phone'] ?? '',
                'shop_email' => $config['settings']['shop_email'] ?? '',
                'invoice_terms' => $config['settings']['invoice_terms'] ?? '',
            ];

            $pdfContent = $invoiceController->downloadPdf($user, $id, $settings);

            // Return PDF as download
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="invoice-' . $id . '.pdf"');
            header('Content-Length: ' . strlen($pdfContent));
            echo $pdfContent;
            exit;
        });
    });

    // Appointment routes
    $appointmentAudit = new AuditLogger($connection, $config['audit']);
    $webhookConfig = $config['appointments']['webhooks'] ?? [];
    $appointmentWebhooks = new WebhookDispatcher(
        !empty($webhookConfig['enabled']) ? ($webhookConfig['endpoints'] ?? []) : [],
        (string) ($webhookConfig['secret'] ?? ''),
        (int) ($webhookConfig['timeout'] ?? 5),
        $appointmentAudit
    );

    $appointmentController = new \App\Services\Appointment\AppointmentController(
        new \App\Services\Appointment\AppointmentService($connection, $appointmentAudit, $appointmentWebhooks),
        new \App\Services\Appointment\AvailabilityService($connection),
        $gate
    );

    $router->get('/api/public/appointments/availability', function (Request $request) use ($appointmentController) {
        $params = [
            'date' => $request->queryParam('date'),
            'technician_id' => $request->queryParam('technician_id'),
        ];
        $data = $appointmentController->availability(null, $params);
        return Response::json($data);
    });

    $router->group([Middleware::auth()], function (Router $router) use ($appointmentController) {
        $router->get('/api/appointments', function (Request $request) use ($appointmentController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'customer_id' => $request->queryParam('customer_id'),
                'technician_id' => $request->queryParam('technician_id'),
                'date' => $request->queryParam('date'),
            ];
            $data = $appointmentController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/appointments/availability', function (Request $request) use ($appointmentController) {
            $user = $request->getAttribute('user');
            $params = [
                'date' => $request->queryParam('date'),
                'technician_id' => $request->queryParam('technician_id'),
            ];
            $data = $appointmentController->availability($user, $params);
            return Response::json($data);
        });

        $router->get('/api/appointments/{id}', function (Request $request) use ($appointmentController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $appointmentController->show($user, $id);
            return Response::json($data);
        });

        $router->post('/api/appointments', function (Request $request) use ($appointmentController) {
            $user = $request->getAttribute('user');
            $data = $appointmentController->store($user, $request->body());
            return Response::created($data);
        });

        $router->get('/api/appointments/availability/config', function (Request $request) use ($appointmentController) {
            $user = $request->getAttribute('user');
            $data = $appointmentController->availabilityConfig($user);
            return Response::json($data);
        });

        $router->put('/api/appointments/availability/config', function (Request $request) use ($appointmentController) {
            $user = $request->getAttribute('user');
            $data = $appointmentController->saveAvailabilityConfig($user, $request->body());
            return Response::json($data);
        });

        $router->put('/api/appointments/{id}', function (Request $request) use ($appointmentController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $appointmentController->update($user, $id, $request->body());
            return Response::json($data);
        });

        $router->patch('/api/appointments/{id}/status', function (Request $request) use ($appointmentController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $appointmentController->updateStatus($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/appointments/{id}', function (Request $request) use ($appointmentController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $appointmentController->destroy($user, $id);
            return Response::noContent();
        });
    });

    // Inspection routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $config) {

        $inspectionController = new \App\Services\Inspection\InspectionController(
            new \App\Services\Inspection\InspectionTemplateService($connection),
            new \App\Services\Inspection\InspectionCompletionService($connection),
            new \App\Services\Inspection\InspectionPortalService($connection),
            $gate,
            new \App\Support\Pdf\InspectionPdfGenerator($connection)
        );

        $router->get('/api/inspections/templates', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $data = $inspectionController->templates($user);
            return Response::json($data);
        });

        $router->post('/api/inspections/templates', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $data = $inspectionController->createTemplate($user, $request->body());
            return Response::created($data);
        });

        $router->post('/api/inspections/{id}/complete', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $inspectionController->complete($user, $id, $request->body());
            return Response::json($data);
        });

        $router->get('/api/inspections/customer', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $data = $inspectionController->customerList($user);
            return Response::json($data);
        });

        $router->get('/api/inspections/{id}/pdf', function (Request $request) use ($inspectionController, $config) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $settings = [
                'shop_name' => $config['settings']['shop_name'] ?? 'Auto Repair Shop',
                'shop_address' => $config['settings']['shop_address'] ?? '',
                'shop_phone' => $config['settings']['shop_phone'] ?? '',
            ];

            $pdfContent = $inspectionController->downloadPdf($user, $id, $settings);

            // Return PDF as download
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="inspection-report-' . $id . '.pdf"');
            header('Content-Length: ' . strlen($pdfContent));
            echo $pdfContent;
            exit;
        });
    });

    // Warranty routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {

        $warrantyController = new \App\Services\Warranty\WarrantyController(
            new \App\Services\Warranty\WarrantyClaimService($connection),
            $gate
        );

        $router->get('/api/warranty-claims', function (Request $request) use ($warrantyController) {
            $user = $request->getAttribute('user');
            $filters = ['status' => $request->queryParam('status')];
            $data = $warrantyController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/warranty-claims/{id}', function (Request $request) use ($warrantyController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $warrantyController->show($user, $id);
            return Response::json($data);
        });

        $router->post('/api/warranty-claims', function (Request $request) use ($warrantyController) {
            $user = $request->getAttribute('user');
            $data = $warrantyController->store($user, $request->body());
            return Response::created($data);
        });

        $router->patch('/api/warranty-claims/{id}/status', function (Request $request) use ($warrantyController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $warrantyController->updateStatus($user, $id, $request->body());
            return Response::json($data);
        });

        $router->get('/api/customer/warranty-claims', function (Request $request) use ($warrantyController) {
            $user = $request->getAttribute('user');
            $filters = ['status' => $request->queryParam('status')];
            $data = $warrantyController->customerIndex($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/customer/warranty-claims/{id}', function (Request $request) use ($warrantyController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $warrantyController->customerShow($user, $id);
            return Response::json($data);
        });

        $router->post('/api/customer/warranty-claims/{id}/reply', function (Request $request) use ($warrantyController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $warrantyController->reply($user, $id, $request->body());
            return Response::json($data);
        });
    });

    // Credit Account routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {

        $creditController = new \App\Services\Credit\CreditAccountController(
            new \App\Services\Credit\CreditAccountService($connection),
            new \App\Services\Credit\CreditAccountStatementService($connection),
            $gate
        );

        $router->get('/api/credit-accounts', function (Request $request) use ($creditController) {
            $user = $request->getAttribute('user');
            $data = $creditController->index($user, []);
            return Response::json($data);
        });

        $router->get('/api/credit-accounts/{id}', function (Request $request) use ($creditController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $creditController->show($user, $id);
            return Response::json($data);
        });

        $router->post('/api/credit-accounts', function (Request $request) use ($creditController) {
            $user = $request->getAttribute('user');
            $data = $creditController->store($user, $request->body());
            return Response::created($data);
        });

        $router->post('/api/credit-accounts/{id}/payments', function (Request $request) use ($creditController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $creditController->recordPayment($user, $id, $request->body());
            return Response::json($data);
        });

        $router->get('/api/credit-accounts/{id}/statement', function (Request $request) use ($creditController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $params = [
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
            ];
            $data = $creditController->statement($user, $id, $params);
            return Response::json($data);
        });

        $router->get('/api/credit-accounts/customer/me', function (Request $request) use ($creditController) {
            $user = $request->getAttribute('user');
            $data = $creditController->customerView($user);
            return Response::json($data);
        });

        $router->get('/api/credit-accounts/customer/history', function (Request $request) use ($creditController) {
            $user = $request->getAttribute('user');
            $data = $creditController->customerHistory($user);
            return Response::json($data);
        });

        $router->post('/api/credit-accounts/customer/payments', function (Request $request) use ($creditController) {
            $user = $request->getAttribute('user');
            $data = $creditController->submitCustomerPayment($user, $request->body());
            return Response::json($data);
        });
    });

    // Financial routes (Admin/Manager only)
    $router->group([Middleware::auth(), Middleware::role('admin', 'manager')], function (Router $router) use ($connection, $gate) {

        $financialController = new \App\Services\Financial\FinancialController(
            new \App\Services\Financial\FinancialEntryService($connection),
            new \App\Services\Financial\FinancialReportService($connection),
            $gate
        );

        $router->get('/api/financial/entries', function (Request $request) use ($financialController) {
            $user = $request->getAttribute('user');
            $filters = [
                'type' => $request->queryParam('type'),
                'category' => $request->queryParam('category'),
                'vendor' => $request->queryParam('vendor'),
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
                'search' => $request->queryParam('search'),
                'page' => $request->queryParam('page', 1),
                'per_page' => $request->queryParam('per_page', 25),
            ];
            $data = $financialController->index($user, $filters);
            return Response::json($data);
        });

        $router->post('/api/financial/entries', function (Request $request) use ($financialController) {
            $user = $request->getAttribute('user');
            $data = $financialController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/financial/entries/{id}', function (Request $request) use ($financialController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $financialController->update($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/financial/entries/{id}', function (Request $request) use ($financialController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $financialController->destroy($user, $id);
            return Response::noContent();
        });

        $router->get('/api/financial/reports', function (Request $request) use ($financialController) {
            $user = $request->getAttribute('user');
            $params = [
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
                'category' => $request->queryParam('category'),
                'vendor' => $request->queryParam('vendor'),
            ];
            $data = $financialController->report($user, $params);
            return Response::json($data);
        });

        $router->get('/api/financial/reports/export', function (Request $request) use ($financialController) {
            $user = $request->getAttribute('user');
            $params = [
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
                'format' => $request->queryParam('format', 'csv'),
                'category' => $request->queryParam('category'),
                'vendor' => $request->queryParam('vendor'),
            ];
            $data = $financialController->export($user, $params);
            return Response::json($data);
        });

        $router->get('/api/financial/entries/export', function (Request $request) use ($financialController) {
            $user = $request->getAttribute('user');
            $filters = [
                'type' => $request->queryParam('type'),
                'category' => $request->queryParam('category'),
                'vendor' => $request->queryParam('vendor'),
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
                'search' => $request->queryParam('search'),
            ];
            $data = $financialController->exportEntries($user, $filters);
            return Response::json($data);
        });
    });

    // Time Tracking routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {

        $timeTrackingService = new \App\Services\TimeTracking\TimeTrackingService($connection);

        $timeController = new \App\Services\TimeTracking\TimeTrackingController(
            $timeTrackingService,
            new \App\Services\TimeTracking\TechnicianPortalService($connection, $timeTrackingService),
            $gate
        );

        $router->get('/api/time-tracking', function (Request $request) use ($timeController) {
            $user = $request->getAttribute('user');
            $filters = [
                'technician_id' => $request->queryParam('technician_id'),
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
                'search' => $request->queryParam('search'),
                'page' => $request->queryParam('page', 1),
                'per_page' => $request->queryParam('per_page', 25),
            ];
            $data = $timeController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/time-tracking/export', function (Request $request) use ($timeController) {
            $user = $request->getAttribute('user');
            $filters = [
                'technician_id' => $request->queryParam('technician_id'),
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
                'search' => $request->queryParam('search'),
                'limit' => $request->queryParam('limit'),
            ];
            $data = $timeController->export($user, $filters);
            return Response::json($data);
        });

        $router->post('/api/time-tracking/start', function (Request $request) use ($timeController) {
            $user = $request->getAttribute('user');
            $data = $timeController->start($user, $request->body());
            return Response::created($data);
        });

        $router->post('/api/time-tracking/{id}/stop', function (Request $request) use ($timeController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $timeController->stop($user, $id, $request->body());
            return Response::json($data);
        });

        $router->post('/api/time-tracking', function (Request $request) use ($timeController) {
            $user = $request->getAttribute('user');
            $data = $timeController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/time-tracking/{id}', function (Request $request) use ($timeController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $timeController->update($user, $id, $request->body());
            return Response::json($data);
        });

        $router->get('/api/time-tracking/technician/jobs', function (Request $request) use ($timeController) {
            $user = $request->getAttribute('user');
            $data = $timeController->assignedJobs($user);
            return Response::json($data);
        });

        $router->get('/api/time-tracking/technician/portal', function (Request $request) use ($timeController) {
            $user = $request->getAttribute('user');
            $data = $timeController->portal($user);
            return Response::json($data);
        });
    });

    // Settings routes (Admin only)
    $router->group([Middleware::auth(), Middleware::role('admin')], function (Router $router) use ($connection, $gate, $settingsRepository) {

        $settingsController = new \App\Services\Settings\SettingsController(
            $settingsRepository,
            $gate
        );

        $router->get('/api/settings', function (Request $request) use ($settingsController) {
            $user = $request->getAttribute('user');
            $data = $settingsController->index($user);
            return Response::json($data);
        });

        $router->get('/api/settings/{key}', function (Request $request) use ($settingsController) {
            $user = $request->getAttribute('user');
            $key = $request->getAttribute('key');
            $value = $settingsController->show($user, (string) $key);
            return Response::json(['key' => $key, 'value' => $value]);
        });

        $router->put('/api/settings/{key}', function (Request $request) use ($settingsController) {
            $user = $request->getAttribute('user');
            $key = $request->getAttribute('key');
            $data = $settingsController->update($user, (string) $key, $request->body());
            return Response::json($data);
        });

        $router->put('/api/settings', function (Request $request) use ($settingsController) {
            $user = $request->getAttribute('user');
            $data = $settingsController->bulkUpdate($user, $request->body());
            return Response::json($data);
        });
    });

    // Audit routes (Admin only)
    $router->group([Middleware::auth(), Middleware::role('admin')], function (Router $router) use ($connection, $gate) {

        $auditController = new \App\Services\Audit\AuditController(
            new \App\Services\Audit\AuditLogViewerService($connection),
            new \App\Services\ImportExport\AuditExportService($connection),
            $gate
        );

        $router->get('/api/audit', function (Request $request) use ($auditController) {
            $user = $request->getAttribute('user');
            $filters = [
                'entity_type' => $request->queryParam('entity_type'),
                'actor_id' => $request->queryParam('actor_id'),
            ];
            $data = $auditController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/audit/{id}', function (Request $request) use ($auditController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $auditController->show($user, $id);
            return Response::json($data);
        });

        $router->get('/api/audit/export', function (Request $request) use ($auditController) {
            $user = $request->getAttribute('user');
            $params = [
                'entity_type' => $request->queryParam('entity_type'),
                'actor_id' => $request->queryParam('actor_id'),
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
                'format' => $request->queryParam('format', 'csv'),
            ];
            $data = $auditController->export($user, $params);
            return Response::json($data);
        });
    });
};
