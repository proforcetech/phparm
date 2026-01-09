<?php

use App\Support\Http\Router;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\Middleware;
use App\Support\Http\RateLimiter;
use App\Support\Auth\AccessGate;
use App\Support\Auth\JwtService;
use App\Support\Auth\RolePermissions;
use App\Support\Audit\AuditLogger;
use App\Support\Webhooks\WebhookDispatcher;
use App\Models\WorkorderJob;
use App\Support\Security\RecaptchaVerifier;
use App\Support\Security\LoginRateLimiter;
use App\Support\Auth\TotpService;
use App\CMS\Controllers\CategoryController;
use App\CMS\Controllers\MediaController;
use App\CMS\Controllers\MenuController;
use App\CMS\Controllers\PageController;
use App\Services\CMS\CMSCacheService;

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

    // Initialize JWT service for token generation
    $jwtConfig = $authConfig['jwt'] ?? [];
    $jwtService = new JwtService(
        $connection,
        $jwtConfig['secret'] ?? 'default-secret-key-change-in-production',
        $jwtConfig['ttl'] ?? 3600,
        $jwtConfig['refresh_ttl'] ?? 604800
    );

    // Ensure all authenticated routes share the same JWT validator instance
    Middleware::setJwtService($jwtService);

    $settingsRepository = new \App\Support\SettingsRepository($connection);
    $settingsRepository->seedDefaults($config['settings']['defaults']);

    $recaptchaConfigLoader = function () use ($settingsRepository, $config): array {
        $fallback = $config['recaptcha'] ?? [];

        try {
            return [
                'enabled' => (bool) $settingsRepository->get(
                    'integrations.recaptcha.enabled',
                    $fallback['enabled'] ?? false
                ),
                'site_key' => $settingsRepository->get('integrations.recaptcha.site_key', $fallback['site_key'] ?? null),
                'secret_key' => $settingsRepository->get(
                    'integrations.recaptcha.secret_key',
                    $fallback['secret_key'] ?? null
                ),
                'score_threshold' => (float) $settingsRepository->get(
                    'integrations.recaptcha.score_threshold',
                    $fallback['score_threshold'] ?? 0.5
                ),
            ];
        } catch (Throwable $e) {
            return [
                'enabled' => (bool) ($fallback['enabled'] ?? false),
                'site_key' => $fallback['site_key'] ?? null,
                'secret_key' => $fallback['secret_key'] ?? null,
                'score_threshold' => (float) ($fallback['score_threshold'] ?? 0.5),
            ];
        }
    };

    $recaptchaVerifier = function () use ($recaptchaConfigLoader): RecaptchaVerifier {
        $recaptchaConfig = $recaptchaConfigLoader();

        return new RecaptchaVerifier(
            $recaptchaConfig['secret_key'] ?? null,
            (float) ($recaptchaConfig['score_threshold'] ?? 0.5),
            $recaptchaConfig['enabled'] ?? false
        );
    };

    $totpService = new TotpService();

    $securityConfig = require __DIR__ . '/../config/security.php';
    $auditConfig = require __DIR__ . '/../config/audit.php';
    $auditLogger = new AuditLogger($connection, $auditConfig);
    $rateLimiter = new RateLimiter(__DIR__ . '/../storage/temp/ratelimits');
    $loginLimiter = new LoginRateLimiter(
        $rateLimiter,
        $securityConfig['auth_rate_limiting'] ?? [],
        $auditLogger
    );

    $rateLimitResponse = function (\App\Support\Security\LoginRateLimitResult $result, string $message, int $status = 429, string $error = 'rate_limited') {
        return Response::json($result->toPayload($message, $error), $status);
    };

    $resolveMandatoryTwoFactorRoles = function () use ($settingsRepository, $authConfig): array {
        $defaultRoles = [];
        foreach ($authConfig['roles'] ?? [] as $roleKey => $roleConfig) {
            if (($roleConfig['requires_2fa'] ?? false) === true) {
                $defaultRoles[] = $roleKey;
            }
        }

        $configuredRoles = $settingsRepository->get('security.mandatory_2fa_roles', $defaultRoles);
        if (!is_array($configuredRoles)) {
            return $defaultRoles;
        }

        return $configuredRoles;
    };

    $enforceMandatoryTwoFactorSetup = function (\App\Models\User $user) use ($resolveMandatoryTwoFactorRoles, $connection): \App\Models\User {
        $requiredRoles = $resolveMandatoryTwoFactorRoles();
        if (!in_array($user->role, $requiredRoles, true) || $user->two_factor_enabled) {
            return $user;
        }

        if ($user->two_factor_setup_pending) {
            return $user;
        }

        $userRepo = new \App\Services\User\UserRepository($connection);
        return $userRepo->requireTwoFactorSetup($user->id);
    };

    // Apply global rate limiting (60 requests per minute per IP+path)
    $router->middleware(Middleware::throttleWithOverrides(60, 60, [
        '/api/time-tracking*' => ['max' => 240, 'decay' => 60],
        '/api/messages*' => ['max' => 120, 'decay' => 60],
    ]));

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

    // Public security configuration
    $router->get('/api/public/security/recaptcha', function () use ($recaptchaConfigLoader) {
        $recaptchaConfig = $recaptchaConfigLoader();
        return Response::json([
            'enabled' => (bool) ($recaptchaConfig['enabled'] ?? false),
            'site_key' => $recaptchaConfig['site_key'] ?? null,
            'score_threshold' => (float) ($recaptchaConfig['score_threshold'] ?? 0.5),
        ]);
    });

    // Public vehicle data endpoints for estimate request form
    $router->get('/api/public/vehicle-years', function () use ($connection) {
        $stmt = $connection->pdo()->query(
            'SELECT DISTINCT year FROM vehicle_master WHERE year IS NOT NULL ORDER BY year DESC'
        );
        $years = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return Response::json(['years' => $years]);
    });

    $router->get('/api/public/vehicle-makes', function (Request $request) use ($connection) {
        $year = $request->queryParam('year');
        if (!$year) {
            return Response::json(['error' => 'Year parameter is required'], 400);
        }

        $stmt = $connection->pdo()->prepare(
            'SELECT DISTINCT make FROM vehicle_master WHERE year = :year AND make IS NOT NULL ORDER BY make ASC'
        );
        $stmt->execute(['year' => $year]);
        $makes = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return Response::json(['makes' => $makes]);
    });

    $router->get('/api/public/vehicle-models', function (Request $request) use ($connection) {
        $year = $request->queryParam('year');
        $make = $request->queryParam('make');

        if (!$year || !$make) {
            return Response::json(['error' => 'Year and make parameters are required'], 400);
        }

        $stmt = $connection->pdo()->prepare(
            'SELECT DISTINCT model FROM vehicle_master
             WHERE year = :year AND make = :make AND model IS NOT NULL
             ORDER BY model ASC'
        );
        $stmt->execute(['year' => $year, 'make' => $make]);
        $models = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        return Response::json(['models' => $models]);
    });

    $router->get('/api/public/service-types', function () use ($connection) {
        $stmt = $connection->pdo()->query(
            'SELECT id, name, description
             FROM service_types
             WHERE active = 1
             ORDER BY display_order ASC, name ASC'
        );
        $serviceTypes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return Response::json(['service_types' => $serviceTypes]);
    });

    // Submit estimate request (public)
    $router->post('/api/public/estimate-request', function (Request $request) use ($connection, $recaptchaVerifier) {
        // Verify reCAPTCHA if enabled
        $recaptchaToken = $request->input('recaptcha_token');
        $verifier = $recaptchaVerifier();
        if (!$verifier->verify($recaptchaToken)) {
            return Response::json([
                'error' => 'reCAPTCHA verification failed. Please try again.',
            ], 400);
        }

        // Validate required fields
        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', ''));
        $phone = trim((string) $request->input('phone', ''));
        $address = trim((string) $request->input('address', ''));
        $city = trim((string) $request->input('city', ''));
        $state = trim((string) $request->input('state', ''));
        $zip = trim((string) $request->input('zip', ''));

        if (!$name || !$email || !$phone || !$address || !$city || !$state || !$zip) {
            return Response::json([
                'error' => 'All contact and address fields are required.',
            ], 400);
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json([
                'error' => 'Invalid email address.',
            ], 400);
        }

        // Get service type name if ID provided
        $serviceTypeId = $request->input('service_type_id');
        $serviceTypeName = null;
        if ($serviceTypeId) {
            $stmt = $connection->pdo()->prepare('SELECT name FROM service_types WHERE id = :id');
            $stmt->execute(['id' => $serviceTypeId]);
            $serviceTypeName = $stmt->fetchColumn();
        }

        // Prepare request data
        $requestData = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'service_address_same_as_customer' => (bool) $request->input('service_address_same_as_customer', true),
            'service_address' => $request->input('service_address'),
            'service_city' => $request->input('service_city'),
            'service_state' => $request->input('service_state'),
            'service_zip' => $request->input('service_zip'),
            'vehicle_year' => $request->input('vehicle_year'),
            'vehicle_make' => $request->input('vehicle_make'),
            'vehicle_model' => $request->input('vehicle_model'),
            'vin' => $request->input('vin'),
            'license_plate' => $request->input('license_plate'),
            'service_type_id' => $serviceTypeId,
            'service_type_name' => $serviceTypeName,
            'description' => $request->input('description'),
            'source' => 'website',
            'ip_address' => $request->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

        try {
            // Create estimate request
            $repository = new \App\Services\EstimateRequest\EstimateRequestRepository($connection);
            $estimateRequest = $repository->create($requestData);

            // Handle file uploads if present
            if (!empty($_FILES['photos'])) {
                $uploadDir = __DIR__ . '/../storage/uploads/estimate-requests/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $files = $_FILES['photos'];
                $fileCount = is_array($files['name']) ? count($files['name']) : 1;

                for ($i = 0; $i < min($fileCount, 5); $i++) {
                    $fileName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                    $fileTmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                    $fileSize = is_array($files['size']) ? $files['size'][$i] : $files['size'];
                    $fileError = is_array($files['error']) ? $files['error'][$i] : $files['error'];

                    if ($fileError === UPLOAD_ERR_OK) {
                        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
                        $safeFileName = 'request_' . $estimateRequest->id . '_' . uniqid() . '.' . $fileExt;
                        $filePath = $uploadDir . $safeFileName;

                        if (move_uploaded_file($fileTmpName, $filePath)) {
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mimeType = finfo_file($finfo, $filePath);
                            finfo_close($finfo);

                            $repository->addMedia(
                                $estimateRequest->id,
                                'uploads/estimate-requests/' . $safeFileName,
                                $fileName,
                                $mimeType,
                                $fileSize
                            );
                        }
                    }
                }
            }

            // Auto-process to create draft estimate
            $estimateNumber = null;
            try {
                $processor = new \App\Services\EstimateRequest\EstimateRequestProcessor(
                    $connection,
                    $repository,
                    new \App\Services\Customer\CustomerRepository(
                        $connection,
                        new \App\Services\Customer\CustomerValidator()
                    ),
                    new \App\Services\Customer\CustomerVehicleService($connection),
                    new \App\Services\Vehicle\VehicleMasterRepository(
                        $connection,
                        new \App\Services\Vehicle\VehicleMasterValidator()
                    )
                );
                $result = $processor->processRequest($estimateRequest);

                // Get estimate number for email
                $estimateStmt = $connection->pdo()->prepare('SELECT number FROM estimates WHERE id = :id');
                $estimateStmt->execute(['id' => $result['estimate_id']]);
                $estimateNumber = $estimateStmt->fetchColumn();
            } catch (\Throwable $e) {
                // If auto-processing fails, still continue to send notification emails
                error_log('Failed to auto-process estimate request #' . $estimateRequest->id . ': ' . $e->getMessage());
            }

            // Send email notifications
            try {
                $notificationsConfig = require __DIR__ . '/../config/notifications.php';
                $dispatcher = new \App\Support\Notifications\NotificationDispatcher(
                    $notificationsConfig,
                    new \App\Support\Notifications\TemplateEngine(),
                    new \App\Support\Notifications\NotificationLogRepository($connection)
                );

                // Prepare email data
                $emailData = [
                    'request_id' => $estimateRequest->id,
                    'submitted_at' => date('Y-m-d H:i:s'),
                    'customer_name' => $estimateRequest->name,
                    'customer_email' => $estimateRequest->email,
                    'customer_phone' => $estimateRequest->phone,
                    'customer_address' => $estimateRequest->address,
                    'customer_city' => $estimateRequest->city,
                    'customer_state' => $estimateRequest->state,
                    'customer_zip' => $estimateRequest->zip,
                ];

                // Add service address if different
                if (!$estimateRequest->service_address_same_as_customer && $estimateRequest->service_address) {
                    $emailData['service_address_different'] = true;
                    $emailData['service_address'] = $estimateRequest->service_address;
                    $emailData['service_city'] = $estimateRequest->service_city;
                    $emailData['service_state'] = $estimateRequest->service_state;
                    $emailData['service_zip'] = $estimateRequest->service_zip;
                }

                // Add vehicle info if provided
                if ($estimateRequest->vehicle_year && $estimateRequest->vehicle_make && $estimateRequest->vehicle_model) {
                    $emailData['vehicle_info'] = true;
                    $emailData['vehicle_year'] = $estimateRequest->vehicle_year;
                    $emailData['vehicle_make'] = $estimateRequest->vehicle_make;
                    $emailData['vehicle_model'] = $estimateRequest->vehicle_model;
                    if ($estimateRequest->vin) {
                        $emailData['vin'] = $estimateRequest->vin;
                    }
                    if ($estimateRequest->license_plate) {
                        $emailData['license_plate'] = $estimateRequest->license_plate;
                    }
                }

                // Add service type if selected
                if ($estimateRequest->service_type_name) {
                    $emailData['service_type'] = $estimateRequest->service_type_name;
                }

                // Add description if provided
                if ($estimateRequest->description) {
                    $emailData['description'] = $estimateRequest->description;
                }

                // Add photo count if photos uploaded
                $mediaFiles = $repository->getMedia($estimateRequest->id);
                if (count($mediaFiles) > 0) {
                    $emailData['photo_count'] = count($mediaFiles);
                }

                // Add estimate number if created
                if ($estimateNumber) {
                    $emailData['estimate_created'] = true;
                    $emailData['estimate_number'] = $estimateNumber;
                }

                // Send staff notification
                $staffEmail = $settingsRepository->get('notifications.estimate_request_email', $notificationsConfig['mail']['from_address'] ?? 'admin@example.com');
                if ($staffEmail) {
                    try {
                        $dispatcher->sendMail(
                            'estimate_request.staff_notification',
                            $staffEmail,
                            $emailData,
                            'New Estimate Request #' . $estimateRequest->id
                        );
                    } catch (\Throwable $e) {
                        error_log('Failed to send staff notification for estimate request #' . $estimateRequest->id . ': ' . $e->getMessage());
                    }
                }

                // Send customer confirmation email
                try {
                    $dispatcher->sendMail(
                        'estimate_request.customer_confirmation',
                        $estimateRequest->email,
                        $emailData,
                        'We Received Your Estimate Request'
                    );
                } catch (\Throwable $e) {
                    error_log('Failed to send customer confirmation for estimate request #' . $estimateRequest->id . ': ' . $e->getMessage());
                }
            } catch (\Throwable $e) {
                error_log('Failed to send estimate request notifications: ' . $e->getMessage());
            }

            return Response::json([
                'success' => true,
                'message' => 'Your estimate request has been submitted successfully. We will contact you shortly.',
                'request_id' => $estimateRequest->id,
                'estimate_id' => $estimateNumber ? $result['estimate_id'] : null,
            ]);
        } catch (\Throwable $e) {
            error_log('Estimate request submission error: ' . $e->getMessage());
            return Response::json([
                'error' => 'Failed to submit estimate request. Please try again.',
            ], 500);
        }
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

    // Authentication routes (public) - with adaptive rate limiting
    $router->post('/api/auth/login', function (Request $request) use (
        $authService,
        $jwtService,
        $recaptchaVerifier,
        $totpService,
        $loginLimiter,
        $rateLimitResponse
    ) {
        $email = $request->input('email');
        $password = $request->input('password');
        $recaptchaToken = $request->input('recaptcha_token');
        $identifier = (string) ($email ?? 'unknown');
        $ip = LoginRateLimiter::clientIp($request);

        $verifier = $recaptchaVerifier();
        $preCheck = $loginLimiter->check($identifier, $ip);
        if (!$preCheck->allowed) {
            $message = $preCheck->locked
                ? 'Account temporarily locked due to too many failed attempts.'
                : 'Too many login attempts. Please wait before retrying.';

            return $rateLimitResponse($preCheck, $message);
        }

        if ($preCheck->captchaRequired) {
            if (!$verifier->verify($recaptchaToken)) {
                $result = $loginLimiter->recordFailure($identifier, $ip);

                return Response::json(
                    $result->toPayload('Captcha verification required before attempting to login again.'),
                    429
                );
            }
        } elseif ($recaptchaToken && !$verifier->verify($recaptchaToken)) {
            return Response::badRequest('reCAPTCHA validation failed');
        }

        if (!$email || !$password) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            return Response::json($result->toPayload('Email and password required', 'validation_error'), 400);
        }

        $user = $authService->staffLogin((string) $email, (string) $password);

        if ($user === null) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            $message = $result->locked
                ? 'Account temporarily locked due to too many failed attempts.'
                : 'Invalid credentials';
            $status = $result->locked || $result->cooldown ? 429 : 401;
            $error = $result->locked || $result->cooldown ? 'rate_limited' : 'invalid_credentials';

            return Response::json($result->toPayload($message, $error), $status);
        }

        $loginLimiter->recordSuccess($identifier, $ip);

        // Start session for backwards compatibility
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $user = $enforceMandatoryTwoFactorSetup($user);

        if ($user->two_factor_enabled && $user->two_factor_secret) {
            $challengeToken = bin2hex(random_bytes(32));
            $_SESSION['2fa_challenges'][$challengeToken] = [
                'user_id' => $user->id,
                'type' => 'totp',
                'expires_at' => time() + 300,
            ];

            return Response::json([
                'status' => '2fa_required',
                'challenge_token' => $challengeToken,
                'message' => 'Two-factor authentication required',
            ]);
        }

        $_SESSION['user_id'] = $user->id;
        $_SESSION['user'] = $user->toArray();

        // Generate JWT tokens
        $accessToken = $jwtService->generateToken($user);
        $refreshToken = $jwtService->generateRefreshToken($user);

        return Response::json([
            'user' => $user->toArray(),
            'token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $jwtService->getTokenTtl(),
            'token_type' => 'Bearer',
            'message' => 'Login successful',
        ]);
    });

    $router->post('/api/auth/customer-login', function (Request $request) use (
        $authService,
        $jwtService,
        $recaptchaVerifier,
        $totpService,
        $loginLimiter,
        $rateLimitResponse
    ) {
        $email = $request->input('email');
        $password = $request->input('password');
        $recaptchaToken = $request->input('recaptcha_token');
        $identifier = (string) ($email ?? 'unknown');
        $ip = LoginRateLimiter::clientIp($request);

        $verifier = $recaptchaVerifier();
        $preCheck = $loginLimiter->check($identifier, $ip);
        if (!$preCheck->allowed) {
            $message = $preCheck->locked
                ? 'Account temporarily locked due to too many failed attempts.'
                : 'Too many login attempts. Please wait before retrying.';

            return $rateLimitResponse($preCheck, $message);
        }

        if ($preCheck->captchaRequired) {
            if (!$verifier->verify($recaptchaToken)) {
                $result = $loginLimiter->recordFailure($identifier, $ip);

                return Response::json(
                    $result->toPayload('Captcha verification required before attempting to login again.'),
                    429
                );
            }
        } elseif ($recaptchaToken && !$verifier->verify($recaptchaToken)) {
            return Response::badRequest('reCAPTCHA validation failed');
        }

        if (!$email || !$password) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            return Response::json($result->toPayload('Email and password required', 'validation_error'), 400);
        }

        $user = $authService->customerPortalLogin((string) $email, (string) $password);

        if ($user === null) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            $message = $result->locked
                ? 'Account temporarily locked due to too many failed attempts.'
                : 'Invalid credentials';
            $status = $result->locked || $result->cooldown ? 429 : 401;
            $error = $result->locked || $result->cooldown ? 'rate_limited' : 'invalid_credentials';

            return Response::json($result->toPayload($message, $error), $status);
        }

        $loginLimiter->recordSuccess($identifier, $ip);

        // Start session for backwards compatibility
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $user = $enforceMandatoryTwoFactorSetup($user);

        if ($user->two_factor_enabled && $user->two_factor_secret) {
            $challengeToken = bin2hex(random_bytes(32));
            $_SESSION['2fa_challenges'][$challengeToken] = [
                'user_id' => $user->id,
                'type' => 'totp',
                'expires_at' => time() + 300,
            ];

            return Response::json([
                'status' => '2fa_required',
                'challenge_token' => $challengeToken,
                'message' => 'Two-factor authentication required',
            ]);
        }

        $_SESSION['user_id'] = $user->id;
        $_SESSION['user'] = $user->toArray();
        $_SESSION['portal_nonce'] = $_SESSION['portal_nonce'] ?? bin2hex(random_bytes(16));

        // Generate JWT tokens
        $accessToken = $jwtService->generateToken($user);
        $refreshToken = $jwtService->generateRefreshToken($user);

        return Response::json([
            'user' => $user->toArray(),
            'token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $jwtService->getTokenTtl(),
            'token_type' => 'Bearer',
            'nonce' => $_SESSION['portal_nonce'],
            'api_base' => '/api',
            'message' => 'Login successful',
        ]);
    });

    $router->post('/api/auth/verify-2fa', function (Request $request) use ($authService, $jwtService, $totpService) {
        $challengeToken = $request->input('challenge_token');
        $code = $request->input('code');

        if (!$challengeToken || !$code) {
            return Response::badRequest('Challenge token and code are required');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $challenge = $_SESSION['2fa_challenges'][$challengeToken] ?? null;
        if (!$challenge || ($challenge['expires_at'] ?? 0) < time()) {
            return Response::unauthorized('Two-factor challenge has expired');
        }

        try {
            $user = $authService->findUserById((int) $challenge['user_id']);
        } catch (\Throwable $e) {
            return Response::unauthorized('Invalid challenge state');
        }

        if (!$user->two_factor_secret || !$totpService->verifyCode($user->two_factor_secret, (string) $code)) {
            return Response::unauthorized('Invalid authentication code');
        }

        unset($_SESSION['2fa_challenges'][$challengeToken]);

        $_SESSION['user_id'] = $user->id;
        $_SESSION['user'] = $user->toArray();

        $accessToken = $jwtService->generateToken($user);
        $refreshToken = $jwtService->generateRefreshToken($user);

        return Response::json([
            'user' => $user->toArray(),
            'token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $jwtService->getTokenTtl(),
            'token_type' => 'Bearer',
            'message' => 'Login successful',
        ]);
    })->middleware(Middleware::throttleStrict(5, 60));

    $router->post('/api/auth/customer-verify-2fa', function (Request $request) use ($authService, $jwtService, $totpService) {
        $challengeToken = $request->input('challenge_token');
        $code = $request->input('code');

        if (!$challengeToken || !$code) {
            return Response::badRequest('Challenge token and code are required');
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $challenge = $_SESSION['2fa_challenges'][$challengeToken] ?? null;
        if (!$challenge || ($challenge['expires_at'] ?? 0) < time()) {
            return Response::unauthorized('Two-factor challenge has expired');
        }

        try {
            $user = $authService->findUserById((int) $challenge['user_id']);
        } catch (\Throwable $e) {
            return Response::unauthorized('Invalid challenge state');
        }

        if (!$user->two_factor_secret || !$totpService->verifyCode($user->two_factor_secret, (string) $code)) {
            return Response::unauthorized('Invalid authentication code');
        }

        unset($_SESSION['2fa_challenges'][$challengeToken]);

        $_SESSION['user_id'] = $user->id;
        $_SESSION['user'] = $user->toArray();
        $_SESSION['portal_nonce'] = $_SESSION['portal_nonce'] ?? bin2hex(random_bytes(16));

        $accessToken = $jwtService->generateToken($user);
        $refreshToken = $jwtService->generateRefreshToken($user);

        return Response::json([
            'user' => $user->toArray(),
            'token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $jwtService->getTokenTtl(),
            'token_type' => 'Bearer',
            'nonce' => $_SESSION['portal_nonce'],
            'api_base' => '/api',
            'message' => 'Login successful',
        ]);
    })->middleware(Middleware::throttleStrict(5, 60));

    // Token refresh endpoint
    $router->post('/api/auth/refresh', function (Request $request) use ($jwtService) {
        $refreshToken = $request->input('refresh_token');

        if (!$refreshToken) {
            return Response::badRequest('Refresh token required');
        }

        $result = $jwtService->refreshTokens((string) $refreshToken);

        if ($result === null) {
            return Response::unauthorized('Invalid or expired refresh token');
        }

        return Response::json($result);
    })->middleware(Middleware::throttleStrict(10, 60));

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

    // 2FA Setup Flow - Initiate setup by generating secret and QR code
    $router->post('/api/auth/2fa/setup/initiate', function (Request $request) use ($totpService, $authService) {
        $user = $request->getAttribute('user');

        if (!$user) {
            return Response::unauthorized('Not authenticated');
        }

        // Generate a new TOTP secret
        $secret = $totpService->generateSecret();

        // Create QR code URL for TOTP apps
        // Format: otpauth://totp/Label?secret=SECRET&issuer=ISSUER
        $appName = env('APP_NAME', 'PHPArm');
        $qrCodeUrl = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s',
            rawurlencode($appName),
            rawurlencode($user->email),
            $secret,
            rawurlencode($appName)
        );

        // Store the secret temporarily in session until verified
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['2fa_setup_secret'] = $secret;
        $_SESSION['2fa_setup_user_id'] = $user->id;

        return Response::json([
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
            'message' => 'Scan the QR code with your authenticator app and enter the code to complete setup'
        ]);
    })->middleware(Middleware::auth());

    // 2FA Setup Flow - Complete setup by verifying code
    $router->post('/api/auth/2fa/setup/complete', function (Request $request) use ($totpService, $connection) {
        $user = $request->getAttribute('user');
        $code = $request->input('code');

        if (!$user) {
            return Response::unauthorized('Not authenticated');
        }

        if (!$code) {
            return Response::badRequest('Verification code is required');
        }

        // Get the secret from session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $secret = $_SESSION['2fa_setup_secret'] ?? null;
        $sessionUserId = $_SESSION['2fa_setup_user_id'] ?? null;

        if (!$secret || $sessionUserId !== $user->id) {
            return Response::badRequest('No pending 2FA setup found. Please initiate setup first.');
        }

        // Verify the code
        if (!$totpService->verifyCode($secret, (string) $code)) {
            return Response::unauthorized('Invalid verification code. Please try again.');
        }

        // Generate recovery codes
        $recoveryCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $recoveryCodes[] = bin2hex(random_bytes(4)); // 8-character recovery codes
        }

        // Save to database using UserRepository
        $userRepo = new App\Services\User\UserRepository($connection);
        $updatedUser = $userRepo->completeTwoFactorSetup($user->id, $secret, $recoveryCodes);

        // Clear session data
        unset($_SESSION['2fa_setup_secret']);
        unset($_SESSION['2fa_setup_user_id']);

        return Response::json([
            'message' => '2FA has been successfully enabled',
            'recovery_codes' => $recoveryCodes,
            'user' => [
                'id' => $updatedUser->id,
                'name' => $updatedUser->name,
                'email' => $updatedUser->email,
                'two_factor_enabled' => $updatedUser->two_factor_enabled,
                'two_factor_type' => $updatedUser->two_factor_type,
                'two_factor_setup_pending' => $updatedUser->two_factor_setup_pending
            ]
        ]);
    })->middleware(Middleware::auth());

    // Password reset request (forgot password)
    $router->post('/api/auth/forgot-password', function (Request $request) use (
        $authService,
        $connection,
        $authConfig,
        $recaptchaVerifier,
        $loginLimiter
    ) {
        $email = $request->input('email');
        $recaptchaToken = $request->input('recaptcha_token');
        $identifier = (string) ($email ?? 'unknown');
        $ip = LoginRateLimiter::clientIp($request);

        $verifier = $recaptchaVerifier();
        $preCheck = $loginLimiter->check($identifier, $ip);
        if (!$preCheck->allowed) {
            $message = $preCheck->locked
                ? 'Account temporarily locked due to too many failed attempts.'
                : 'Too many password reset attempts. Please wait before retrying.';

            return Response::json($preCheck->toPayload($message), 429);
        }

        if ($preCheck->captchaRequired) {
            if (!$verifier->verify($recaptchaToken)) {
                $result = $loginLimiter->recordFailure($identifier, $ip);

                return Response::json(
                    $result->toPayload('Captcha verification required before continuing.'),
                    429
                );
            }
        } elseif ($recaptchaToken && !$verifier->verify($recaptchaToken)) {
            return Response::badRequest('reCAPTCHA validation failed');
        }

        if (!$email) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            return Response::json($result->toPayload('Email is required', 'validation_error'), 400);
        }

        $token = $authService->requestPasswordReset((string) $email);

        // Send email if token was created (user exists)
        if ($token !== null) {
            $notificationsConfig = require __DIR__ . '/../config/notifications.php';
            $dispatcher = new \App\Support\Notifications\NotificationDispatcher(
                $notificationsConfig,
                new \App\Support\Notifications\TemplateEngine(),
                new \App\Support\Notifications\NotificationLogRepository($connection)
            );

            $appUrl = env('APP_URL', 'http://localhost:8080');
            $resetUrl = $appUrl . '/reset-password?token=' . urlencode($token->token);
            $expiryHours = round(($authConfig['passwords']['expire_minutes'] ?? 60) / 60, 1);

            try {
                $dispatcher->sendMail(
                    'auth.password_reset',
                    (string) $email,
                    ['reset_url' => $resetUrl, 'expiry_hours' => $expiryHours],
                    'Reset Your Password'
                );
            } catch (\Throwable $e) {
                error_log('Failed to send password reset email: ' . $e->getMessage());
            }
        }

        $loginLimiter->recordSuccess($identifier, $ip);

        // Always return success to prevent email enumeration
        return Response::json(['message' => 'If an account exists, a password reset link has been sent']);
    });

    // Reset password with token
    $router->post('/api/auth/reset-password', function (Request $request) use ($authService, $loginLimiter) {
        $token = $request->input('token');
        $password = $request->input('password');
        $identifier = 'reset-token:' . (string) ($token ?? 'none');
        $ip = LoginRateLimiter::clientIp($request);

        $preCheck = $loginLimiter->check($identifier, $ip);
        if (!$preCheck->allowed) {
            $message = $preCheck->locked
                ? 'Account temporarily locked due to too many failed attempts.'
                : 'Too many password reset attempts. Please wait before retrying.';

            return Response::json($preCheck->toPayload($message), 429);
        }

        if (!$token || !$password) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            return Response::json($result->toPayload('Token and password are required', 'validation_error'), 400);
        }

        $success = $authService->resetPassword((string) $token, (string) $password);

        if (!$success) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            return Response::json($result->toPayload('Invalid or expired token', 'invalid_token'), 400);
        }

        $loginLimiter->recordSuccess($identifier, $ip);

        return Response::json(['message' => 'Password reset successfully']);
    });

    // Verify email with token
    $router->post('/api/auth/verify-email', function (Request $request) use ($authService, $loginLimiter) {
        $token = $request->input('token');
        $identifier = 'verify-token:' . (string) ($token ?? 'none');
        $ip = LoginRateLimiter::clientIp($request);

        $preCheck = $loginLimiter->check($identifier, $ip);
        if (!$preCheck->allowed) {
            $message = $preCheck->locked
                ? 'Account temporarily locked due to too many failed attempts.'
                : 'Too many verification attempts. Please wait before retrying.';

            return Response::json($preCheck->toPayload($message), 429);
        }

        if (!$token) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            return Response::json($result->toPayload('Token is required', 'validation_error'), 400);
        }

        $success = $authService->verifyEmail((string) $token);

        if (!$success) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            return Response::json($result->toPayload('Invalid or expired verification token', 'invalid_token'), 400);
        }

        $loginLimiter->recordSuccess($identifier, $ip);

        return Response::json(['message' => 'Email verified successfully']);
    });

    // Accept invitation and set password
    $router->post('/api/auth/accept-invite', function (Request $request) use ($authService, $loginLimiter) {
        $token = $request->input('token');
        $password = $request->input('password');
        $identifier = 'invite-token:' . (string) ($token ?? 'none');
        $ip = LoginRateLimiter::clientIp($request);

        $preCheck = $loginLimiter->check($identifier, $ip);
        if (!$preCheck->allowed) {
            $message = $preCheck->locked
                ? 'Account temporarily locked due to too many failed attempts.'
                : 'Too many invitation attempts. Please wait before retrying.';

            return Response::json($preCheck->toPayload($message), 429);
        }

        if (!$token || !$password) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            return Response::json($result->toPayload('Token and password are required', 'validation_error'), 400);
        }

        $user = $authService->acceptInvitation((string) $token, (string) $password);

        if ($user === null) {
            $result = $loginLimiter->recordFailure($identifier, $ip);
            return Response::json($result->toPayload('Invalid or expired invitation token', 'invalid_token'), 400);
        }

        $loginLimiter->recordSuccess($identifier, $ip);

        return Response::json(['message' => 'Invitation accepted successfully']);
    });

    // Resend verification email
    $router->post('/api/auth/resend-verification', function (Request $request) use ($authService, $connection, $authConfig) {
        $user = $request->getAttribute('user');

        if (!$user || !($user instanceof \App\Models\User)) {
            return Response::unauthorized('Authentication required');
        }

        if ($user->email_verified) {
            return Response::json(['message' => 'Email is already verified']);
        }

        $token = $authService->issueVerificationToken($user->id);

        // Send verification email
        $notificationsConfig = require __DIR__ . '/../config/notifications.php';
        $dispatcher = new \App\Support\Notifications\NotificationDispatcher(
            $notificationsConfig,
            new \App\Support\Notifications\TemplateEngine(),
            new \App\Support\Notifications\NotificationLogRepository($connection)
        );

        $appUrl = env('APP_URL', 'http://localhost:8080');
        $verificationUrl = $appUrl . '/verify-email?token=' . urlencode($token->token);
        $expiryHours = $authConfig['verification']['token_ttl_hours'] ?? 48;

        try {
            $dispatcher->sendMail(
                'auth.email_verification',
                $user->email,
                ['name' => $user->name, 'verification_url' => $verificationUrl, 'expiry_hours' => $expiryHours],
                'Verify Your Email Address'
            );
        } catch (\Throwable $e) {
            error_log('Failed to send verification email: ' . $e->getMessage());
            return Response::serverError('Failed to send verification email');
        }

        return Response::json(['message' => 'Verification email has been sent']);
    })->middleware(Middleware::auth())
      ->middleware(Middleware::throttleStrict(3, 60));

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
    })->middleware(Middleware::auth())
      ->middleware(Middleware::role('customer'));

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

    $partnerDispatchService = new \App\Services\Integrations\PartnerDispatchService(
        $connection,
        $auditLogger,
        new \App\Services\Integrations\PartnerDispatchAdapterRegistry([
            new \App\Services\Integrations\AaaPartnerDispatchAdapter(),
            new \App\Services\Integrations\GeicoPartnerDispatchAdapter(),
            new \App\Services\Integrations\AgeroPartnerDispatchAdapter(),
        ]),
        new \App\Services\Integrations\PartnerEmailParser()
    );

    $router->post('/api/integrations/partners/{partner}/dispatch', function (Request $request) use ($partnerDispatchService) {
        if (!$request->isJson()) {
            return Response::badRequest('JSON payload required');
        }

        $partner = (string) $request->getAttribute('partner');
        $result = $partnerDispatchService->ingestApiDispatch($partner, $request->body());
        $status = $result['status'] === 'failed' ? 422 : 201;

        return Response::json($result, $status);
    });

    // Initialize AccessGate for protected routes
    $gate = new AccessGate(new RolePermissions($config['auth']['roles']));

    $cmsCacheService = new CMSCacheService($config['cms'] ?? []);
    // CMS controllers reuse the same gate and connection
    $cmsCategoryController = new CategoryController($connection, $gate);
    $cmsPageController = new PageController($connection, $gate, $cmsCacheService);
    $cmsMenuController = new MenuController($connection, $gate, $cmsCacheService);
    $cmsMediaController = new MediaController($connection, $gate, $cmsCacheService);

    // CMS API Controller for public page delivery
    $cmsAuthBridge = new \App\Services\CMS\CMSAuthBridge();
    $cmsApiController = new \App\Services\CMS\CMSApiController($connection, $cmsAuthBridge, $gate, $cmsCacheService);

    $resolveLocale = function (Request $request): string {
        $locale = $request->queryParam('locale');
        if (!empty($locale)) {
            return (string) $locale;
        }

        $acceptLanguage = $request->header('ACCEPT-LANGUAGE');
        if (!empty($acceptLanguage)) {
            return explode(',', (string) $acceptLanguage)[0];
        }

        return 'en';
    };

    // Public CMS content delivery endpoints
    $router->get('/api/cms/page/{slug}', function (Request $request) use ($cmsPageController, $cmsCacheService, $resolveLocale) {
        $slug = (string) $request->getAttribute('slug');
        $locale = $resolveLocale($request);
        $cacheKey = $cmsCacheService->buildKey('page', $slug, $locale, 'json');

        if ($cached = $cmsCacheService->get($cacheKey)) {
            return Response::json($cached);
        }

        $page = $cmsPageController->publishedPage($slug);

        if ($page === null) {
            return Response::notFound('Page not found');
        }

        if ($cmsCacheService->isEnabled()) {
            $cmsCacheService->set($cacheKey, $page, $cmsCacheService->defaultTtl());
        }

        return Response::json($page);
    });

    // Get fully rendered HTML for a page (for the SPA)
    $router->get('/api/cms/page/{slug}/rendered', function (Request $request) use ($cmsPageController, $cmsCacheService, $resolveLocale) {
        $slug = (string) $request->getAttribute('slug');
        $locale = $resolveLocale($request);
        $cacheKey = $cmsCacheService->buildKey('page', $slug, $locale, 'rendered');

        if ($cached = $cmsCacheService->get($cacheKey)) {
            return Response::json(['html' => $cached, 'page' => $cmsPageController->publishedPage($slug)]);
        }

        $html = $cmsPageController->renderPublishedPage($slug);

        if ($html === null) {
            return Response::notFound('Page not found');
        }

        if ($cmsCacheService->isEnabled()) {
            $cmsCacheService->set($cacheKey, $html, $cmsCacheService->defaultTtl());
        }

        $page = $cmsPageController->publishedPage($slug);

        return Response::json(['html' => $html, 'page' => $page]);
    });

    $router->get('/api/cms/menu/{slug}', function (Request $request) use ($cmsMenuController, $cmsCacheService, $resolveLocale) {
        $slug = (string) $request->getAttribute('slug');
        $locale = $resolveLocale($request);
        $cacheKey = $cmsCacheService->buildKey('menu', $slug, $locale, 'json');

        if ($cached = $cmsCacheService->get($cacheKey)) {
            return Response::json($cached);
        }

        $menu = $cmsMenuController->publishedMenu($slug);

        if ($menu === null) {
            return Response::notFound('Menu not found');
        }

        if ($cmsCacheService->isEnabled()) {
            $cmsCacheService->set($cacheKey, $menu, $cmsCacheService->defaultTtl());
        }

        return Response::json($menu);
    });

    $router->get('/cms/media/{slug}', function (Request $request) use ($cmsMediaController, $cmsCacheService, $resolveLocale) {
        $slug = (string) $request->getAttribute('slug');
        $locale = $resolveLocale($request);
        $cacheKey = $cmsCacheService->buildKey('media', $slug, $locale, 'json');

        if ($cached = $cmsCacheService->get($cacheKey)) {
            return Response::json($cached);
        }

        $media = $cmsMediaController->publishedMedia($slug);

        if ($media === null) {
            return Response::notFound('Media not found');
        }

        if ($cmsCacheService->isEnabled()) {
            $cmsCacheService->set($cacheKey, $media, $cmsCacheService->defaultTtl());
        }

        return Response::json($media);
    });

    // Public API endpoint for fetching CMS pages by slug (for frontend routing)
    $router->get('/api/cms/page/{slug}', function (Request $request) use ($cmsApiController) {
        $slug = (string) $request->getAttribute('slug');
        $page = $cmsApiController->getPageBySlug($slug);

        if ($page === null) {
            return Response::notFound('Page not found');
        }

        return Response::json($page);
    });

    // Dashboard routes (authenticated)
    $router->group([Middleware::auth()], function (Router $router) use ($config, $connection, $gate, $settingsRepository, $auditLogger) {

        $dashboardService = new \App\Services\Dashboard\DashboardService($connection);
        $dashboardController = new \App\Services\Dashboard\DashboardController($dashboardService);
        $inventoryPullRequestRepository = new \App\Services\Inventory\InventoryPullRequestRepository(
            $connection,
            $auditLogger
        );

        $router->get('/api/dashboard', function (Request $request) use ($dashboardController) {
            /** @var \App\Models\User|null $user */
            $user = $request->getAttribute('user');
            $params = [
                'preset' => $request->queryParam('preset'),
                'start' => $request->queryParam('start'),
                'end' => $request->queryParam('end'),
                'timezone' => $request->queryParam('timezone', 'UTC'),
                'role' => $user?->role,
            ];

            if ($user?->role === 'customer' && $user->customer_id !== null) {
                $params['customer_id'] = $user->customer_id;
            }

            $requestedTechnician = $request->queryParam('technician_id');
            if ($user?->role === 'technician') {
                $params['technician_id'] = $user->id;
            } elseif ($requestedTechnician !== null) {
                $params['technician_id'] = (int) $requestedTechnician;
            }

            $data = $dashboardController->handleKpis($params);
            return Response::json($data);
        });

        $router->get('/api/dashboard/charts', function (Request $request) use ($dashboardController) {
            /** @var \App\Models\User|null $user */
            $user = $request->getAttribute('user');
            $params = [
                'preset' => $request->queryParam('preset'),
                'start' => $request->queryParam('start'),
                'end' => $request->queryParam('end'),
                'timezone' => $request->queryParam('timezone', 'UTC'),
                'role' => $user?->role,
            ];

            if ($user?->role === 'customer' && $user->customer_id !== null) {
                $params['customer_id'] = $user->customer_id;
            }

            $requestedTechnician = $request->queryParam('technician_id');
            if ($user?->role === 'technician') {
                $params['technician_id'] = $user->id;
            } elseif ($requestedTechnician !== null) {
                $params['technician_id'] = (int) $requestedTechnician;
            }

            $data = $dashboardController->handleMonthlyTrends($params);
            return Response::json($data);
        });

        $router->get('/api/dashboard/charts/service-types', function (Request $request) use ($dashboardController) {
            /** @var \App\Models\User|null $user */
            $user = $request->getAttribute('user');
            $params = [
                'preset' => $request->queryParam('preset'),
                'start' => $request->queryParam('start'),
                'end' => $request->queryParam('end'),
                'timezone' => $request->queryParam('timezone', 'UTC'),
                'limit' => $request->queryParam('limit', 10),
                'role' => $user?->role,
            ];

            if ($user?->role === 'customer' && $user->customer_id !== null) {
                $params['customer_id'] = $user->customer_id;
            }

            $requestedTechnician = $request->queryParam('technician_id');
            if ($user?->role === 'technician') {
                $params['technician_id'] = $user->id;
            } elseif ($requestedTechnician !== null) {
                $params['technician_id'] = (int) $requestedTechnician;
            }

            $data = $dashboardController->handleServiceTypeBreakdown($params);
            return Response::json($data);
        });

        $router->get('/api/dashboard/workorders/wip-aging', function (Request $request) use ($dashboardController) {
            /** @var \App\Models\User|null $user */
            $user = $request->getAttribute('user');
            $params = [
                'role' => $user?->role,
            ];

            if ($user?->role === 'customer' && $user->customer_id !== null) {
                $params['customer_id'] = $user->customer_id;
            }

            $requestedTechnician = $request->queryParam('technician_id');
            if ($user?->role === 'technician') {
                $params['technician_id'] = $user->id;
            } elseif ($requestedTechnician !== null) {
                $params['technician_id'] = (int) $requestedTechnician;
            }

            $data = $dashboardController->handleWipAging($params);
            return Response::json($data);
        });

        $router->get('/api/dashboard/inventory/pull-requests', function (Request $request) use ($inventoryPullRequestRepository) {
            $statusesParam = $request->queryParam('statuses');
            $statuses = $statusesParam
                ? array_filter(array_map('trim', explode(',', $statusesParam)))
                : [
                    \App\Models\InventoryPullRequest::STATUS_PENDING,
                    \App\Models\InventoryPullRequest::STATUS_ORDERED,
                    \App\Models\InventoryPullRequest::STATUS_RECEIVED,
                ];

            $limit = (int) ($request->queryParam('limit') ?? 5);

            $data = $inventoryPullRequestRepository->getDashboardNotifications($statuses, $limit);
            return Response::json($data);
        });

        // PartsTech integration
        $partsTechService = new \App\Services\Integrations\PartsTechService(
            $settingsRepository,
            $auditLogger
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
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $auditLogger) {

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
            try {
                $data = $customerController->store($user, $request->body());
                return Response::created($data);
            } catch (\InvalidArgumentException $e) {
                error_log('Customer creation validation error: ' . $e->getMessage());
                error_log('Request body: ' . json_encode($request->body()));
                return Response::badRequest($e->getMessage());
            } catch (\Throwable $e) {
                error_log('Customer creation error: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                throw $e;
            }
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

        $router->get('/api/customers/{id}/vehicles/{vehicleId}', function (Request $request) use ($customerController) {
            $user = $request->getAttribute('user');
            $customerId = (int) $request->getAttribute('id');
            $vehicleId = (int) $request->getAttribute('vehicleId');

            $data = $customerController->getVehicle($user, $customerId, $vehicleId);
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
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $auditLogger) {

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
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $auditLogger) {

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
        $customerVehicleService = new \App\Services\Customer\CustomerVehicleService($connection);

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

        $router->get('/api/vehicles', function (Request $request) use ($customerVehicleService, $gate) {
            $user = $request->getAttribute('user');
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'customer_query' => $request->queryParam('customer_query'),
                'year' => $request->queryParam('year'),
                'make' => $request->queryParam('make'),
                'model' => $request->queryParam('model'),
                'term' => $request->queryParam('term'),
            ];

            if ($user?->role === 'customer' && $user->customer_id !== null) {
                $filters['customer_id'] = $user->customer_id;
                $filters['customer_query'] = null;
            }

            $gate->assert($user, 'vehicles.view');
            $data = $customerVehicleService->searchVehicles($filters);
            return Response::json($data);
        });

        // Vehicle master search (for autocomplete)
        $router->get('/api/vehicle-master/search', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $query = $request->queryParam('query', '');
            $limit = (int) $request->queryParam('limit', 20);

            $data = $vehicleController->search($user, $query, $limit);
            return Response::json(['data' => $data]);
        });

$router->get('/api/vehicles/{id}', function (Request $request) use ($vehicleController) {
    $user = $request->getAttribute('user');
    $id = (int) $request->getAttribute('id');
    $data = $vehicleController->show($user, $id);
    return Response::json($data);
});
        $router->post('/api/vehicles', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $data = $vehicleController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/vehicles/{id}', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $vehicleController->update($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/vehicles/{id}', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $vehicleController->destroy($user, $id);
            return Response::noContent();
        });

        // CSV upload endpoint
        $router->post('/api/vehicles/upload-csv', function (Request $request) use ($vehicleController) {
            $user = $request->getAttribute('user');
            $data = $vehicleController->uploadCsv($user, $request);
            return Response::json($data);
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
        $stockOrderRepository = new \App\Services\Inventory\InventoryStockOrderRepository($connection);
        $lowStockService = new \App\Services\Inventory\InventoryLowStockService($inventoryRepository, $stockOrderRepository);
        $inventoryController = new \App\Services\Inventory\InventoryItemController($inventoryRepository, $gate, null, $lowStockService);
        $inventoryLookupService = new \App\Services\Inventory\InventoryLookupService($connection);
        $inventoryLookupController = new \App\Services\Inventory\InventoryLookupController($inventoryLookupService, $gate);
        $stockOrderController = new \App\Services\Inventory\InventoryStockOrderController($stockOrderRepository, $gate);

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

        $router->get('/api/inventory/stock-orders', function (Request $request) use ($stockOrderController) {
            $user = $request->getAttribute('user');
            $params = [
                'status' => $request->queryParam('status'),
                'inventory_item_id' => $request->queryParam('inventory_item_id'),
                'query' => $request->queryParam('query'),
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ];

            $data = $stockOrderController->index($user, $params);

            return Response::json($data);
        });

        $router->get('/api/inventory/stock-orders/{id}', function (Request $request) use ($stockOrderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $stockOrderController->show($user, $id);

            return Response::json($data);
        });

        $router->post('/api/inventory/stock-orders', function (Request $request) use ($stockOrderController) {
            $user = $request->getAttribute('user');
            $data = $stockOrderController->store($user, $request->body());

            return Response::created($data);
        });

        $router->put('/api/inventory/stock-orders/{id}', function (Request $request) use ($stockOrderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $stockOrderController->update($user, $id, $request->body());

            return Response::json($data);
        });

        $router->get('/api/inventory/{type:categories|vendors|locations}', function (Request $request) use ($inventoryLookupController) {
            $user = $request->getAttribute('user');
            $type = (string) $request->getAttribute('type');
            $filters = [
                'parts_supplier' => $request->queryParam('parts_supplier') === 'true',
            ];

            $data = $inventoryLookupController->index($user, $type, $filters);
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

        // CSV Export
        $router->get('/api/inventory/export', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $filters = [
                'query' => $request->queryParam('query'),
                'category' => $request->queryParam('category'),
                'location' => $request->queryParam('location'),
                'low_stock_only' => $request->queryParam('low_stock') === 'true',
            ];

            $csv = $inventoryController->export($user, $filters);

            return new Response(
                200,
                [
                    'Content-Type' => 'text/csv',
                    'Content-Disposition' => 'attachment; filename="inventory_export_' . date('Y-m-d') . '.csv"'
                ],
                $csv
            );
        });

        // CSV Import
        $router->post('/api/inventory/import', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $body = $request->body();

            $csv = $body['csv'] ?? '';
            $updateExisting = isset($body['update_existing']) && $body['update_existing'] === true;

            if (empty($csv)) {
                return Response::json(['error' => 'CSV content is required'], 422);
            }

            $result = $inventoryController->import($user, $csv, $updateExisting);
            return Response::json($result);
        });

        $router->post('/api/inventory/{type:categories|vendors|locations}', function (Request $request) use ($inventoryLookupController) {
            $user = $request->getAttribute('user');
            $type = (string) $request->getAttribute('type');

            $data = $inventoryLookupController->store($user, $type, $request->body());
            return Response::created($data);
        });

        $router->put('/api/inventory/{type:categories|vendors|locations}/{id}', function (Request $request) use ($inventoryLookupController) {
            $user = $request->getAttribute('user');
            $type = (string) $request->getAttribute('type');
            $id = (int) $request->getAttribute('id');

            $data = $inventoryLookupController->update($user, $type, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/inventory/{type:categories|vendors|locations}/{id}', function (Request $request) use ($inventoryLookupController) {
            $user = $request->getAttribute('user');
            $type = (string) $request->getAttribute('type');
            $id = (int) $request->getAttribute('id');

            $inventoryLookupController->destroy($user, $type, $id);
            return Response::noContent();
        });

        // Inventory search for parts (with optional vehicle compatibility filter)
        $router->get('/api/inventory/search-parts', function (Request $request) use ($inventoryController) {
            error_log('[INVENTORY SEARCH DEBUG] Route handler called');

            $user = $request->getAttribute('user');
            error_log('[INVENTORY SEARCH DEBUG] User ID: ' . ($user ? $user->id : 'NULL'));

            $params = [
                'query' => $request->queryParam('query'),
                'vehicle_master_id' => $request->queryParam('vehicle_master_id'),
                'limit' => $request->queryParam('limit'),
            ];
            error_log('[INVENTORY SEARCH DEBUG] Query param: ' . ($params['query'] ?? 'NULL'));
            error_log('[INVENTORY SEARCH DEBUG] Limit param: ' . ($params['limit'] ?? 'NULL'));

            $data = $inventoryController->searchParts($user, $params);
            error_log('[INVENTORY SEARCH DEBUG] Results count: ' . (is_array($data) ? count($data) : 'NOT ARRAY - ' . gettype($data)));
            error_log('[INVENTORY SEARCH DEBUG] Results: ' . json_encode($data));

            $response = ['data' => $data];
            error_log('[INVENTORY SEARCH DEBUG] Response structure: ' . json_encode($response));

            return Response::json($response);
        });

        // Find inventory by SKU (for auto-populate)
        $router->get('/api/inventory/by-sku', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $sku = (string) $request->queryParam('sku');

            if ($sku === '') {
                return Response::json(['error' => 'SKU is required'], 422);
            }

            $data = $inventoryController->findBySku($user, $sku);
            if ($data === null) {
                return Response::json(['error' => 'Item not found'], 404);
            }
            return Response::json($data);
        });

        $router->get('/api/inventory/by-sku/{sku:.+}', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $sku = (string) $request->getAttribute('sku');

            $data = $inventoryController->findBySku($user, $sku);
            if ($data === null) {
                return Response::json(['error' => 'Item not found'], 404);
            }
            return Response::json($data);
        });

        // Find inventory by barcode or UPC
        $router->get('/api/inventory/by-barcode', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $code = (string) $request->queryParam('code');
            $scanType = (string) ($request->queryParam('scan_type') ?? 'inventory_lookup');
            $workorderId = $request->queryParam('workorder_id') ? (int) $request->queryParam('workorder_id') : null;
            $invoiceId = $request->queryParam('invoice_id') ? (int) $request->queryParam('invoice_id') : null;

            if ($code === '') {
                return Response::json(['error' => 'Barcode is required'], 422);
            }

            $data = $inventoryController->findByBarcode($user, $code, $scanType, $workorderId, $invoiceId);
            return Response::json($data);
        });

        // Post version for barcode lookup (for scanner input)
        $router->post('/api/inventory/scan-barcode', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $body = $request->body();
            $code = (string) ($body['code'] ?? '');
            $scanType = (string) ($body['scan_type'] ?? 'inventory_lookup');
            $workorderId = isset($body['workorder_id']) ? (int) $body['workorder_id'] : null;
            $invoiceId = isset($body['invoice_id']) ? (int) $body['invoice_id'] : null;

            if ($code === '') {
                return Response::json(['error' => 'Barcode is required'], 422);
            }

            $data = $inventoryController->findByBarcode($user, $code, $scanType, $workorderId, $invoiceId);
            return Response::json($data);
        });

        // Vehicle compatibility routes
        $router->get('/api/inventory/{id}/vehicle-compatibility', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $inventoryController->getVehicleCompatibility($user, $id);
            return Response::json(['data' => $data]);
        });

        $router->post('/api/inventory/{id}/vehicle-compatibility', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $inventoryController->addVehicleCompatibility($user, $id, $request->body());
            return Response::json($data, 201);
        });

        $router->post('/api/inventory/{id}/vehicle-compatibility/bulk', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $inventoryController->bulkAddVehicleCompatibility($user, $id, $request->body());
            return Response::json($data, 201);
        });

        $router->delete('/api/inventory/{id}/vehicle-compatibility/{vehicleMasterId}', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $vehicleMasterId = (int) $request->getAttribute('vehicleMasterId');

            $inventoryController->removeVehicleCompatibility($user, $id, $vehicleMasterId);
            return Response::noContent();
        });

        // Search parts with compatibility highlighting
        $router->get('/api/inventory/search-with-compatibility', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $params = [
                'query' => $request->queryParam('query'),
                'vehicle_master_id' => $request->queryParam('vehicle_master_id'),
                'limit' => $request->queryParam('limit'),
            ];

            $data = $inventoryController->searchWithCompatibility($user, $params);
            return Response::json(['data' => $data]);
        });

        // Get compatible parts for a vehicle
        $router->get('/api/inventory/compatible-parts/{vehicleMasterId}', function (Request $request) use ($inventoryController) {
            $user = $request->getAttribute('user');
            $vehicleMasterId = (int) $request->getAttribute('vehicleMasterId');
            $limit = (int) ($request->queryParam('limit') ?? 100);

            $data = $inventoryController->getCompatibleParts($user, $vehicleMasterId, $limit);
            return Response::json(['data' => $data]);
        });
    });

    // Core Return Tracking routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $auditLogger) {
        $coreReturnService = new \App\Services\Inventory\CoreReturnService($connection, $auditLogger);
        $coreReturnController = new \App\Services\Inventory\CoreReturnController($coreReturnService, $gate);

        // List core returns with filters
        $router->get('/api/core-returns', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $params = $request->queryParams();
            $data = $coreReturnController->index($user, $params);
            return Response::json($data);
        });

        // Get core returns summary (for dashboard)
        $router->get('/api/core-returns/summary', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $data = $coreReturnController->summary($user);
            return Response::json($data);
        });

        // Get core tracking status
        $router->get('/api/core-returns/status', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $data = $coreReturnController->status($user);
            return Response::json($data);
        });

        // Get single core return
        $router->get('/api/core-returns/{id}', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $coreReturnController->show($user, $id);
            return Response::json($data);
        });

        // Create core return
        $router->post('/api/core-returns', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $data = $coreReturnController->store($user, $request->body());
            return Response::json($data, 201);
        });

        // Receive core from customer
        $router->post('/api/core-returns/{id}/receive-from-customer', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $coreReturnController->receiveFromCustomer($user, $id, $request->body());
            return Response::json($data);
        });

        // Credit customer for core
        $router->post('/api/core-returns/{id}/credit-customer', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $coreReturnController->creditCustomer($user, $id, $request->body());
            return Response::json($data);
        });

        // Return core to vendor
        $router->post('/api/core-returns/{id}/return-to-vendor', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $coreReturnController->returnToVendor($user, $id, $request->body());
            return Response::json($data);
        });

        // Record vendor credit
        $router->post('/api/core-returns/{id}/vendor-credit', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $coreReturnController->vendorCredit($user, $id, $request->body());
            return Response::json($data);
        });

        // Waive core
        $router->post('/api/core-returns/{id}/waive', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $coreReturnController->waive($user, $id, $request->body());
            return Response::json($data);
        });

        // Expire core
        $router->post('/api/core-returns/{id}/expire', function (Request $request) use ($coreReturnController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $coreReturnController->expire($user, $id, $request->body());
            return Response::json($data);
        });
    });

    // Inventory Pull Requests routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $auditLogger) {
        $messagingNotifications = new \App\Services\Messaging\MessagingNotificationService(
            $connection,
            new \App\Services\Messaging\MessagingService($connection)
        );
        $pullRequestRepository = new \App\Services\Inventory\InventoryPullRequestRepository(
            $connection,
            $auditLogger,
            $messagingNotifications
        );
        $pullRequestController = new \App\Services\Inventory\InventoryPullRequestController($pullRequestRepository, $gate);

        // List all pull requests with filters
        $router->get('/api/inventory/pull-requests', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $params = [
                'workorder_id' => $request->queryParam('workorder_id'),
                'status' => $request->queryParam('status'),
                'request_type' => $request->queryParam('request_type'),
                'pending_only' => $request->queryParam('pending_only') === 'true',
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ];
            $data = $pullRequestController->index($user, $params);
            return Response::json($data);
        });

        // Get pull requests summary (for dashboard)
        $router->get('/api/inventory/pull-requests/summary', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $data = $pullRequestController->summary($user);
            return Response::json($data);
        });

        // Get single pull request
        $router->get('/api/inventory/pull-requests/{id}', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $pullRequestController->show($user, $id);
            return Response::json($data);
        });

        // Create pull request
        $router->post('/api/inventory/pull-requests', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $data = $pullRequestController->store($user, $request->body());
            return Response::created($data);
        });

        // Update pull request
        $router->put('/api/inventory/pull-requests/{id}', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $pullRequestController->update($user, $id, $request->body());
            return Response::json($data);
        });

        // Mark as pulled from inventory
        $router->post('/api/inventory/pull-requests/{id}/pull', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $pullRequestController->pull($user, $id, $request->body());
            return Response::json($data);
        });

        // Mark as ordered
        $router->post('/api/inventory/pull-requests/{id}/order', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $pullRequestController->order($user, $id, $request->body());
            return Response::json($data);
        });

        // Mark as received
        $router->post('/api/inventory/pull-requests/{id}/receive', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $pullRequestController->receive($user, $id, $request->body());
            return Response::json($data);
        });

        // Cancel pull request
        $router->post('/api/inventory/pull-requests/{id}/cancel', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $pullRequestController->cancel($user, $id);
            return Response::json($data);
        });

        // Delete pull request
        $router->delete('/api/inventory/pull-requests/{id}', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $pullRequestController->destroy($user, $id);
            return Response::noContent();
        });

        // Get pull requests for a specific workorder
        $router->get('/api/workorders/{id}/pull-requests', function (Request $request) use ($pullRequestController) {
            $user = $request->getAttribute('user');
            $workorderId = (int) $request->getAttribute('id');
            $data = $pullRequestController->getByWorkorder($user, $workorderId);
            return Response::json($data);
        });

        // Parts Cart routes (PartsTech integration)
        $partsTechAdapter = new \App\Services\Integrations\PartsTechAdapter($connection);
        $partsCartService = new \App\Services\Inventory\PartsCartService($connection, $partsTechAdapter, $auditLogger);
        $partsCartController = new \App\Services\Inventory\PartsCartController($partsCartService, $gate);

        // Get or create parts cart for workorder
        $router->get('/api/workorders/{id}/parts-cart', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $workorderId = (int) $request->getAttribute('id');
            $data = $partsCartController->getOrCreate($user, $workorderId);
            return Response::json($data);
        });

        // Get parts cart by ID
        $router->get('/api/parts-carts/{id}', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $partsCartController->show($user, $id);
            return Response::json($data);
        });

        // Add item to cart
        $router->post('/api/parts-carts/{id}/items', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $partsCartController->addItem($user, $id, $request->body());
            return Response::created($data);
        });

        // Add item from inventory
        $router->post('/api/parts-carts/{id}/items/from-inventory', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $partsCartController->addFromInventory($user, $id, $request->body());
            return Response::created($data);
        });

        // Add item from PartsTech
        $router->post('/api/parts-carts/{id}/items/from-partstech', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $partsCartController->addFromPartsTech($user, $id, $request->body());
            return Response::created($data);
        });

        // Update cart item
        $router->patch('/api/parts-carts/{cartId}/items/{itemId}', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $cartId = (int) $request->getAttribute('cartId');
            $itemId = (int) $request->getAttribute('itemId');
            $data = $partsCartController->updateItem($user, $cartId, $itemId, $request->body());
            return Response::json($data);
        });

        // Remove cart item
        $router->delete('/api/parts-carts/{cartId}/items/{itemId}', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $cartId = (int) $request->getAttribute('cartId');
            $itemId = (int) $request->getAttribute('itemId');
            $partsCartController->removeItem($user, $cartId, $itemId);
            return Response::noContent();
        });

        // Submit cart for approval
        $router->post('/api/parts-carts/{id}/submit', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $partsCartController->submit($user, $id);
            return Response::json($data);
        });

        // Approve cart
        $router->post('/api/parts-carts/{id}/approve', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $partsCartController->approve($user, $id);
            return Response::json($data);
        });

        // Reject cart
        $router->post('/api/parts-carts/{id}/reject', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $partsCartController->reject($user, $id, $request->body());
            return Response::json($data);
        });

        // Place order
        $router->post('/api/parts-carts/{id}/order', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $partsCartController->order($user, $id);
            return Response::json($data);
        });

        // Mark as received
        $router->post('/api/parts-carts/{id}/receive', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $partsCartController->receive($user, $id, $request->body());
            return Response::json($data);
        });

        // Cancel cart
        $router->post('/api/parts-carts/{id}/cancel', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $partsCartController->cancel($user, $id, $request->body());
            return Response::json($data);
        });

        // PartsTech search
        $router->get('/api/parts-carts/partstech/search', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $params = [
                'q' => $request->queryParam('q'),
                'year' => $request->queryParam('year'),
                'make' => $request->queryParam('make'),
                'model' => $request->queryParam('model'),
                'limit' => $request->queryParam('limit'),
            ];
            $data = $partsCartController->searchPartsTech($user, $params);
            return Response::json($data);
        });

        // PartsTech status
        $router->get('/api/parts-carts/partstech/status', function (Request $request) use ($partsCartController) {
            $user = $request->getAttribute('user');
            $data = $partsCartController->partsTechStatus($user);
            return Response::json($data);
        });
    });

    // Roadside assistance routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {
        $messagingNotifications = new \App\Services\Messaging\MessagingNotificationService(
            $connection,
            new \App\Services\Messaging\MessagingService($connection)
        );
        $roadsideService = new \App\Services\Roadside\RoadsideService($messagingNotifications);
        $roadsideController = new \App\Services\Roadside\RoadsideController($roadsideService, $gate);

        $router->get('/api/roadside/dashboard', function (Request $request) use ($roadsideController) {
            $user = $request->getAttribute('user');
            $data = $roadsideController->dashboard($user);
            return Response::json($data);
        });

        $router->get('/api/roadside/requests', function (Request $request) use ($roadsideController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'priority' => $request->queryParam('priority'),
            ];
            $data = $roadsideController->listRequests($user, $filters);
            return Response::json($data);
        });

        $router->post('/api/roadside/requests', function (Request $request) use ($roadsideController) {
            $user = $request->getAttribute('user');
            $data = $roadsideController->createRequest($user, $request->body());
            return Response::created($data);
        });
    });

    // Estimate routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $auditLogger) {

        $bundleController = new \App\Services\Estimate\BundleController(
            new \App\Services\Estimate\BundleService($connection),
            $gate
        );

        $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection, $auditLogger);
        $estimateEditor = new \App\Services\Estimate\EstimateEditorService($connection, $auditLogger);
        $invoiceService = new \App\Services\Invoice\InvoiceService($connection, $auditLogger);
        $estimateController = new \App\Services\Estimate\EstimateController(
            $estimateRepository,
            $gate,
            $estimateEditor,
            $invoiceService
        );

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

        $router->get('/api/estimates/{id}', function (Request $request) use ($estimateController, $connection) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $estimateController->show($user, $id);

            // Enrich with customer data
            if (!empty($data['customer_id'])) {
                $stmt = $connection->pdo()->prepare('SELECT id, first_name, last_name, CONCAT(first_name, " ", last_name) AS name, email, phone FROM customers WHERE id = :id');
                $stmt->execute(['id' => $data['customer_id']]);
                $customer = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($customer) {
                    $data['customer'] = $customer;
                }
            }

            // Enrich with vehicle data
            if (!empty($data['vehicle_id'])) {
                $stmt = $connection->pdo()->prepare('SELECT id, year, make, model, vin, license_plate FROM customer_vehicles WHERE id = :id');
                $stmt->execute(['id' => $data['vehicle_id']]);
                $vehicle = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($vehicle) {
                    $data['vehicle'] = $vehicle;
                }
            }

            // Enrich with technician data
            if (!empty($data['technician_id'])) {
                $stmt = $connection->pdo()->prepare('SELECT id, name, email FROM users WHERE id = :id');
                $stmt->execute(['id' => $data['technician_id']]);
                $technician = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($technician) {
                    $data['technician'] = $technician;
                }
            }

            // Add estimate jobs and items
            $jobsStmt = $connection->pdo()->prepare('SELECT * FROM estimate_jobs WHERE estimate_id = :estimate_id ORDER BY display_order ASC');
            $jobsStmt->execute(['estimate_id' => $id]);
            $jobRows = $jobsStmt->fetchAll(\PDO::FETCH_ASSOC);

            $itemStmt = $connection->pdo()->prepare('SELECT * FROM estimate_items WHERE estimate_job_id = :job_id ORDER BY id ASC');
            $jobs = [];
            foreach ($jobRows as $jobRow) {
                $itemStmt->execute(['job_id' => $jobRow['id']]);
                $jobRow['items'] = $itemStmt->fetchAll(\PDO::FETCH_ASSOC);
                $jobs[] = $jobRow;
            }
            $data['jobs'] = $jobs;

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

        $router->delete('/api/estimates/{id}', function (Request $request) use ($estimateController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $success = $estimateController->delete($user, $id);
            return $success ? Response::noContent() : Response::notFound(['error' => 'Estimate not found']);
        });

        $router->post('/api/estimates/{id}/reject', function (Request $request) use ($estimateController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $reason = $request->body()['reason'] ?? null;

            $data = $estimateController->reject($user, $id, $reason);
            return Response::json($data);
        });

        $router->post('/api/estimates/{id}/approve', function (Request $request) use ($estimateController, $connection, $auditLogger, $gate) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $reason = $request->body()['reason'] ?? null;

            $data = $estimateController->approve($user, $id, $reason);

            // Auto-create workorder after approval
            if ($data) {
                try {
                    $workorderRepository = new \App\Services\Workorder\WorkorderRepository($connection, $auditLogger);
                    $workorderService = new \App\Services\Workorder\WorkorderService($connection, $workorderRepository, $auditLogger);

                    // Check if workorder already exists
                    $existingWorkorder = $workorderRepository->findByEstimateId($id);
                    if ($existingWorkorder === null) {
                        $workorder = $workorderService->createFromEstimate($id, null, $user->id);
                        $data['workorder'] = $workorder->toArray();
                    }
                } catch (\Exception $e) {
                    // Log error but don't fail the approval
                    error_log('Auto workorder creation failed: ' . $e->getMessage());
                }
            }

            return Response::json($data);
        });

        $router->post('/api/estimates/{id}/decline', function (Request $request) use ($estimateController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $reason = $request->body()['reason'] ?? null;

            $data = $estimateController->reject($user, $id, $reason);
            return Response::json($data);
        });

        $router->patch('/api/estimates/{id}/items/status', function (Request $request) use ($estimateController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $estimateController->updateItemStatuses($user, $id, $request->body());
            return Response::json($data);
        });

        $router->post('/api/estimates/{id}/merge-into-invoice', function (Request $request) use ($estimateController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $estimateController->mergeIntoInvoice($user, $id, $request->body());
            return Response::json($data);
        });

        // Share estimate via email
        $router->post('/api/estimates/{id}/share/email', function (Request $request) use ($connection, $auditLogger) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $body = $request->body();

            if (empty($body['email'])) {
                return Response::badRequest(['error' => 'Email address is required']);
            }

            $notificationConfig = require __DIR__ . '/../config/notifications.php';
            $templateEngine = new \App\Support\Notifications\TemplateEngine();
            $notificationLogs = new \App\Support\Notifications\NotificationLogRepository($connection);
            $notifications = new \App\Support\Notifications\NotificationDispatcher($notificationConfig, $templateEngine, $notificationLogs);
            $messagingNotifications = new \App\Services\Messaging\MessagingNotificationService(
                $connection,
                new \App\Services\Messaging\MessagingService($connection)
            );

            $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection, $auditLogger);
            $estimateEditor = new \App\Services\Estimate\EstimateEditorService($connection, $auditLogger);
            $approvalAudit = new \App\Services\Approval\ApprovalAuditService($connection);
            $linkService = new \App\Services\Estimate\EstimatePublicLinkService($connection, $estimateRepository, $estimateEditor, $auditLogger, $approvalAudit);
            $shareService = new \App\Services\Estimate\EstimateShareService(
                $connection,
                $estimateRepository,
                $linkService,
                $notifications,
                $messagingNotifications
            );

            $baseUrl = rtrim($request->header('Origin') ?? $request->header('Referer') ?? 'http://localhost', '/');
            $result = $shareService->shareViaEmail($id, $body['email'], $baseUrl, $user->id);

            return Response::json($result);
        });

        // Share estimate via SMS
        $router->post('/api/estimates/{id}/share/sms', function (Request $request) use ($connection, $auditLogger) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $body = $request->body();

            if (empty($body['phone'])) {
                return Response::badRequest(['error' => 'Phone number is required']);
            }

            $notificationConfig = require __DIR__ . '/../config/notifications.php';
            $templateEngine = new \App\Support\Notifications\TemplateEngine();
            $notificationLogs = new \App\Support\Notifications\NotificationLogRepository($connection);
            $notifications = new \App\Support\Notifications\NotificationDispatcher($notificationConfig, $templateEngine, $notificationLogs);
            $messagingNotifications = new \App\Services\Messaging\MessagingNotificationService(
                $connection,
                new \App\Services\Messaging\MessagingService($connection)
            );

            $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection, $auditLogger);
            $estimateEditor = new \App\Services\Estimate\EstimateEditorService($connection, $auditLogger);
            $approvalAudit = new \App\Services\Approval\ApprovalAuditService($connection);
            $linkService = new \App\Services\Estimate\EstimatePublicLinkService($connection, $estimateRepository, $estimateEditor, $auditLogger, $approvalAudit);
            $shareService = new \App\Services\Estimate\EstimateShareService(
                $connection,
                $estimateRepository,
                $linkService,
                $notifications,
                $messagingNotifications
            );

            $baseUrl = rtrim($request->header('Origin') ?? $request->header('Referer') ?? 'http://localhost', '/');
            $result = $shareService->shareViaSms($id, $body['phone'], $baseUrl, $user->id);

            return Response::json($result);
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

    $router->get('/track/{token}', function (Request $request) use ($connection) {
        $trackingService = new \App\Services\Tracking\TrackingService($connection);
        $data = $trackingService->getTrackingView((string) $request->getAttribute('token'));
        return Response::json($data);
    });

    // Public estimate rejection reasons
    $router->get('/api/public/estimate/rejection-reasons', function () use ($connection) {
        $settingsRepo = new \App\Support\SettingsRepository($connection);
        $reasons = $settingsRepo->get('estimates.rejection_reasons');

        // Default reasons if not configured
        if (empty($reasons) || !is_array($reasons)) {
            $reasons = [
                'Price too high',
                'Found a better deal elsewhere',
                'Decided not to proceed with repairs',
                'Going to a different shop',
                'Vehicle no longer owned',
                'Other'
            ];
        }

        return Response::json(['reasons' => $reasons]);
    });

    // Public estimate routes
    $router->get('/api/public/estimate', function (Request $request) use ($connection, $auditLogger) {
        $token = $request->queryParam('token');
        if (!$token) {
            return Response::badRequest(['error' => 'Token is required']);
        }

        $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection, $auditLogger);
        $estimateEditor = new \App\Services\Estimate\EstimateEditorService($connection, $auditLogger);
        $approvalAudit = new \App\Services\Approval\ApprovalAuditService($connection);
        $linkService = new \App\Services\Estimate\EstimatePublicLinkService($connection, $estimateRepository, $estimateEditor, $auditLogger, $approvalAudit);

        try {
            $data = $linkService->fetchView($token, $request->getClientIp(), $request->header('USER_AGENT'));

            // Convert estimate object to array
            $estimate = $data['estimate']->toArray();

            // Get customer and vehicle data
            $customerStmt = $connection->pdo()->prepare('SELECT id, CONCAT(first_name, " ", last_name) AS name, email, phone FROM customers WHERE id = :id');
            $customerStmt->execute(['id' => $estimate['customer_id']]);
            $customer = $customerStmt->fetch(\PDO::FETCH_ASSOC);

            $vehicleStmt = $connection->pdo()->prepare('SELECT id, year, make, model, vin, license_plate FROM customer_vehicles WHERE id = :id');
            $vehicleStmt->execute(['id' => $estimate['vehicle_id']]);
            $vehicle = $vehicleStmt->fetch(\PDO::FETCH_ASSOC);

            // Format jobs with items
            $jobs = [];
            foreach ($data['jobs'] as $jobData) {
                $job = $jobData['job']->toArray();
                $job['items'] = $jobData['items'];
                $jobs[] = $job;
            }

            // Check if a signature already exists
            $signatureStmt = $connection->pdo()->prepare('SELECT COUNT(*) FROM estimate_signatures WHERE estimate_id = :id');
            $signatureStmt->execute(['id' => $estimate['id']]);
            $hasSignature = (int) $signatureStmt->fetchColumn() > 0;

            // Get estimate terms from settings
            $settingsRepo = new \App\Support\SettingsRepository($connection);
            $estimateTerms = $settingsRepo->get('documents.terms.estimates') ?? '';

            return Response::json([
                'estimate' => $estimate,
                'customer' => $customer ?: null,
                'vehicle' => $vehicle ?: null,
                'jobs' => $jobs,
                'has_signature' => $hasSignature,
                'terms' => $estimateTerms,
            ]);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    });

    $router->post('/api/public/estimate/approve-job', function (Request $request) use ($connection, $auditLogger) {
        $body = $request->body();
        $token = $body['token'] ?? '';
        $jobId = (int) ($body['job_id'] ?? 0);

        if (!$token || !$jobId) {
            return Response::badRequest(['error' => 'Token and job_id are required']);
        }

        $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection, $auditLogger);
        $estimateEditor = new \App\Services\Estimate\EstimateEditorService($connection, $auditLogger);
        $approvalAudit = new \App\Services\Approval\ApprovalAuditService($connection);
        $linkService = new \App\Services\Estimate\EstimatePublicLinkService($connection, $estimateRepository, $estimateEditor, $auditLogger, $approvalAudit);

        try {
            $result = $linkService->approveJob(
                $token,
                $jobId,
                $body['comment'] ?? null,
                $request->getClientIp(),
                $request->header('USER_AGENT'),
                $body['signer_name'] ?? null,
                $body['signer_email'] ?? null
            );
            return Response::json(['success' => $result]);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    });

    $router->post('/api/public/estimate/reject-job', function (Request $request) use ($connection, $auditLogger) {
        $body = $request->body();
        $token = $body['token'] ?? '';
        $jobId = (int) ($body['job_id'] ?? 0);

        if (!$token || !$jobId) {
            return Response::badRequest(['error' => 'Token and job_id are required']);
        }

        $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection, $auditLogger);
        $estimateEditor = new \App\Services\Estimate\EstimateEditorService($connection, $auditLogger);
        $approvalAudit = new \App\Services\Approval\ApprovalAuditService($connection);
        $linkService = new \App\Services\Estimate\EstimatePublicLinkService($connection, $estimateRepository, $estimateEditor, $auditLogger, $approvalAudit);

        try {
            $result = $linkService->rejectJob(
                $token,
                $jobId,
                $body['comment'] ?? null,
                $request->getClientIp(),
                $request->header('USER_AGENT'),
                $body['signer_name'] ?? null,
                $body['signer_email'] ?? null,
                $body['rejection_reason'] ?? null
            );
            return Response::json(['success' => $result]);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    });

    $router->post('/api/public/estimate/signature', function (Request $request) use ($connection, $auditLogger) {
        $body = $request->body();
        $token = $body['token'] ?? '';

        if (!$token || empty($body['name']) || empty($body['signature_data'])) {
            return Response::badRequest(['error' => 'Token, name, and signature_data are required']);
        }

        $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection, $auditLogger);
        $estimateEditor = new \App\Services\Estimate\EstimateEditorService($connection, $auditLogger);
        $approvalAudit = new \App\Services\Approval\ApprovalAuditService($connection);
        $linkService = new \App\Services\Estimate\EstimatePublicLinkService($connection, $estimateRepository, $estimateEditor, $auditLogger, $approvalAudit);

        try {
            $signature = $linkService->captureSignature(
                $token,
                $body['name'],
                $body['email'] ?? null,
                $body['signature_data'],
                $body['comment'] ?? null,
                $request->getClientIp(),
                $request->header('USER_AGENT'),
                $body['device_fingerprint'] ?? null,
                !empty($body['legal_consent']),
                $body['consent_text'] ?? null
            );

            // Auto-create workorder after signature is captured
            $estimateId = $signature->estimate_id;
            $workorderCreated = null;
            try {
                $workorderRepository = new \App\Services\Workorder\WorkorderRepository($connection, $auditLogger);
                $workorderService = new \App\Services\Workorder\WorkorderService($connection, $workorderRepository, $auditLogger);

                // Check if workorder already exists
                $existingWorkorder = $workorderRepository->findByEstimateId($estimateId);
                if ($existingWorkorder === null) {
                    $workorder = $workorderService->createFromEstimate($estimateId, null, null);
                    $workorderCreated = $workorder->id;
                }
            } catch (\Exception $e) {
                // Log error but don't fail the signature capture
                error_log('Auto workorder creation after signature failed: ' . $e->getMessage());
            }

            return Response::json([
                'success' => true,
                'signature_id' => $signature->id,
                'workorder_id' => $workorderCreated,
            ]);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    });

    // Short code redirect for estimates
    $router->get('/e/{shortCode}', function (Request $request) use ($connection) {
        $shortCode = (string) $request->getAttribute('shortCode');

        $stmt = $connection->pdo()->prepare('SELECT token_hash FROM estimate_public_links WHERE short_code = :short_code LIMIT 1');
        $stmt->execute(['short_code' => $shortCode]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return Response::notFound(['error' => 'Link not found']);
        }

        // We can't redirect with the original token since we only store the hash
        // Instead redirect to a page that will look up by short code
        $baseUrl = rtrim($request->header('Origin') ?? $request->header('Referer') ?? '', '/');
        if (!$baseUrl) {
            $baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }

        header('Location: ' . $baseUrl . '/estimate/view?code=' . $shortCode);
        exit;
    });

    // Also support fetching estimate by short code
    $router->get('/api/public/estimate/by-code/{shortCode}', function (Request $request) use ($connection, $auditLogger) {
        $shortCode = (string) $request->getAttribute('shortCode');

        $stmt = $connection->pdo()->prepare('SELECT * FROM estimate_public_links WHERE short_code = :short_code LIMIT 1');
        $stmt->execute(['short_code' => $shortCode]);
        $linkRow = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$linkRow) {
            return Response::notFound(['error' => 'Link not found']);
        }

        // Check expiration
        if ($linkRow['expires_at'] !== null && strtotime($linkRow['expires_at']) < time()) {
            return Response::json(['error' => 'This estimate link has expired'], 400);
        }

        $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection, $auditLogger);
        $estimate = $estimateRepository->find((int) $linkRow['estimate_id']);

        if ($estimate === null) {
            return Response::notFound(['error' => 'Estimate not found']);
        }

        // Update last accessed
        $updateStmt = $connection->pdo()->prepare('UPDATE estimate_public_links SET last_accessed_at = NOW() WHERE id = :id');
        $updateStmt->execute(['id' => $linkRow['id']]);

        // Get customer and vehicle data
        $customerStmt = $connection->pdo()->prepare('SELECT id, CONCAT(first_name, " ", last_name) AS name, email, phone FROM customers WHERE id = :id');
        $customerStmt->execute(['id' => $estimate->customer_id]);
        $customer = $customerStmt->fetch(\PDO::FETCH_ASSOC);

        $vehicleStmt = $connection->pdo()->prepare('SELECT id, year, make, model, vin, license_plate FROM customer_vehicles WHERE id = :id');
        $vehicleStmt->execute(['id' => $estimate->vehicle_id]);
        $vehicle = $vehicleStmt->fetch(\PDO::FETCH_ASSOC);

        // Get jobs with items
        $jobsStmt = $connection->pdo()->prepare('SELECT * FROM estimate_jobs WHERE estimate_id = :estimate_id ORDER BY display_order ASC');
        $jobsStmt->execute(['estimate_id' => $estimate->id]);
        $jobRows = $jobsStmt->fetchAll(\PDO::FETCH_ASSOC);

        $itemStmt = $connection->pdo()->prepare('SELECT * FROM estimate_items WHERE estimate_job_id = :job_id ORDER BY id ASC');

        $jobs = [];
        foreach ($jobRows as $jobRow) {
            $itemStmt->execute(['job_id' => $jobRow['id']]);
            $jobRow['items'] = $itemStmt->fetchAll(\PDO::FETCH_ASSOC);
            $jobs[] = $jobRow;
        }

        // Check if a signature already exists
        $signatureStmt = $connection->pdo()->prepare('SELECT COUNT(*) FROM estimate_signatures WHERE estimate_id = :id');
        $signatureStmt->execute(['id' => $estimate->id]);
        $hasSignature = (int) $signatureStmt->fetchColumn() > 0;

        // Get estimate terms from settings
        $settingsRepo = new \App\Support\SettingsRepository($connection);
        $estimateTerms = $settingsRepo->get('documents.terms.estimates') ?? '';

        return Response::json([
            'estimate' => $estimate->toArray(),
            'customer' => $customer ?: null,
            'vehicle' => $vehicle ?: null,
            'jobs' => $jobs,
            'short_code' => $shortCode,
            'has_signature' => $hasSignature,
            'terms' => $estimateTerms,
        ]);
    });

// Invoice routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $config, $paymentConfig) {

        // Payment gateway setup
        $gatewayFactory = new \App\Services\Payment\PaymentGatewayFactory($paymentConfig);

        $invoiceMessagingNotifications = new \App\Services\Messaging\MessagingNotificationService(
            $connection,
            new \App\Services\Messaging\MessagingService($connection)
        );
        $invoiceController = new \App\Services\Invoice\InvoiceController(
            new \App\Services\Invoice\InvoiceService($connection),
            new \App\Services\Invoice\PaymentProcessingService($connection, $gatewayFactory),
            $gate,
            new \App\Support\Pdf\InvoicePdfGenerator($connection),
            $invoiceMessagingNotifications
        );
        $onsitePaymentController = new \App\Services\Payments\OnsitePaymentController(
            new \App\Services\Payments\OnsitePaymentService($connection, $gatewayFactory),
            $gate
        );

        $router->get('/api/invoices', function (Request $request) use ($invoiceController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'customer_id' => $request->queryParam('customer_id'),
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ];

            if ($user?->role === 'customer' && $user->customer_id !== null) {
                $filters['customer_id'] = $user->customer_id;
            } elseif ($user?->role === 'technician') {
                $filters['technician_id'] = $user->id;
            }
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

        $router->post('/api/payments/onsite', function (Request $request) use ($onsitePaymentController) {
            $user = $request->getAttribute('user');
            $data = $onsitePaymentController->createCharge($user, $request->body());
            return Response::created($data);
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

    // Workorder routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $auditLogger) {
        $workorderRepository = new \App\Services\Workorder\WorkorderRepository($connection, $auditLogger);
        $workorderService = new \App\Services\Workorder\WorkorderService($connection, $workorderRepository, $auditLogger);
        $workorderEvidence = new \App\Services\Workorder\WorkorderJobEvidenceService($connection, $auditLogger);
        $workorderMessagingNotifications = new \App\Services\Messaging\MessagingNotificationService(
            $connection,
            new \App\Services\Messaging\MessagingService($connection)
        );
        $trackingNotificationConfig = require __DIR__ . '/../config/notifications.php';
        $trackingTemplateEngine = new \App\Support\Notifications\TemplateEngine();
        $trackingLogs = new \App\Support\Notifications\NotificationLogRepository($connection);
        $trackingDispatcher = new \App\Support\Notifications\NotificationDispatcher(
            $trackingNotificationConfig,
            $trackingTemplateEngine,
            $trackingLogs,
            $auditLogger
        );
        $trackingService = new \App\Services\Tracking\TrackingService(
            $connection,
            $trackingDispatcher,
            $workorderMessagingNotifications,
            new \App\Services\Dispatch\DispatchAuditService($connection)
        );
        $workorderController = new \App\Services\Workorder\WorkorderController(
            $workorderRepository,
            $workorderService,
            $workorderEvidence,
            $gate,
            $workorderMessagingNotifications
        );

        // Status-driven notification service
        $notificationEventService = new \App\Services\Notification\NotificationEventService(
            $connection,
            $trackingDispatcher
        );
        $workorderStatusNotifications = new \App\Services\Workorder\WorkorderStatusNotificationService(
            $connection,
            $notificationEventService,
            $auditLogger
        );

        $router->post('/api/tracking-links', function (Request $request) use ($trackingService) {
            $user = $request->getAttribute('user');
            $body = $request->body();
            $jobId = $body['job_id'] ?? null;
            $expiresAt = $body['expires_at'] ?? null;

            if (!$jobId) {
                return Response::badRequest(['error' => 'job_id is required']);
            }

            $baseUrl = rtrim($request->header('Origin') ?? $request->header('Referer') ?? 'http://localhost', '/');
            $data = $trackingService->issueLink((int) $jobId, $baseUrl, $expiresAt, $user?->id);
            return Response::created($data);
        });

        $router->delete('/api/tracking-links/{token}', function (Request $request) use ($trackingService) {
            $token = (string) $request->getAttribute('token');
            $trackingService->revokeLink($token);
            return Response::noContent();
        });

        $router->post('/api/tracking/jobs/{jobId}/location', function (Request $request) use ($trackingService) {
            $jobId = (int) $request->getAttribute('jobId');
            $body = $request->body();

            if (!isset($body['location']) && !isset($body['lat']) && !isset($body['lng'])) {
                return Response::badRequest(['error' => 'location is required']);
            }

            $location = is_array($body['location'] ?? null) ? $body['location'] : $body;
            $data = $trackingService->recordLocation($jobId, $location);
            return Response::json(['last_position' => $data]);
        });

        $router->get('/api/workorders', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $params = [
                'status' => $request->queryParam('status'),
                'customer_id' => $request->queryParam('customer_id'),
                'vehicle_id' => $request->queryParam('vehicle_id'),
                'technician_id' => $request->queryParam('technician_id'),
                'priority' => $request->queryParam('priority'),
                'term' => $request->queryParam('term'),
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ];
            $data = $workorderController->index($user, $params);
            return Response::json($data);
        });

        $router->get('/api/workorders/stats', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $params = ['technician_id' => $request->queryParam('technician_id')];
            $data = $workorderController->stats($user, $params);
            return Response::json($data);
        });

        $router->get('/api/workorders/{id}', function (Request $request) use ($workorderController, $connection) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $workorderController->show($user, $id);

            // Enrich with customer data
            if (!empty($data['customer_id'])) {
                $stmt = $connection->pdo()->prepare('SELECT id, first_name, last_name, CONCAT(first_name, " ", last_name) AS name, email, phone FROM customers WHERE id = :id');
                $stmt->execute(['id' => $data['customer_id']]);
                $customer = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($customer) {
                    $data['customer'] = $customer;
                }
            }

            // Enrich with vehicle data
            if (!empty($data['vehicle_id'])) {
                $stmt = $connection->pdo()->prepare('SELECT id, year, make, model, vin, license_plate, CONCAT(year, " ", make, " ", model) AS display_name FROM customer_vehicles WHERE id = :id');
                $stmt->execute(['id' => $data['vehicle_id']]);
                $vehicle = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($vehicle) {
                    $data['vehicle'] = $vehicle;
                }
            }

            return Response::json($data);
        });

        $router->post('/api/workorders/from-estimate', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $data = $workorderController->createFromEstimate($user, $request->body());
            return Response::created($data);
        });

        $router->patch('/api/workorders/{id}/status', function (Request $request) use ($workorderController, $workorderRepository, $workorderStatusNotifications) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            // Get current status before update
            $before = $workorderRepository->find($id);
            $previousStatus = $before?->status ?? '';

            // Update status
            $data = $workorderController->updateStatus($user, $id, $request->body());

            // Trigger status-driven notifications
            $newStatus = $data['status'] ?? '';
            if ($previousStatus !== $newStatus && $newStatus !== '') {
                $workorderStatusNotifications->onStatusChange($id, $previousStatus, $newStatus, $user?->id);
            }

            return Response::json($data);
        });

        $router->patch('/api/workorders/{id}/assign', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $workorderController->assignTechnician($user, $id, $request->body());
            return Response::json($data);
        });

        $router->patch('/api/workorders/{id}/priority', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $workorderController->updatePriority($user, $id, $request->body());
            return Response::json($data);
        });

        $router->post('/api/workorders/{id}/to-invoice', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $workorderController->convertToInvoice($user, $id, $request->body());
            return Response::created($data);
        });

        $router->post('/api/workorders/{id}/sub-estimate', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $workorderController->createSubEstimate($user, $id, $request->body());
            return Response::created($data);
        });

        $router->post('/api/workorders/{id}/add-sub-estimate', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $workorderController->addSubEstimateJobs($user, $id, $request->body());
            return Response::json($data);
        });

        $router->get('/api/workorders/{id}/timeline', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $workorderController->timeline($user, $id);
            return Response::json($data);
        });

        $router->patch('/api/workorders/{id}/jobs/{jobId}/status', function (Request $request) use ($workorderController, $trackingService) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $jobId = (int) $request->getAttribute('jobId');
            $data = $workorderController->updateJobStatus($user, $id, $jobId, $request->body());

            $status = $request->body()['status'] ?? null;
            if ($status === WorkorderJob::STATUS_IN_PROGRESS) {
                $baseUrl = rtrim($request->header('Origin') ?? $request->header('Referer') ?? 'http://localhost', '/');
                $trackingService->sendTrackingLinkForJob($jobId, $baseUrl, $user?->id);
            }

            return Response::json($data);
        });

        $router->patch('/api/workorders/{id}/jobs/{jobId}/assign', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $jobId = (int) $request->getAttribute('jobId');
            $data = $workorderController->assignJobTechnician($user, $id, $jobId, $request->body());
            return Response::json($data);
        });

        $router->post('/api/workorders/{id}/jobs/{jobId}/checkpoints/{checkpointType}', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $jobId = (int) $request->getAttribute('jobId');
            $checkpointType = (string) $request->getAttribute('checkpointType');
            $file = $request->file('file');
            $data = $workorderController->uploadJobCheckpoint($user, $id, $jobId, $checkpointType, is_array($file) ? $file : []);
            return Response::created($data);
        });

        $router->get('/api/workorders/{id}/jobs/{jobId}/checkpoints', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $jobId = (int) $request->getAttribute('jobId');
            $data = $workorderController->checkpointStatus($user, $id, $jobId);
            return Response::json($data);
        });

        $router->post('/api/workorders/{id}/jobs/{jobId}/damage-reports', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $jobId = (int) $request->getAttribute('jobId');
            $data = $workorderController->createDamageReport($user, $id, $jobId, $request->body());
            return Response::created($data);
        });

        $router->get('/api/workorders/{id}/jobs/{jobId}/damage-reports', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $jobId = (int) $request->getAttribute('jobId');
            $data = $workorderController->listDamageReports($user, $id, $jobId);
            return Response::json($data);
        });

        $router->post('/api/workorders/{id}/jobs/{jobId}/signature', function (Request $request) use ($workorderController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $jobId = (int) $request->getAttribute('jobId');
            $data = $workorderController->captureJobSignature(
                $user,
                $id,
                $jobId,
                $request->body(),
                $request->getClientIp() ?? 'unknown',
                $request->header('USER_AGENT')
            );
            return Response::created($data);
        });
    });

    // Dispatch recommendation routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection) {
        $dispatchRecommendationService = new \App\Services\Dispatch\DispatchRecommendationService($connection);

        $router->get('/api/dispatch/suggestions', function (Request $request) use ($dispatchRecommendationService) {
            $params = [
                'dispatch_requirement_id' => $request->queryParam('dispatch_requirement_id'),
                'job_category' => $request->queryParam('job_category'),
                'scheduled_start' => $request->queryParam('scheduled_start'),
                'estimated_duration_hours' => $request->queryParam('estimated_duration_hours'),
                'required_capacity' => $request->queryParam('required_capacity'),
                'required_equipment_class' => $request->queryParam('required_equipment_class'),
                'equipment_requirements' => $request->queryParam('equipment_requirements'),
                'required_certifications' => $request->queryParam('required_certifications'),
                'pickup_latitude' => $request->queryParam('pickup_latitude'),
                'pickup_longitude' => $request->queryParam('pickup_longitude'),
                'dropoff_latitude' => $request->queryParam('dropoff_latitude'),
                'dropoff_longitude' => $request->queryParam('dropoff_longitude'),
            ];
            $limit = (int) ($request->queryParam('limit') ?? 5);
            $data = $dispatchRecommendationService->suggest($params, $limit);
            return Response::json($data);
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

    $appointmentMessagingNotifications = new \App\Services\Messaging\MessagingNotificationService(
        $connection,
        new \App\Services\Messaging\MessagingService($connection)
    );
    $appointmentController = new \App\Services\Appointment\AppointmentController(
        new \App\Services\Appointment\AppointmentService($connection, $appointmentAudit, $appointmentWebhooks, $appointmentMessagingNotifications),
        new \App\Services\Appointment\AvailabilityService($connection),
        $gate
    );

    // User controller for technician listings
    $userController = new \App\Services\User\UserController(
        new \App\Services\User\UserRepository($connection),
        $gate,
        $totpService,
        new \App\Services\ImportExport\CsvExportService($connection)
    );

    // Role controller for role management
    $roleController = new \App\Services\Role\RoleController(
        new \App\Services\Role\RoleRepository($connection),
        $gate
    );

    $messagingController = new \App\Services\Messaging\MessagingController(
        new \App\Services\Messaging\MessagingService($connection),
        $gate
    );

    $maskedSmsConfig = require __DIR__ . '/../config/notifications.php';
    $maskedSmsGateway = new \App\Services\Messaging\MaskedSmsGateway($maskedSmsConfig);
    $maskedSmsService = new \App\Services\Messaging\MaskedSmsService(
        $connection,
        $maskedSmsGateway,
        $maskedSmsConfig['sms']['masked_number'] ?? null
    );
    $maskedSmsController = new \App\Services\Messaging\MaskedSmsController($maskedSmsService, $gate);

    $driverDispatchController = new \App\Services\Dispatch\DriverDispatchController(
        $connection,
        new \App\Services\Dispatch\DriverPushTokenService($connection),
        new \App\Services\Dispatch\DriverJobOfferService($connection),
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

    $router->post('/api/public/messages/job-sms/receive', function (Request $request) use ($maskedSmsController) {
        $data = $maskedSmsController->receive($request->body());
        return Response::json($data);
    });

    $router->group([Middleware::auth()], function (Router $router) use ($appointmentController, $userController, $roleController, $messagingController, $maskedSmsController, $driverDispatchController) {
        $router->get('/api/appointments', function (Request $request) use ($appointmentController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'customer_id' => $request->queryParam('customer_id'),
                'technician_id' => $request->queryParam('technician_id'),
                'date' => $request->queryParam('date'),
            ];

            if ($user?->role === 'technician') {
                $filters['technician_id'] = $user->id;
            }
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

        // Technician listings for appointment forms
        $router->get('/api/technicians', function (Request $request) use ($userController) {
            $user = $request->getAttribute('user');
            $params = [
                'query' => $request->queryParam('query'),
            ];
            $data = $userController->listTechnicians($user, $params);
            return Response::json($data);
        });

        // User management routes
        $router->put('/api/users/me', function (Request $request) use ($userController) {
            $user = $request->getAttribute('user');
            $data = $userController->updateProfile($user, $request->body());
            return Response::json($data);
        });

        $router->get('/api/users', function (Request $request) use ($userController) {
            $user = $request->getAttribute('user');
            $filters = [
                'role' => $request->queryParam('role'),
                'query' => $request->queryParam('query'),
            ];
            $data = $userController->listUsers($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/users/export', function (Request $request) use ($userController) {
            $user = $request->getAttribute('user');
            $filters = [
                'role' => $request->queryParam('role'),
                'query' => $request->queryParam('query'),
            ];
            $csv = $userController->exportUsers($user, $filters);

            return new Response(
                200,
                [
                    'Content-Type' => 'text/csv',
                    'Content-Disposition' => 'attachment; filename="users_export_' . date('Y-m-d') . '.csv"'
                ],
                $csv
            );
        });

        $router->get('/api/users/{id}', function (Request $request) use ($userController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $userController->getUser($user, $id);
            return Response::json($data);
        });

        $router->post('/api/users', function (Request $request) use ($userController) {
            $user = $request->getAttribute('user');
            $data = $userController->createUser($user, $request->body());
            return Response::created($data);
        });

        $router->post('/api/users/invite', function (Request $request) use ($userController, $authService, $connection, $authConfig) {
            $user = $request->getAttribute('user');
            $data = $userController->inviteUser($user, $request->body());
            $token = $authService->issueVerificationToken($data['id']);

            $notificationsConfig = require __DIR__ . '/../config/notifications.php';
            $dispatcher = new \App\Support\Notifications\NotificationDispatcher(
                $notificationsConfig,
                new \App\Support\Notifications\TemplateEngine(),
                new \App\Support\Notifications\NotificationLogRepository($connection)
            );

            $appUrl = env('APP_URL', 'http://localhost:8080');
            $inviteUrl = $appUrl . '/accept-invite/' . urlencode($token->token);
            $expiryHours = $authConfig['verification']['token_ttl_hours'] ?? 48;

            try {
                $dispatcher->sendMail(
                    'auth.invitation',
                    $data['email'],
                    [
                        'name' => $data['name'],
                        'invite_url' => $inviteUrl,
                        'expiry_hours' => $expiryHours,
                    ],
                    'You are invited to Auto Repair Shop Management'
                );
            } catch (\Throwable $e) {
                error_log('Failed to send invitation email: ' . $e->getMessage());
            }

            return Response::created([
                'user' => $data,
                'message' => 'Invitation email has been sent',
            ]);
        });

        $router->put('/api/users/{id}', function (Request $request) use ($userController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $userController->updateUser($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/users/{id}', function (Request $request) use ($userController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $userController->deleteUser($user, $id);
            return Response::noContent();
        });

        $router->post('/api/users/{id}/reset-2fa', function (Request $request) use ($userController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $userController->reset2FA($user, $id);
            return Response::json($data);
        });

        $router->post('/api/users/{id}/require-2fa', function (Request $request) use ($userController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $body = $request->body();
            $required = $body['required'] ?? false;
            $data = $userController->require2FA($user, $id, $required);
            return Response::json($data);
        });

        // Role management routes
        $router->get('/api/roles', function (Request $request) use ($roleController) {
            $user = $request->getAttribute('user');
            $filters = [
                'query' => $request->queryParam('query'),
                'include_system' => $request->queryParam('include_system') !== 'false',
            ];
            $data = $roleController->listRoles($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/roles/{id}', function (Request $request) use ($roleController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $roleController->getRole($user, $id);
            return Response::json($data);
        });

        $router->post('/api/roles', function (Request $request) use ($roleController) {
            $user = $request->getAttribute('user');
            $data = $roleController->createRole($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/roles/{id}', function (Request $request) use ($roleController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $roleController->updateRole($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/roles/{id}', function (Request $request) use ($roleController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $roleController->deleteRole($user, $id);
            return Response::noContent();
        });

        $router->get('/api/permissions', function (Request $request) use ($roleController) {
            $user = $request->getAttribute('user');
            $data = $roleController->getAvailablePermissions($user);
            return Response::json($data);
        });

        // Messaging routes (staff only)
        $router->get('/api/messages/threads', function (Request $request) use ($messagingController) {
            $user = $request->getAttribute('user');
            $data = $messagingController->threads($user);
            return Response::json($data);
        })->middleware(Middleware::auth());

        $router->post('/api/messages/threads', function (Request $request) use ($messagingController) {
            $user = $request->getAttribute('user');
            $data = $messagingController->createThread($user, $request->body());
            return Response::created($data);
        })->middleware(Middleware::auth());

        $router->get('/api/messages/threads/{id}/messages', function (Request $request) use ($messagingController) {
            $user = $request->getAttribute('user');
            $threadId = (int) $request->getAttribute('id');
            $data = $messagingController->messages($user, $threadId);
            return Response::json($data);
        })->middleware(Middleware::auth());

        $router->post('/api/messages/threads/{id}/messages', function (Request $request) use ($messagingController) {
            $user = $request->getAttribute('user');
            $threadId = (int) $request->getAttribute('id');
            $data = $messagingController->postMessage($user, $threadId, $request->body());
            return Response::created($data);
        })->middleware(Middleware::auth());

        $router->post('/api/messages/threads/{id}/attachments', function (Request $request) use ($messagingController) {
            $user = $request->getAttribute('user');
            $threadId = (int) $request->getAttribute('id');
            $files = $request->file('files') ?? $request->file('file');
            $data = $messagingController->postMessageWithAttachments(
                $user,
                $threadId,
                $request->body(),
                is_array($files) ? $files : []
            );
            return Response::created($data);
        })->middleware(Middleware::auth());

        $router->post('/api/messages/threads/{id}/read', function (Request $request) use ($messagingController) {
            $user = $request->getAttribute('user');
            $threadId = (int) $request->getAttribute('id');
            $data = $messagingController->markRead($user, $threadId);
            return Response::json($data);
        })->middleware(Middleware::auth());

        $router->get('/api/messages/threads/{id}/state', function (Request $request) use ($messagingController) {
            $user = $request->getAttribute('user');
            $threadId = (int) $request->getAttribute('id');
            $data = $messagingController->threadState($user, $threadId);
            return Response::json($data);
        })->middleware(Middleware::auth());

        $router->get('/api/messages/unread', function (Request $request) use ($messagingController) {
            $user = $request->getAttribute('user');
            $data = $messagingController->unreadCounts($user);
            return Response::json($data);
        })->middleware(Middleware::auth());

        // Job-related masked SMS routes
        $router->post('/api/jobs/{jobReference}/messages/sms', function (Request $request) use ($maskedSmsController) {
            $user = $request->getAttribute('user');
            $jobReference = (string) $request->getAttribute('jobReference');
            $data = $maskedSmsController->send($user, $jobReference, $request->body());
            return Response::created($data);
        });

        // Driver push tokens
        $router->post('/api/driver/push-tokens', function (Request $request) use ($driverDispatchController) {
            $user = $request->getAttribute('user');
            $data = $driverDispatchController->registerPushToken($user, $request->body());
            return Response::created($data);
        });

        // Driver job offers
        $router->get('/api/dispatch/job-offers', function (Request $request) use ($driverDispatchController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'driver_profile_id' => $request->queryParam('driver_profile_id'),
            ];
            $data = $driverDispatchController->listOffers($user, $filters);
            return Response::json($data);
        });

        $router->post('/api/dispatch/job-offers', function (Request $request) use ($driverDispatchController) {
            $user = $request->getAttribute('user');
            $data = $driverDispatchController->createOffer($user, $request->body());
            return Response::created($data);
        });

        $router->post('/api/dispatch/job-offers/{id}/accept', function (Request $request) use ($driverDispatchController) {
            $user = $request->getAttribute('user');
            $offerId = (int) $request->getAttribute('id');
            $data = $driverDispatchController->acceptOffer($user, $offerId);
            return Response::json($data);
        });

        $router->post('/api/dispatch/job-offers/{id}/decline', function (Request $request) use ($driverDispatchController) {
            $user = $request->getAttribute('user');
            $offerId = (int) $request->getAttribute('id');
            $body = $request->body();
            $data = $driverDispatchController->declineOffer(
                $user,
                $offerId,
                $body['rejection_reason'] ?? null,
                $body['rejection_notes'] ?? null
            );
            return Response::json($data);
        });
    });

    // Advanced Dispatch Routes (Waterfall, Geofencing, ETA)
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {
        $etaConfig = require __DIR__ . '/../config/dispatch.php';
        $etaService = new \App\Services\Dispatch\TrafficAwareEtaService($connection, $etaConfig['eta'] ?? []);
        $recommendationService = new \App\Services\Dispatch\DispatchRecommendationService($connection, $etaService);
        $offerService = new \App\Services\Dispatch\DriverJobOfferService($connection);
        $waterfallService = new \App\Services\Dispatch\WaterfallDispatchService($connection, $recommendationService, $offerService);
        $geofencingService = new \App\Services\Dispatch\GeofencingService($connection);
        $auditService = new \App\Services\Dispatch\DispatchAuditService($connection);

        // Waterfall Dispatch Routes
        $router->post('/api/dispatch/waterfall', function (Request $request) use ($waterfallService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.waterfall.initiate')) {
                return Response::forbidden('Permission denied');
            }
            $data = $waterfallService->initiateWaterfall($request->body(), $user->id);
            return Response::created($data);
        });

        $router->get('/api/dispatch/waterfall', function (Request $request) use ($waterfallService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.waterfall.view')) {
                return Response::forbidden('Permission denied');
            }
            $data = $waterfallService->listActiveSequences();
            return Response::json(['data' => $data]);
        });

        $router->get('/api/dispatch/waterfall/{reference}', function (Request $request) use ($waterfallService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.waterfall.view')) {
                return Response::forbidden('Permission denied');
            }
            $reference = (string) $request->getAttribute('reference');
            $data = $waterfallService->getSequenceStatus($reference);
            if ($data === null) {
                return Response::notFound('Waterfall sequence not found');
            }
            return Response::json($data);
        });

        $router->post('/api/dispatch/waterfall/{id}/cancel', function (Request $request) use ($waterfallService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.waterfall.cancel')) {
                return Response::forbidden('Permission denied');
            }
            $id = (int) $request->getAttribute('id');
            $body = $request->body();
            $data = $waterfallService->cancelSequence($id, $user->id, $body['reason'] ?? null);
            return Response::json($data);
        });

        // Driver Location Routes
        $router->post('/api/driver/location', function (Request $request) use ($etaService, $connection) {
            $user = $request->getAttribute('user');
            $driverProfile = (new \App\Services\Dispatch\DriverDispatchController($connection, null, null, null))
                ->resolveDriverProfile($user);
            if ($driverProfile === null) {
                return Response::forbidden('Driver profile not found');
            }
            $body = $request->body();
            $id = $etaService->recordDriverLocation($driverProfile['id'], [
                'latitude' => $body['latitude'],
                'longitude' => $body['longitude'],
                'accuracy' => $body['accuracy'] ?? null,
                'altitude' => $body['altitude'] ?? null,
                'speed' => $body['speed'] ?? null,
                'heading' => $body['heading'] ?? null,
                'source' => $body['source'] ?? 'gps',
            ]);
            return Response::created(['id' => $id]);
        });

        $router->get('/api/driver/location/current', function (Request $request) use ($etaService, $connection) {
            $user = $request->getAttribute('user');
            $driverProfile = (new \App\Services\Dispatch\DriverDispatchController($connection, null, null, null))
                ->resolveDriverProfile($user);
            if ($driverProfile === null) {
                return Response::forbidden('Driver profile not found');
            }
            $location = $etaService->getDriverCurrentLocation($driverProfile['id']);
            return Response::json(['data' => $location]);
        });

        // Geofencing Routes
        $router->post('/api/dispatch/geofences', function (Request $request) use ($geofencingService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.geofences.create')) {
                return Response::forbidden('Permission denied');
            }
            $body = $request->body();
            $data = $geofencingService->createJobGeofence(
                $body['job_reference'],
                $body['job_type'] ?? 'workorder',
                (float) $body['latitude'],
                (float) $body['longitude'],
                (int) ($body['radius_meters'] ?? 200),
                $body['enter_action'] ?? 'mark_arrived',
                $body['exit_action'] ?? null
            );
            return Response::created($data);
        });

        $router->get('/api/dispatch/geofences/{id}/check', function (Request $request) use ($geofencingService, $connection) {
            $user = $request->getAttribute('user');
            $geofenceId = (int) $request->getAttribute('id');
            $driverProfileId = $request->queryParam('driver_profile_id');

            if ($driverProfileId === null) {
                $driverProfile = (new \App\Services\Dispatch\DriverDispatchController($connection, null, null, null))
                    ->resolveDriverProfile($user);
                $driverProfileId = $driverProfile ? $driverProfile['id'] : null;
            }

            if ($driverProfileId === null) {
                return Response::badRequest('Driver profile ID required');
            }

            $data = $geofencingService->isDriverInGeofence((int) $driverProfileId, $geofenceId);
            return Response::json(['data' => $data]);
        });

        $router->delete('/api/dispatch/geofences/{id}', function (Request $request) use ($geofencingService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.geofences.delete')) {
                return Response::forbidden('Permission denied');
            }
            $id = (int) $request->getAttribute('id');
            $geofencingService->deactivateGeofence($id);
            return Response::noContent();
        });

        // Idle Alerts Routes
        $router->get('/api/dispatch/idle-alerts', function (Request $request) use ($geofencingService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.alerts.view')) {
                return Response::forbidden('Permission denied');
            }
            $data = $geofencingService->getActiveIdleAlerts();
            return Response::json(['data' => $data]);
        });

        $router->post('/api/dispatch/idle-alerts/{id}/acknowledge', function (Request $request) use ($geofencingService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.alerts.acknowledge')) {
                return Response::forbidden('Permission denied');
            }
            $alertId = (int) $request->getAttribute('id');
            $body = $request->body();
            $data = $geofencingService->acknowledgeIdleAlert($alertId, $user->id, $body['notes'] ?? null);
            return Response::json($data);
        });

        // Rejection Reasons Routes
        $router->get('/api/dispatch/rejection-reasons', function (Request $request) use ($auditService) {
            $data = $auditService->getRejectionReasons();
            return Response::json(['data' => $data]);
        });

        // Audit/Analytics Routes
        $router->get('/api/dispatch/analytics/rejections', function (Request $request) use ($auditService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.analytics.view')) {
                return Response::forbidden('Permission denied');
            }
            $daysBack = (int) ($request->queryParam('days') ?? 30);
            $data = $auditService->analyzeRejectionPatterns($daysBack);
            return Response::json(['data' => $data]);
        });

        $router->get('/api/dispatch/audit/{jobReference}', function (Request $request) use ($auditService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.audit.view')) {
                return Response::forbidden('Permission denied');
            }
            $jobReference = (string) $request->getAttribute('jobReference');
            $eventType = $request->queryParam('event_type');
            $data = $auditService->getEventsForJob($jobReference, $eventType);
            return Response::json(['data' => $data]);
        });

        // Job Density Heatmap Data
        $router->get('/api/dispatch/heatmap', function (Request $request) use ($connection, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.heatmap.view')) {
                return Response::forbidden('Permission denied');
            }
            $date = $request->queryParam('date') ?? date('Y-m-d');
            $hour = $request->queryParam('hour');

            $sql = 'SELECT grid_lat, grid_lng, job_count, snapshot_hour
                    FROM job_density_snapshots
                    WHERE snapshot_date = :date';
            $params = ['date' => $date];

            if ($hour !== null) {
                $sql .= ' AND snapshot_hour = :hour';
                $params['hour'] = (int) $hour;
            }

            $stmt = $connection->pdo()->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return Response::json(['data' => $data, 'date' => $date]);
        });

        // Driver Certifications Routes
        $router->get('/api/driver/certifications', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            $driverProfile = (new \App\Services\Dispatch\DriverDispatchController($connection, null, null, null))
                ->resolveDriverProfile($user);
            if ($driverProfile === null) {
                return Response::forbidden('Driver profile not found');
            }

            $stmt = $connection->pdo()->prepare(
                'SELECT * FROM driver_certifications WHERE driver_profile_id = :id ORDER BY certification_name'
            );
            $stmt->execute(['id' => $driverProfile['id']]);
            $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return Response::json(['data' => $data]);
        });

        $router->post('/api/driver/certifications', function (Request $request) use ($connection, $gate) {
            $user = $request->getAttribute('user');
            $body = $request->body();
            $driverProfileId = $body['driver_profile_id'] ?? null;

            // Allow self-submission or admin submission
            if ($driverProfileId === null) {
                $driverProfile = (new \App\Services\Dispatch\DriverDispatchController($connection, null, null, null))
                    ->resolveDriverProfile($user);
                $driverProfileId = $driverProfile ? $driverProfile['id'] : null;
            } elseif (!$gate->can($user, 'dispatch.certifications.manage')) {
                return Response::forbidden('Permission denied');
            }

            if ($driverProfileId === null) {
                return Response::badRequest('Driver profile ID required');
            }

            $stmt = $connection->pdo()->prepare(
                'INSERT INTO driver_certifications
                    (driver_profile_id, certification_code, certification_name, issuing_authority,
                     certificate_number, issued_date, expiry_date, status, verification_status, created_at)
                 VALUES
                    (:driver_id, :code, :name, :authority, :cert_num, :issued, :expiry, :status, :verification, NOW())
                 ON DUPLICATE KEY UPDATE
                    certification_name = VALUES(certification_name),
                    issuing_authority = VALUES(issuing_authority),
                    certificate_number = VALUES(certificate_number),
                    issued_date = VALUES(issued_date),
                    expiry_date = VALUES(expiry_date),
                    updated_at = NOW()'
            );

            $stmt->execute([
                'driver_id' => $driverProfileId,
                'code' => strtoupper($body['certification_code']),
                'name' => $body['certification_name'],
                'authority' => $body['issuing_authority'] ?? null,
                'cert_num' => $body['certificate_number'] ?? null,
                'issued' => $body['issued_date'] ?? null,
                'expiry' => $body['expiry_date'] ?? null,
                'status' => 'active',
                'verification' => 'pending',
            ]);

            return Response::created(['success' => true]);
        });

        $router->post('/api/dispatch/certifications/{id}/verify', function (Request $request) use ($connection, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.certifications.verify')) {
                return Response::forbidden('Permission denied');
            }
            $id = (int) $request->getAttribute('id');
            $body = $request->body();

            $stmt = $connection->pdo()->prepare(
                'UPDATE driver_certifications
                 SET verification_status = :status, verified_by = :user_id, verified_at = NOW(), updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'status' => $body['verified'] ? 'verified' : 'rejected',
                'user_id' => $user->id,
                'id' => $id,
            ]);

            return Response::json(['success' => true]);
        });

        // ETA Calculation Endpoint
        $router->post('/api/dispatch/calculate-eta', function (Request $request) use ($etaService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.suggestions.view')) {
                return Response::forbidden('Permission denied');
            }
            $body = $request->body();
            $data = $etaService->calculateEta(
                (float) $body['from_latitude'],
                (float) $body['from_longitude'],
                (float) $body['to_latitude'],
                (float) $body['to_longitude'],
                $body['driver_profile_id'] ?? null
            );
            return Response::json($data);
        });

        // Job Offer History for a Job
        $router->get('/api/dispatch/jobs/{jobReference}/offers', function (Request $request) use ($offerService, $gate) {
            $user = $request->getAttribute('user');
            if (!$gate->can($user, 'dispatch.offers.view')) {
                return Response::forbidden('Permission denied');
            }
            $jobReference = (string) $request->getAttribute('jobReference');
            $data = $offerService->getOffersForJob($jobReference);
            return Response::json(['data' => $data]);
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

        $router->get('/api/inspections/templates/{id}', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $inspectionController->showTemplate($user, $id);
            return Response::json($data);
        });

        $router->put('/api/inspections/templates/{id}', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $inspectionController->updateTemplate($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/inspections/templates/{id}', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $inspectionController->deleteTemplate($user, $id);
            return Response::noContent();
        });

        $router->post('/api/inspections/start', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $data = $inspectionController->start($user, $request->body());
            return Response::created($data);
        });

        $router->post('/api/inspections/{id}/complete', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $inspectionController->complete($user, $id, $request->body());
            return Response::json($data);
        });

        $router->post('/api/inspections/{id}/media', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $file = $request->file('media') ?? [];
            $clientToken = $request->input('client_token') ?? $request->header('X-Idempotency-Key');
            $data = $inspectionController->uploadMedia($user, $id, $file, $clientToken);
            return Response::json($data);
        });

        $router->get('/api/inspections/customer', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $data = $inspectionController->customerList($user);
            return Response::json($data);
        });

        $router->get('/api/inspections/customer/{id}', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $inspectionController->customerShow($user, $id);
            return Response::json($data);
        });

        $router->get('/api/inspections/{id}', function (Request $request) use ($inspectionController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $inspectionController->show($user, $id);
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

        // Inspection-to-Estimate Bridge routes
        $bridgeService = new \App\Services\Inspection\InspectionEstimateBridgeService($connection);
        $bridgeController = new \App\Services\Inspection\InspectionEstimateBridgeController($bridgeService, $gate);

        $router->get('/api/inspections/{id}/failed-items', function (Request $request) use ($bridgeController) {
            $id = (int) $request->getAttribute('id');
            return $bridgeController->failedItems($request, Response::create(), ['id' => $id]);
        });

        $router->get('/api/inspections/{id}/recommendations', function (Request $request) use ($bridgeController) {
            $id = (int) $request->getAttribute('id');
            return $bridgeController->recommendations($request, Response::create(), ['id' => $id]);
        });

        $router->post('/api/inspections/{id}/add-to-estimate', function (Request $request) use ($bridgeController) {
            $id = (int) $request->getAttribute('id');
            return $bridgeController->addToEstimate($request, Response::create(), ['id' => $id]);
        });

        $router->post('/api/inspections/{id}/create-estimate', function (Request $request) use ($bridgeController) {
            $id = (int) $request->getAttribute('id');
            return $bridgeController->createEstimate($request, Response::create(), ['id' => $id]);
        });

        $router->post('/api/inspections/{id}/add-to-workorder', function (Request $request) use ($bridgeController) {
            $id = (int) $request->getAttribute('id');
            return $bridgeController->addToWorkorder($request, Response::create(), ['id' => $id]);
        });

        $router->post('/api/inspections/{id}/recommendations/{itemId}/decline', function (Request $request) use ($bridgeController) {
            $id = (int) $request->getAttribute('id');
            $itemId = (int) $request->getAttribute('itemId');
            return $bridgeController->declineRecommendation($request, Response::create(), ['id' => $id, 'itemId' => $itemId]);
        });

        $router->post('/api/inspections/{id}/recommendations/{itemId}/defer', function (Request $request) use ($bridgeController) {
            $id = (int) $request->getAttribute('id');
            $itemId = (int) $request->getAttribute('itemId');
            return $bridgeController->deferRecommendation($request, Response::create(), ['id' => $id, 'itemId' => $itemId]);
        });
    });

    // Quality Control (QC) Checklist routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $auditLogger) {

        $qcService = new \App\Services\QualityControl\QCChecklistService($connection, $auditLogger);
        $qcController = new \App\Services\QualityControl\QCChecklistController($qcService, $gate);

        // Template endpoints
        $router->get('/api/qc/templates', function (Request $request) use ($qcController) {
            $user = $request->getAttribute('user');
            $includeInactive = $request->queryParam('include_inactive') === 'true';
            $data = $qcController->listTemplates($user, $includeInactive);
            return Response::json($data);
        });

        $router->get('/api/qc/templates/{id}', function (Request $request) use ($qcController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $qcController->showTemplate($user, $id);
            return Response::json($data);
        });

        $router->post('/api/qc/templates', function (Request $request) use ($qcController) {
            $user = $request->getAttribute('user');
            $data = $qcController->createTemplate($user, $request->body());
            return Response::created($data);
        });

        // Workorder QC endpoints
        $router->get('/api/workorders/{id}/qc-check', function (Request $request) use ($qcController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $qcController->getWorkorderCheck($user, $id);
            return Response::json($data);
        });

        $router->get('/api/workorders/{id}/qc-status', function (Request $request) use ($qcController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $qcController->getQCStatus($user, $id);
            return Response::json($data);
        });

        $router->post('/api/workorders/{id}/qc-check/initialize', function (Request $request) use ($qcController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $qcController->initializeCheck($user, $id, $request->body());
            return Response::created($data);
        });

        // QC check item endpoints
        $router->patch('/api/qc/checks/{id}/items', function (Request $request) use ($qcController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $qcController->updateCheckItems($user, $id, $request->body());
            return Response::json($data);
        });

        $router->patch('/api/qc/checks/{checkId}/items/{itemId}', function (Request $request) use ($qcController) {
            $user = $request->getAttribute('user');
            $checkId = (int) $request->getAttribute('checkId');
            $itemId = (int) $request->getAttribute('itemId');
            $data = $qcController->updateCheckItem($user, $checkId, $itemId, $request->body());
            return Response::json($data);
        });

        $router->post('/api/qc/checks/{id}/complete', function (Request $request) use ($qcController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $qcController->completeCheck($user, $id, $request->body());
            return Response::json($data);
        });
    });

    // Warranty routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {

        $warrantyMessagingNotifications = new \App\Services\Messaging\MessagingNotificationService(
            $connection,
            new \App\Services\Messaging\MessagingService($connection)
        );
        $warrantyController = new \App\Services\Warranty\WarrantyController(
            new \App\Services\Warranty\WarrantyClaimService($connection),
            $gate,
            $warrantyMessagingNotifications
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
        $financialCategoryController = new \App\Services\Financial\FinancialCategoryController($connection, $gate);
        $technicianMarginController = new \App\Services\Reports\TechnicianMarginReportController(
            new \App\Services\Reports\TechnicianMarginReportService($connection, $settingsRepository),
            $gate
        );

        $router->get('/api/financial/categories', function (Request $request) use ($financialCategoryController) {
            $user = $request->getAttribute('user');
            $filters = [
                'type' => $request->queryParam('type'),
            ];
            $data = $financialCategoryController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/financial/categories/{type:purchase|expense|income}', function (Request $request) use ($financialCategoryController) {
            $user = $request->getAttribute('user');
            $type = (string) $request->getAttribute('type');
            $data = $financialCategoryController->index($user, ['type' => $type]);
            return Response::json($data);
        });

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

        $router->post('/api/financial/entries/{id}/attachment', function (Request $request) use ($financialController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $file = $request->file('file');

            $data = $financialController->uploadAttachment($user, $id, is_array($file) ? $file : []);
            return Response::json($data);
        });

        $router->delete('/api/financial/entries/{id}/attachment', function (Request $request) use ($financialController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $financialController->removeAttachment($user, $id);
            return Response::noContent();
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

        $router->get('/api/financial/reports/summary', function (Request $request) use ($financialController) {
            $user = $request->getAttribute('user');
            $params = [
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
            ];
            $data = $financialController->reportSummary($user, $params);
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

        $router->get('/api/reports/technician-margins', function (Request $request) use ($technicianMarginController) {
            $user = $request->getAttribute('user');
            $params = [
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
                'branch_id' => $request->queryParam('branch_id'),
            ];
            $data = $technicianMarginController->report($user, $params);
            return Response::json($data);
        });
    });

    // Customer retention report routes
    $customerRetentionWebhookConfig = $config['customer_retention']['webhooks'] ?? [];
    $customerRetentionWebhooks = new WebhookDispatcher(
        !empty($customerRetentionWebhookConfig['enabled']) ? ($customerRetentionWebhookConfig['endpoints'] ?? []) : [],
        (string) ($customerRetentionWebhookConfig['secret'] ?? ''),
        (int) ($customerRetentionWebhookConfig['timeout'] ?? 5),
        $auditLogger
    );

    $customerRetentionController = new \App\Services\Customer\CustomerRetentionReportController(
        new \App\Services\Customer\CustomerRetentionReportService(
            new \App\Services\Customer\CustomerRepository($connection),
            $customerRetentionWebhooks
        ),
        $gate
    );

    $router->group([Middleware::auth()], function (Router $router) use ($customerRetentionController) {
        $router->get('/api/reports/customer-retention', function (Request $request) use ($customerRetentionController) {
            $user = $request->getAttribute('user');
            $params = [
                'months' => $request->queryParam('months', 6),
                'limit' => $request->queryParam('limit', 50),
                'offset' => $request->queryParam('offset', 0),
                'query' => $request->queryParam('query'),
            ];
            $data = $customerRetentionController->index($user, $params);
            return Response::json($data);
        });

        $router->get('/api/reports/customer-retention/export', function (Request $request) use ($customerRetentionController) {
            $user = $request->getAttribute('user');
            $params = [
                'months' => $request->queryParam('months', 6),
                'format' => $request->queryParam('format', 'csv'),
                'query' => $request->queryParam('query'),
            ];
            $data = $customerRetentionController->export($user, $params);
            return Response::json($data);
        });

        $router->post('/api/reports/customer-retention/hooks', function (Request $request) use ($customerRetentionController) {
            $user = $request->getAttribute('user');
            $data = $customerRetentionController->dispatchCampaign($user, $request->body());
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

        $router->post('/api/time-tracking/{id}/approve', function (Request $request) use ($timeController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $timeController->approve($user, $id, $request->body());
            return Response::json($data);
        });

        $router->post('/api/time-tracking/{id}/reject', function (Request $request) use ($timeController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $timeController->reject($user, $id, $request->body());
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

        // Labor Tasks routes (for granular labor clocking)
        $laborTaskService = new \App\Services\TimeTracking\LaborTaskService($connection);
        $laborTaskController = new \App\Services\TimeTracking\LaborTaskController($laborTaskService, $gate);

        $router->get('/api/labor-tasks', function (Request $request) use ($laborTaskController) {
            $user = $request->getAttribute('user');
            $filters = [
                'search' => $request->queryParam('search'),
                'is_active' => $request->queryParam('is_active'),
                'service_type_id' => $request->queryParam('service_type_id'),
                'page' => $request->queryParam('page', 1),
                'per_page' => $request->queryParam('per_page', 100),
            ];
            $data = $laborTaskController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/labor-tasks/active', function (Request $request) use ($laborTaskController) {
            $user = $request->getAttribute('user');
            $data = $laborTaskController->active($user);
            return Response::json($data);
        });

        $router->get('/api/labor-tasks/efficiency', function (Request $request) use ($laborTaskController) {
            $user = $request->getAttribute('user');
            $filters = [
                'technician_id' => $request->queryParam('technician_id'),
                'start_date' => $request->queryParam('start_date'),
                'end_date' => $request->queryParam('end_date'),
                'group_by' => $request->queryParam('group_by', 'technician'),
            ];
            $data = $laborTaskController->efficiencyReport($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/labor-tasks/{id}', function (Request $request) use ($laborTaskController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $laborTaskController->show($user, $id);
            return Response::json($data);
        });

        $router->post('/api/labor-tasks', function (Request $request) use ($laborTaskController) {
            $user = $request->getAttribute('user');
            $data = $laborTaskController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/labor-tasks/{id}', function (Request $request) use ($laborTaskController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $laborTaskController->update($user, $id, $request->body());
            return Response::json($data);
        });

        $router->delete('/api/labor-tasks/{id}', function (Request $request) use ($laborTaskController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $laborTaskController->destroy($user, $id);
            return Response::json($data);
        });
    });

    // Settings routes (Admin only)
    $router->group([Middleware::auth(), Middleware::role('admin')], function (Router $router) use ($connection, $gate, $settingsRepository) {

        $settingsController = new \App\Services\Settings\SettingsController(
            $settingsRepository,
            $gate
        );
        $notificationTests = new \App\Services\Settings\NotificationTestService($settingsRepository);
        $notificationConfig = require __DIR__ . '/../config/notifications.php';
        $templateEngine = new \App\Support\Notifications\TemplateEngine();
        $notificationLogs = new \App\Support\Notifications\NotificationLogRepository($connection);
        $notifications = new \App\Support\Notifications\NotificationDispatcher(
            $notificationConfig,
            $templateEngine,
            $notificationLogs
        );
        $templateManager = new \App\Services\Notification\TemplateManager($connection, $notifications);

        $router->get('/api/settings', function (Request $request) use ($settingsController) {
            $user = $request->getAttribute('user');
            try {
                $data = $settingsController->index($user);
                return Response::json($data);
            } catch (\Exception $e) {
                error_log('Settings endpoint error: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                return Response::json([
                    'error' => 'Failed to fetch settings',
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ], 500);
            }
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

        $router->get('/api/storage/fees', function (Request $request) use ($connection) {
            $search = trim((string) $request->queryParam('search', ''));
            $pdo = $connection->pdo();

            $sql = <<<SQL
                SELECT
                    storage_fees.id,
                    impound_cases.case_number,
                    storage_fees.fee_date,
                    storage_fees.fee_type,
                    storage_fees.description,
                    storage_fees.amount,
                    storage_fees.status
                FROM storage_fees
                LEFT JOIN impound_cases ON impound_cases.id = storage_fees.impound_case_id
            SQL;
            $params = [];

            if ($search !== '') {
                $sql .= ' WHERE impound_cases.case_number LIKE :search';
                $params['search'] = '%' . $search . '%';
            }

            $sql .= ' ORDER BY storage_fees.fee_date DESC, storage_fees.id DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $fees = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            return Response::json(['data' => $fees]);
        });

        $router->post('/api/storage/fees', function (Request $request) use ($connection) {
            $payload = $request->body();
            $caseNumber = trim((string) ($payload['case_number'] ?? ''));
            $feeDate = trim((string) ($payload['fee_date'] ?? ''));
            $feeType = trim((string) ($payload['fee_type'] ?? ''));
            $amount = (float) ($payload['amount'] ?? 0);
            $status = trim((string) ($payload['status'] ?? 'posted'));
            $description = $payload['description'] ?? null;

            if ($caseNumber === '' || $feeDate === '' || $feeType === '') {
                return Response::badRequest('Case number, fee date, and fee type are required.');
            }

            $pdo = $connection->pdo();
            $stmt = $pdo->prepare('SELECT id FROM impound_cases WHERE case_number = ? LIMIT 1');
            $stmt->execute([$caseNumber]);
            $caseId = $stmt->fetchColumn();
            $stmt->closeCursor();

            if (!$caseId) {
                return Response::badRequest('Impound case not found.');
            }

            $insert = $pdo->prepare(
                'INSERT INTO storage_fees (impound_case_id, fee_date, fee_type, description, amount, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$caseId, $feeDate, $feeType, $description, $amount, $status]);
            $insert->closeCursor();

            $id = (int) $pdo->lastInsertId();
            $row = $pdo->prepare(
                'SELECT storage_fees.id, impound_cases.case_number, storage_fees.fee_date, storage_fees.fee_type,
                        storage_fees.description, storage_fees.amount, storage_fees.status
                 FROM storage_fees
                 LEFT JOIN impound_cases ON impound_cases.id = storage_fees.impound_case_id
                 WHERE storage_fees.id = ?'
            );
            $row->execute([$id]);
            $fee = $row->fetch(\PDO::FETCH_ASSOC);
            $row->closeCursor();

            return Response::created($fee);
        });

        $router->put('/api/storage/fees/{id}', function (Request $request) use ($connection) {
            $id = (int) $request->getAttribute('id');
            $payload = $request->body();
            $pdo = $connection->pdo();

            $stmt = $pdo->prepare(
                'SELECT storage_fees.id, storage_fees.impound_case_id, impound_cases.case_number,
                        storage_fees.fee_date, storage_fees.fee_type, storage_fees.description,
                        storage_fees.amount, storage_fees.status
                 FROM storage_fees
                 LEFT JOIN impound_cases ON impound_cases.id = storage_fees.impound_case_id
                 WHERE storage_fees.id = ?'
            );
            $stmt->execute([$id]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!$existing) {
                return Response::notFound('Storage fee not found.');
            }

            $caseNumber = trim((string) ($payload['case_number'] ?? $existing['case_number'] ?? ''));
            if ($caseNumber === '') {
                return Response::badRequest('Case number is required.');
            }

            $caseId = (int) $existing['impound_case_id'];
            if ($caseNumber !== ($existing['case_number'] ?? '')) {
                $caseStmt = $pdo->prepare('SELECT id FROM impound_cases WHERE case_number = ? LIMIT 1');
                $caseStmt->execute([$caseNumber]);
                $caseId = (int) $caseStmt->fetchColumn();
                $caseStmt->closeCursor();

                if (!$caseId) {
                    return Response::badRequest('Impound case not found.');
                }
            }

            $feeDate = trim((string) ($payload['fee_date'] ?? $existing['fee_date'] ?? ''));
            $feeType = trim((string) ($payload['fee_type'] ?? $existing['fee_type'] ?? ''));
            $description = $payload['description'] ?? $existing['description'];
            $amount = array_key_exists('amount', $payload) ? (float) $payload['amount'] : (float) $existing['amount'];
            $status = trim((string) ($payload['status'] ?? $existing['status'] ?? 'posted'));

            if ($feeDate === '' || $feeType === '') {
                return Response::badRequest('Fee date and fee type are required.');
            }

            $update = $pdo->prepare(
                'UPDATE storage_fees
                 SET impound_case_id = ?, fee_date = ?, fee_type = ?, description = ?, amount = ?, status = ?
                 WHERE id = ?'
            );
            $update->execute([$caseId, $feeDate, $feeType, $description, $amount, $status, $id]);
            $update->closeCursor();

            $row = $pdo->prepare(
                'SELECT storage_fees.id, impound_cases.case_number, storage_fees.fee_date, storage_fees.fee_type,
                        storage_fees.description, storage_fees.amount, storage_fees.status
                 FROM storage_fees
                 LEFT JOIN impound_cases ON impound_cases.id = storage_fees.impound_case_id
                 WHERE storage_fees.id = ?'
            );
            $row->execute([$id]);
            $fee = $row->fetch(\PDO::FETCH_ASSOC);
            $row->closeCursor();

            return Response::json($fee);
        });

        $router->delete('/api/storage/fees/{id}', function (Request $request) use ($connection) {
            $id = (int) $request->getAttribute('id');
            $pdo = $connection->pdo();
            $stmt = $pdo->prepare('DELETE FROM storage_fees WHERE id = ?');
            $stmt->execute([$id]);
            $deleted = $stmt->rowCount() > 0;
            $stmt->closeCursor();

            if (!$deleted) {
                return Response::notFound('Storage fee not found.');
            }

            return Response::noContent();
        });

        $router->post('/api/storage/templates/preview', function (Request $request) use ($settingsRepository) {
            $payload = $request->body();
            $templateKey = (string) ($payload['template_key'] ?? '');
            $template = $payload['template'] ?? null;

            if ($templateKey === '') {
                return Response::badRequest('template_key is required.');
            }

            $address = $settingsRepository->get('shop.address', []);
            $shopAddress = '';
            if (is_array($address)) {
                $lines = array_filter([
                    trim((string) ($address['street'] ?? '')),
                    trim(sprintf(
                        '%s%s%s',
                        (string) ($address['city'] ?? ''),
                        !empty($address['state']) ? ', ' . $address['state'] : '',
                        !empty($address['postal_code']) ? ' ' . $address['postal_code'] : ''
                    )),
                    trim((string) ($address['country'] ?? '')),
                ]);
                $shopAddress = implode('<br>', $lines);
            } elseif (is_string($address)) {
                $shopAddress = $address;
            }

            $settings = [
                'shop_name' => $settingsRepository->get('shop.name', 'Storage Facility'),
                'shop_address' => $shopAddress,
                'shop_phone' => $settingsRepository->get('shop.phone', ''),
            ];

            if (is_string($template)) {
                $settings[$templateKey] = $template;
            } else {
                $settings[$templateKey] = $settingsRepository->get($templateKey);
            }

            $case = [
                'case_number' => 'IMP-2024-017',
                'notice_date' => new \DateTimeImmutable('now'),
                'status' => 'draft',
                'intake_location' => 'Main Storage Yard',
            ];
            $owner = [
                'name' => 'Alex Parker',
                'address' => '1234 Market St',
                'city' => 'Austin',
                'state' => 'TX',
                'zip' => '78701',
                'phone' => '(512) 555-0172',
            ];
            $vehicle = [
                'year' => '2019',
                'make' => 'Honda',
                'model' => 'Civic',
                'vin' => '19XFC2F69KE000000',
                'license_plate' => 'TX-9031',
            ];
            $fees = [
                'billable_days' => 12,
                'daily_rate' => 35.0,
                'daily_amount' => 420.0,
                'gate_fee' => 65.0,
                'total' => 485.0,
            ];
            $notice = [
                'notice_date' => new \DateTimeImmutable('now'),
                'due_date' => (new \DateTimeImmutable('now'))->modify('+10 days'),
                'notice_type' => 'Lien Notice',
            ];

            switch ($templateKey) {
                case 'storage.notice.notice_of_claim':
                    $generator = new \App\Support\Pdf\LienNoticePdfGenerator();
                    $pdf = $generator->generateNoticeOfClaim($case, $owner, $vehicle, $fees, $settings);
                    break;
                case 'storage.notice.lien_notice':
                    $generator = new \App\Support\Pdf\LienNoticePdfGenerator();
                    $pdf = $generator->generateLienNotice($notice, $case, $owner, $vehicle, $fees, $settings);
                    break;
                case 'storage.notice.tow_authorization':
                    $generator = new \App\Support\Pdf\StorageFormPdfGenerator();
                    $pdf = $generator->generateTowAuthorization([
                        'shop_name' => $settings['shop_name'],
                        'shop_address' => $settings['shop_address'],
                        'shop_phone' => $settings['shop_phone'],
                        'notice_date' => (new \DateTimeImmutable('now'))->format('M d, Y'),
                        'case_number' => $case['case_number'],
                        'owner_name' => $owner['name'],
                        'owner_address' => $owner['address'],
                        'owner_city' => $owner['city'],
                        'owner_state' => $owner['state'],
                        'owner_zip' => $owner['zip'],
                        'owner_phone' => $owner['phone'],
                        'vehicle_year' => $vehicle['year'],
                        'vehicle_make' => $vehicle['make'],
                        'vehicle_model' => $vehicle['model'],
                        'vehicle_vin' => $vehicle['vin'],
                        'vehicle_license_plate' => $vehicle['license_plate'],
                        'tow_provider' => 'Metro Tow Services',
                        'intake_location' => $case['intake_location'],
                    ], is_string($settings[$templateKey] ?? null) ? (string) $settings[$templateKey] : null);
                    break;
                case 'storage.notice.lien_ack':
                    $generator = new \App\Support\Pdf\StorageFormPdfGenerator();
                    $pdf = $generator->generateLienAcknowledgment([
                        'shop_name' => $settings['shop_name'],
                        'shop_address' => $settings['shop_address'],
                        'shop_phone' => $settings['shop_phone'],
                        'notice_date' => (new \DateTimeImmutable('now'))->format('M d, Y'),
                        'case_number' => $case['case_number'],
                        'owner_name' => $owner['name'],
                        'vehicle_year' => $vehicle['year'],
                        'vehicle_make' => $vehicle['make'],
                        'vehicle_model' => $vehicle['model'],
                        'vehicle_vin' => $vehicle['vin'],
                        'vehicle_license_plate' => $vehicle['license_plate'],
                        'fees_total' => '$' . number_format((float) $fees['total'], 2),
                    ], is_string($settings[$templateKey] ?? null) ? (string) $settings[$templateKey] : null);
                    break;
                default:
                    return Response::badRequest('Unknown template_key.');
            }

            return Response::make($pdf, 200, ['Content-Type' => 'application/pdf']);
        });

        $router->post('/api/settings/notifications/smtp/test-connection', function (Request $request) use ($notificationTests) {
            try {
                $data = $notificationTests->testSmtpConnection();
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::badRequest($e->getMessage());
            } catch (\Throwable $e) {
                error_log('SMTP test connection failed: ' . $e->getMessage());
                return Response::serverError('Failed to test SMTP connection.');
            }
        });

        $router->post('/api/settings/notifications/smtp/test-email', function (Request $request) use ($notificationTests) {
            $recipient = trim((string) $request->input('recipient', ''));

            try {
                $data = $notificationTests->sendTestEmail($recipient);
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::badRequest($e->getMessage());
            } catch (\Throwable $e) {
                error_log('SMTP test email failed: ' . $e->getMessage());
                return Response::serverError('Failed to send test email.');
            }
        });

        $router->post('/api/settings/notifications/twilio/test-connection', function (Request $request) use ($notificationTests) {
            try {
                $data = $notificationTests->testTwilioConnection();
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::badRequest($e->getMessage());
            } catch (\Throwable $e) {
                error_log('Twilio test connection failed: ' . $e->getMessage());
                return Response::serverError('Failed to test Twilio connection.');
            }
        });

        $router->post('/api/settings/notifications/twilio/test-sms', function (Request $request) use ($notificationTests) {
            $recipient = trim((string) $request->input('recipient', ''));

            try {
                $data = $notificationTests->sendTestSms($recipient);
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::badRequest($e->getMessage());
            } catch (\Throwable $e) {
                error_log('Twilio test SMS failed: ' . $e->getMessage());
                return Response::serverError('Failed to send test SMS.');
            }
        });

        $router->get('/api/notifications/templates', function () use ($templateManager) {
            $templates = $templateManager->all();
            return Response::json(['templates' => $templates]);
        });

        $router->put('/api/notifications/templates/{key}', function (Request $request) use ($templateManager) {
            $key = (string) $request->getAttribute('key');
            $payload = $request->body();
            $subject = trim((string) ($payload['subject'] ?? ''));
            $body = (string) ($payload['body'] ?? '');
            $channel = (string) ($payload['channel'] ?? 'email');

            if ($key === '' || $subject === '' || $body === '') {
                return Response::badRequest('Template key, subject, and body are required.');
            }

            try {
                $templateManager->save($key, [
                    'subject' => $subject,
                    'body' => $body,
                    'channel' => $channel,
                ]);
            } catch (\InvalidArgumentException $e) {
                return Response::badRequest($e->getMessage());
            } catch (\Throwable $e) {
                error_log('Template update failed: ' . $e->getMessage());
                return Response::serverError('Failed to update template.');
            }

            return Response::json([
                'template' => [
                    'template_key' => $key,
                    'subject' => $subject,
                    'body' => $body,
                    'channel' => $channel,
                ],
            ]);
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

    // CMS Management routes (Admin/Manager for full access, Technician for content editing)
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $cmsCategoryController, $cmsPageController, $cmsMenuController, $cmsMediaController, $cmsCacheService, $gate, $cmsApiController) {

        // CMS Dashboard
        $router->get('/api/cms/dashboard', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            try {
                $data = $cmsApiController->dashboard($user);
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        // CMS Pages
        $router->get('/api/cms/pages', function (Request $request) use ($cmsPageController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'search' => $request->queryParam('search'),
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ];

            $data = $cmsPageController->index($user, $filters);
            return Response::json($data);
        });

        // Form options for page editor (templates, components, parent pages)
        $router->get('/api/cms/pages/form-options', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            try {
                $data = $cmsApiController->getPageFormOptions($user);
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        $router->get('/api/cms/pages/{id}', function (Request $request) use ($cmsPageController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $cmsPageController->show($user, $id);

            if ($data === null) {
                return Response::notFound('Page not found');
            }

            return Response::json($data);
        });

        $router->post('/api/cms/pages', function (Request $request) use ($cmsPageController) {
            $user = $request->getAttribute('user');
            $data = $cmsPageController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/cms/pages/{id}', function (Request $request) use ($cmsPageController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $cmsPageController->update($user, $id, $request->body());

            if ($data === null) {
                return Response::notFound('Page not found');
            }

            return Response::json($data);
        });

        $router->delete('/api/cms/pages/{id}', function (Request $request) use ($cmsPageController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $cmsPageController->destroy($user, $id);
            return Response::noContent();
        });

        $router->post('/api/cms/pages/{id}/publish', function (Request $request) use ($cmsPageController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $data = $cmsPageController->publish($user, $id);

            if ($data === null) {
                return Response::notFound('Page not found');
            }

            return Response::json($data);
        });

        $router->get('/api/cms/pages/{id}/preview', function (Request $request) use ($cmsPageController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $html = $cmsPageController->previewPage($user, $id);

            if ($html === null) {
                return Response::notFound('Page not found');
            }

            return Response::html($html);
        });

        // CMS Categories
        $router->get('/api/cms/categories', function (Request $request) use ($cmsCategoryController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'search' => $request->queryParam('search'),
                'limit' => $request->queryParam('limit'),
                'offset' => $request->queryParam('offset'),
            ];

            $data = $cmsCategoryController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/cms/categories/{id}', function (Request $request) use ($cmsCategoryController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $cmsCategoryController->show($user, $id);

            if ($data === null) {
                return Response::notFound('Category not found');
            }

            return Response::json($data);
        });

        $router->post('/api/cms/categories', function (Request $request) use ($cmsCategoryController) {
            $user = $request->getAttribute('user');
            $data = $cmsCategoryController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/cms/categories/{id}', function (Request $request) use ($cmsCategoryController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $cmsCategoryController->update($user, $id, $request->body());

            if ($data === null) {
                return Response::notFound('Category not found');
            }

            return Response::json($data);
        });

        $router->delete('/api/cms/categories/{id}', function (Request $request) use ($cmsCategoryController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            // Check if category has pages
            $pageCount = $cmsCategoryController->getPageCount($id);
            if ($pageCount > 0) {
                return Response::json([
                    'error' => 'Cannot delete category with pages',
                    'message' => "This category has {$pageCount} page(s). Please reassign or delete them first.",
                    'page_count' => $pageCount
                ], 400);
            }

            $cmsCategoryController->destroy($user, $id);
            return Response::noContent();
        });

        // CMS Menus
        $router->get('/api/cms/menus', function (Request $request) use ($cmsMenuController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
            ];

            $data = $cmsMenuController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/cms/menus/{id}', function (Request $request) use ($cmsMenuController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $cmsMenuController->show($user, $id);

            if ($data === null) {
                return Response::notFound('Menu not found');
            }

            return Response::json($data);
        });

        $router->post('/api/cms/menus', function (Request $request) use ($cmsMenuController) {
            $user = $request->getAttribute('user');
            $data = $cmsMenuController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/cms/menus/{id}', function (Request $request) use ($cmsMenuController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $cmsMenuController->update($user, $id, $request->body());

            if ($data === null) {
                return Response::notFound('Menu not found');
            }

            return Response::json($data);
        });

        $router->delete('/api/cms/menus/{id}', function (Request $request) use ($cmsMenuController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $cmsMenuController->destroy($user, $id);
            return Response::noContent();
        });

        // CMS Media Library
        $router->get('/api/cms/media', function (Request $request) use ($cmsMediaController) {
            $user = $request->getAttribute('user');
            $filters = [
                'status' => $request->queryParam('status'),
                'search' => $request->queryParam('search'),
            ];

            $data = $cmsMediaController->index($user, $filters);
            return Response::json($data);
        });

        $router->get('/api/cms/media/{id}', function (Request $request) use ($cmsMediaController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $cmsMediaController->show($user, $id);

            if ($data === null) {
                return Response::notFound('Media not found');
            }

            return Response::json($data);
        });

        $router->post('/api/cms/media', function (Request $request) use ($cmsMediaController) {
            $user = $request->getAttribute('user');
            $data = $cmsMediaController->store($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/cms/media/{id}', function (Request $request) use ($cmsMediaController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $cmsMediaController->update($user, $id, $request->body());

            if ($data === null) {
                return Response::notFound('Media not found');
            }

            return Response::json($data);
        });

        $router->delete('/api/cms/media/{id}', function (Request $request) use ($cmsMediaController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');

            $cmsMediaController->destroy($user, $id);
            return Response::noContent();
        });

        // CMS Components
        $router->get('/api/cms/components', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            try {
                $filters = [
                    'type' => $request->queryParam('type'),
                    'search' => $request->queryParam('search'),
                ];
                $data = $cmsApiController->listComponents($user, $filters);
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        $router->get('/api/cms/components/{id}', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            try {
                $data = $cmsApiController->getComponent($user, $id);
                if ($data === null) {
                    return Response::notFound('Component not found');
                }
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        $router->post('/api/cms/components', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            try {
                $data = $cmsApiController->createComponent($user, $request->body());
                return Response::created($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        $router->put('/api/cms/components/{id}', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            try {
                $data = $cmsApiController->updateComponent($user, $id, $request->body());
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        $router->delete('/api/cms/components/{id}', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            try {
                $cmsApiController->deleteComponent($user, $id);
                return Response::noContent();
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        $router->post('/api/cms/components/{id}/duplicate', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            try {
                $data = $cmsApiController->duplicateComponent($user, $id);
                if ($data === null) {
                    return Response::notFound('Component not found');
                }
                return Response::created($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        // CMS Templates
        $router->get('/api/cms/templates', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            try {
                $filters = [
                    'active' => $request->queryParam('active'),
                    'search' => $request->queryParam('search'),
                ];
                $data = $cmsApiController->listTemplates($user, $filters);
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        $router->get('/api/cms/templates/{id}', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            try {
                $data = $cmsApiController->getTemplate($user, $id);
                if ($data === null) {
                    return Response::notFound('Template not found');
                }
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        // Fixed create template route
        $router->post('/api/cms/templates', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            try {
                $data = $cmsApiController->createTemplate($user, $request->body());
                return Response::created($data);
            } catch (\App\Support\Auth\UnauthorizedException $e) {
                return Response::forbidden($e->getMessage());
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) { 
                    return Response::json([
                        'message' => 'A template with this name or slug already exists.'
                    ], 409); // 409 Conflict
                }
                throw $e;
            }
        });

        // Fixed update template route
        $router->put('/api/cms/templates/{id}', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            try {
                $data = $cmsApiController->updateTemplate($user, $id, $request->body());
                return Response::json($data);
            } catch (\App\Support\Auth\UnauthorizedException $e) {
                return Response::forbidden($e->getMessage());
            } catch (\PDOException $e) {
                if ($e->getCode() == 23000) { 
                    return Response::json([
                        'message' => 'A template with this name or slug already exists.'
                    ], 409);
                }
                throw $e;
            }
        });

        // Fixed delete template route
$router->delete('/api/cms/templates/{id}', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            try {
                $cmsApiController->deleteTemplate($user, $id);
                return Response::noContent();
            } catch (\App\Support\Auth\UnauthorizedException $e) {
                return Response::forbidden($e->getMessage());
            } catch (\RuntimeException $e) {
                // Use Response::json to send 'message' key to match frontend expectation
                return Response::json(['message' => $e->getMessage()], 400);
            }
        });

        // CMS Settings (Admin only)
        $router->get('/api/cms/settings', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            try {
                $data = $cmsApiController->getSettings($user);
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        $router->put('/api/cms/settings', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            try {
                $data = $cmsApiController->updateSettings($user, $request->body());
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        // CMS Cache Management
        $router->get('/api/cms/cache', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            try {
                $data = $cmsApiController->getCacheStats($user);
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        $router->post('/api/cms/cache/clear', function (Request $request) use ($cmsApiController) {
            $user = $request->getAttribute('user');
            try {
                $type = $request->body()['type'] ?? null;
                $data = $cmsApiController->clearCache($user, $type);
                return Response::json($data);
            } catch (\RuntimeException $e) {
                return Response::forbidden($e->getMessage());
            }
        });

        // 404 Log Management (Admin/Manager only)
        $router->get('/api/404-logs', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            if (!in_array($user->role, ['admin', 'manager'], true)) {
                return Response::forbidden('Insufficient permissions');
            }

            $notFoundLogRepo = new \App\Services\NotFound\NotFoundLogRepository($connection);

            $page = max(1, (int) ($request->queryParam('page') ?? 1));
            $perPage = min(100, max(1, (int) ($request->queryParam('per_page') ?? 50)));
            $offset = ($page - 1) * $perPage;

            $filters = [];
            if ($request->queryParam('uri')) {
                $filters['uri'] = $request->queryParam('uri');
            }
            if ($request->queryParam('min_hits')) {
                $filters['min_hits'] = (int) $request->queryParam('min_hits');
            }
            if ($request->queryParam('sort')) {
                $filters['sort'] = $request->queryParam('sort');
            }

            $logs = $notFoundLogRepo->list($filters, $perPage, $offset);
            $total = $notFoundLogRepo->count($filters);
            $stats = $notFoundLogRepo->getStatistics();

            return Response::json([
                'logs' => array_map(fn($log) => $log->toArray(), $logs),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => ceil($total / $perPage),
                ],
                'statistics' => $stats,
            ]);
        });

        $router->delete('/api/404-logs/{id}', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            if (!in_array($user->role, ['admin', 'manager'], true)) {
                return Response::forbidden('Insufficient permissions');
            }

            $id = (int) $request->getAttribute('id');
            $notFoundLogRepo = new \App\Services\NotFound\NotFoundLogRepository($connection);

            if ($notFoundLogRepo->delete($id)) {
                return Response::noContent();
            }

            return Response::notFound('Log entry not found');
        });

        $router->post('/api/404-logs/clear', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            if ($user->role !== 'admin') {
                return Response::forbidden('Admin access required');
            }

            $notFoundLogRepo = new \App\Services\NotFound\NotFoundLogRepository($connection);
            $count = $notFoundLogRepo->clearAll();

            return Response::json(['message' => 'All 404 logs cleared', 'deleted_count' => $count]);
        });

        // Redirect Management (Admin/Manager only)
        $router->get('/api/redirects', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            if (!in_array($user->role, ['admin', 'manager'], true)) {
                return Response::forbidden('Insufficient permissions');
            }

            $redirectRepo = new \App\Services\NotFound\RedirectRepository($connection);

            $page = max(1, (int) ($request->queryParam('page') ?? 1));
            $perPage = min(100, max(1, (int) ($request->queryParam('per_page') ?? 50)));
            $offset = ($page - 1) * $perPage;

            $filters = [];
            if ($request->queryParam('search')) {
                $filters['search'] = $request->queryParam('search');
            }
            if ($request->queryParam('is_active') !== null) {
                $filters['is_active'] = (int) $request->queryParam('is_active');
            }

            $redirects = $redirectRepo->list($filters, $perPage, $offset);
            $total = $redirectRepo->count($filters);

            return Response::json([
                'redirects' => array_map(fn($redirect) => $redirect->toArray(), $redirects),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => ceil($total / $perPage),
                ],
            ]);
        });

        $router->get('/api/redirects/{id}', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            if (!in_array($user->role, ['admin', 'manager'], true)) {
                return Response::forbidden('Insufficient permissions');
            }

            $id = (int) $request->getAttribute('id');
            $redirectRepo = new \App\Services\NotFound\RedirectRepository($connection);
            $redirect = $redirectRepo->find($id);

            if (!$redirect) {
                return Response::notFound('Redirect not found');
            }

            return Response::json($redirect->toArray());
        });

        $router->post('/api/redirects', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            if (!in_array($user->role, ['admin', 'manager'], true)) {
                return Response::forbidden('Insufficient permissions');
            }

            $body = $request->body();
            $redirectRepo = new \App\Services\NotFound\RedirectRepository($connection);

            $data = [
                'source_path' => $body['source_path'] ?? '',
                'destination_path' => $body['destination_path'] ?? '',
                'redirect_type' => $body['redirect_type'] ?? '301',
                'match_type' => $body['match_type'] ?? 'exact',
                'is_active' => isset($body['is_active']) ? (bool) $body['is_active'] : true,
                'description' => $body['description'] ?? null,
                'created_by' => $user->id,
            ];

            if (empty($data['source_path']) || empty($data['destination_path'])) {
                return Response::badRequest('Source and destination paths are required');
            }

            try {
                $redirect = $redirectRepo->create($data);
                return Response::created($redirect->toArray());
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    return Response::badRequest('A redirect for this source path already exists');
                }
                throw $e;
            }
        });

        $router->put('/api/redirects/{id}', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            if (!in_array($user->role, ['admin', 'manager'], true)) {
                return Response::forbidden('Insufficient permissions');
            }

            $id = (int) $request->getAttribute('id');
            $body = $request->body();
            $redirectRepo = new \App\Services\NotFound\RedirectRepository($connection);

            $redirect = $redirectRepo->update($id, $body);

            if (!$redirect) {
                return Response::notFound('Redirect not found');
            }

            return Response::json($redirect->toArray());
        });

        $router->delete('/api/redirects/{id}', function (Request $request) use ($connection) {
            $user = $request->getAttribute('user');
            if (!in_array($user->role, ['admin', 'manager'], true)) {
                return Response::forbidden('Insufficient permissions');
            }

            $id = (int) $request->getAttribute('id');
            $redirectRepo = new \App\Services\NotFound\RedirectRepository($connection);

            if ($redirectRepo->delete($id)) {
                return Response::noContent();
            }

            return Response::notFound('Redirect not found');
        });
    });

    // Towing Pricing Matrix routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {
        $towingPricingController = new \App\Services\Towing\TowingPricingController(
            new \App\Services\Towing\TowingPricingService($connection),
            $gate
        );

        // Service Classes
        $router->get('/api/towing/service-classes', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $filters = ['active' => $request->queryParam('active')];
            $data = $towingPricingController->listServiceClasses($user, $filters);
            return Response::json(['data' => $data]);
        });

        $router->get('/api/towing/service-classes/{id}', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $towingPricingController->showServiceClass($user, $id);
            return $data ? Response::json($data) : Response::notFound('Service class not found');
        });

        $router->post('/api/towing/service-classes', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $data = $towingPricingController->storeServiceClass($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/towing/service-classes/{id}', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $towingPricingController->updateServiceClass($user, $id, $request->body());
            return $data ? Response::json($data) : Response::notFound('Service class not found');
        });

        $router->delete('/api/towing/service-classes/{id}', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $towingPricingController->destroyServiceClass($user, $id);
            return Response::noContent();
        });

        // Service Types
        $router->get('/api/towing/service-types', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $filters = ['active' => $request->queryParam('active')];
            $data = $towingPricingController->listServiceTypes($user, $filters);
            return Response::json(['data' => $data]);
        });

        $router->get('/api/towing/service-types/{id}', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $towingPricingController->showServiceType($user, $id);
            return $data ? Response::json($data) : Response::notFound('Service type not found');
        });

        $router->post('/api/towing/service-types', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $data = $towingPricingController->storeServiceType($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/towing/service-types/{id}', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $towingPricingController->updateServiceType($user, $id, $request->body());
            return $data ? Response::json($data) : Response::notFound('Service type not found');
        });

        $router->delete('/api/towing/service-types/{id}', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $towingPricingController->destroyServiceType($user, $id);
            return Response::noContent();
        });

        // Price Matrix
        $router->get('/api/towing/pricing-matrix', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $filters = [
                'service_class_id' => $request->queryParam('service_class_id'),
                'service_type_id' => $request->queryParam('service_type_id'),
                'is_active' => $request->queryParam('is_active'),
            ];
            $data = $towingPricingController->listPriceMatrix($user, $filters);
            return Response::json(['data' => $data]);
        });

        $router->get('/api/towing/pricing-matrix/full', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $data = $towingPricingController->getFullMatrix($user);
            return Response::json($data);
        });

        $router->get('/api/towing/pricing-matrix/{id}', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $towingPricingController->showPriceMatrix($user, $id);
            return $data ? Response::json($data) : Response::notFound('Price matrix entry not found');
        });

        $router->post('/api/towing/pricing-matrix', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $data = $towingPricingController->storePriceMatrix($user, $request->body());
            return Response::created($data);
        });

        $router->put('/api/towing/pricing-matrix/{id}', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $data = $towingPricingController->updatePriceMatrix($user, $id, $request->body());
            return $data ? Response::json($data) : Response::notFound('Price matrix entry not found');
        });

        $router->post('/api/towing/pricing-matrix/upsert', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $data = $towingPricingController->upsertPriceMatrix($user, $request->body());
            return Response::json($data);
        });

        $router->post('/api/towing/pricing-matrix/bulk', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $body = $request->body();
            $entries = $body['entries'] ?? [];
            $data = $towingPricingController->bulkUpsertPriceMatrix($user, $entries);
            return Response::json(['data' => $data]);
        });

        $router->delete('/api/towing/pricing-matrix/{id}', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $towingPricingController->destroyPriceMatrix($user, $id);
            return Response::noContent();
        });

        // Calculate price endpoint
        $router->post('/api/towing/calculate-price', function (Request $request) use ($towingPricingController) {
            $user = $request->getAttribute('user');
            $body = $request->body();
            $classId = (int) ($body['service_class_id'] ?? 0);
            $typeId = (int) ($body['service_type_id'] ?? 0);
            $params = [
                'loaded_miles' => $body['loaded_miles'] ?? 0,
                'deadhead_miles' => $body['deadhead_miles'] ?? 0,
                'after_hours' => $body['after_hours'] ?? false,
                'accident_cleanup' => $body['accident_cleanup'] ?? false,
                'winch' => $body['winch'] ?? false,
                'storage_days' => $body['storage_days'] ?? 0,
            ];
            $data = $towingPricingController->calculatePrice($user, $classId, $typeId, $params);
            return Response::json($data);
        });
    });

    // =========================================================================
    // Module Settings & User Groups Routes
    // =========================================================================

    // Accessible modules endpoint (for any authenticated user) - MUST be before {key} route
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate) {
        $router->get('/api/modules/accessible', function (Request $request) use ($connection, $gate) {
            $user = $request->getAttribute('user');
            $moduleService = new \App\Support\Auth\ModuleAccessService($connection, $gate);
            $moduleController = new \App\Services\Settings\ModuleSettingsController($moduleService, $gate);
            return Response::json($moduleController->accessible($user));
        });
    });

    // Admin-only module management routes
    $router->group([Middleware::auth(), Middleware::role('admin')], function (Router $router) use ($connection, $gate) {
        $moduleService = new \App\Support\Auth\ModuleAccessService($connection, $gate);
        $moduleController = new \App\Services\Settings\ModuleSettingsController($moduleService, $gate);
        $userGroupService = new \App\Services\UserGroup\UserGroupService($connection);
        $userGroupController = new \App\Services\UserGroup\UserGroupController($userGroupService, $gate);

        // Module Settings
        $router->get('/api/modules', function (Request $request) use ($moduleController) {
            $user = $request->getAttribute('user');
            return Response::json($moduleController->index($user));
        });

        $router->get('/api/modules/{key}', function (Request $request) use ($moduleController) {
            $user = $request->getAttribute('user');
            $key = $request->getAttribute('key');
            return Response::json($moduleController->show($user, (string) $key));
        });

        $router->put('/api/modules/{key}', function (Request $request) use ($moduleController) {
            $user = $request->getAttribute('user');
            $key = $request->getAttribute('key');
            return Response::json($moduleController->update($user, (string) $key, $request->body()));
        });

        $router->put('/api/modules', function (Request $request) use ($moduleController) {
            $user = $request->getAttribute('user');
            return Response::json($moduleController->bulkUpdate($user, $request->body()));
        });

        // User Groups
        $router->get('/api/user-groups', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            return Response::json(['data' => $userGroupController->index($user)]);
        });

        $router->post('/api/user-groups', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            return Response::created($userGroupController->store($user, $request->body()));
        });

        $router->get('/api/user-groups/{id}', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            return Response::json($userGroupController->show($user, $id));
        });

        $router->put('/api/user-groups/{id}', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            return Response::json($userGroupController->update($user, $id, $request->body()));
        });

        $router->delete('/api/user-groups/{id}', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $userGroupController->destroy($user, $id);
            return Response::noContent();
        });

        // User Group Members
        $router->get('/api/user-groups/{id}/members', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            return Response::json(['data' => $userGroupController->members($user, $id)]);
        });

        $router->post('/api/user-groups/{id}/members', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $body = $request->body();
            $userId = (int) ($body['user_id'] ?? 0);
            $userGroupController->addMember($user, $id, $userId);
            return Response::json(['success' => true]);
        });

        $router->delete('/api/user-groups/{groupId}/members/{userId}', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            $groupId = (int) $request->getAttribute('groupId');
            $userId = (int) $request->getAttribute('userId');
            $userGroupController->removeMember($user, $groupId, $userId);
            return Response::noContent();
        });

        $router->put('/api/user-groups/{id}/members', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $body = $request->body();
            $userIds = $body['user_ids'] ?? [];
            $userGroupController->setMembers($user, $id, $userIds);
            return Response::json(['success' => true]);
        });

        $router->get('/api/user-groups/{id}/non-members', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $search = $request->queryParam('search');
            return Response::json(['data' => $userGroupController->nonMembers($user, $id, $search)]);
        });

        // User's groups
        $router->get('/api/users/{id}/groups', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            return Response::json(['data' => $userGroupController->userGroups($user, $id)]);
        });

        $router->put('/api/users/{id}/groups', function (Request $request) use ($userGroupController) {
            $user = $request->getAttribute('user');
            $id = (int) $request->getAttribute('id');
            $body = $request->body();
            $groupIds = $body['group_ids'] ?? [];
            $userGroupController->setUserGroups($user, $id, $groupIds);
            return Response::json(['success' => true]);
        });
    });
};
