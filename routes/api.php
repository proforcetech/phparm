<?php

use App\Support\Http\Router;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\Middleware;
use App\Support\Auth\AccessGate;
use App\Support\Auth\RolePermissions;

/**
 * API Routes Definition
 *
 * @param Router $router
 * @param array<string, mixed> $config
 * @param \App\Database\Connection $connection
 */
return function (Router $router, array $config, $connection) {

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

        // Load auth service
        $authService = new \App\Support\Auth\AuthService(
            $connection,
            new RolePermissions($config['auth']['roles']),
            new \App\Support\Auth\PasswordResetRepository($connection),
            new \App\Support\Auth\EmailVerificationRepository($connection),
            $config['auth']
        );

        $user = $authService->staffLogin((string) $email, (string) $password);

        if ($user === null) {
            return Response::unauthorized('Invalid credentials');
        }

        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $user->id;
        $_SESSION['user'] = $user->toArray();

        return Response::json([
            'user' => $user->toArray(),
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

    // Initialize AccessGate for protected routes
    $gate = new AccessGate(new RolePermissions($config['auth']['roles']));

    // Dashboard routes (authenticated)
    $router->group([Middleware::auth()], function (Router $router) use ($config, $connection, $gate) {

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
        $vehicleController = new \App\Services\Vehicle\VehicleMasterController($vehicleRepository, $gate);

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

        $router->post('/api/vehicles', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $data = $vehicleController->store($user, $request->body());
            return Response::created($data);
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
                'low_stock' => $request->queryParam('low_stock') === 'true',
            ];

            $data = $inventoryController->index($user, $filters);
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

        $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection);
        $estimateController = new \App\Services\Estimate\EstimateController($estimateRepository, $gate);

        $router->get('/api/estimates', function (Request $request) use ($estimateController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'customer_id' => $request->queryParam('customer_id'),
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
    $router->group([Middleware::auth(), Middleware::role('admin', 'manager')], function (Router $router) use ($connection) {

        $router->get('/api/reminders', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            $reminderService = new \App\Services\Reminder\ReminderCampaignService($connection);
            $campaigns = $reminderService->list();
            return Response::json($campaigns);
        });

        $router->post('/api/reminders/{id}/activate', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $reminderService = new \App\Services\Reminder\ReminderCampaignService($connection);
            $campaign = $reminderService->activate($id, $user->id);
            return Response::json($campaign);
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
};
