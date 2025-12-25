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
        return [
            'enabled' => (bool) $settingsRepository->get(
                'integrations.recaptcha.enabled',
                $config['recaptcha']['enabled'] ?? false
            ),
            'site_key' => $settingsRepository->get('integrations.recaptcha.site_key', $config['recaptcha']['site_key'] ?? null),
            'secret_key' => $settingsRepository->get(
                'integrations.recaptcha.secret_key',
                $config['recaptcha']['secret_key'] ?? null
            ),
            'score_threshold' => (float) $settingsRepository->get(
                'integrations.recaptcha.score_threshold',
                $config['recaptcha']['score_threshold'] ?? 0.5
            ),
        ];
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

    // Apply global rate limiting (60 requests per minute per IP+path)
    $router->middleware(Middleware::throttle(60, 60));

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

    // Get fully rendered HTML for a page (for Vue SPA)
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

        $router->get('/api/dashboard', function (Request $request) use ($dashboardController) {
            /** @var \App\Models\User|null $user */
            $user = $request->getAttribute('user');
            $params = [
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
        $inventoryController = new \App\Services\Inventory\InventoryItemController($inventoryRepository, $gate);
        $inventoryLookupService = new \App\Services\Inventory\InventoryLookupService($connection);
        $inventoryLookupController = new \App\Services\Inventory\InventoryLookupController($inventoryLookupService, $gate);

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
    });

    // Estimate routes
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $auditLogger) {

        $bundleController = new \App\Services\Estimate\BundleController(
            new \App\Services\Estimate\BundleService($connection),
            $gate
        );

        $estimateRepository = new \App\Services\Estimate\EstimateRepository($connection, $auditLogger);
        $estimateEditor = new \App\Services\Estimate\EstimateEditorService($connection, $auditLogger);
        $estimateController = new \App\Services\Estimate\EstimateController($estimateRepository, $gate, $estimateEditor);

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
    $router->group([Middleware::auth()], function (Router $router) use ($connection, $gate, $config, $paymentConfig) {

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

    // User controller for technician listings
    $userController = new \App\Services\User\UserController(
        new \App\Services\User\UserRepository($connection),
        $gate,
        $totpService
    );

    // Role controller for role management
    $roleController = new \App\Services\Role\RoleController(
        new \App\Services\Role\RoleRepository($connection),
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

    $router->group([Middleware::auth()], function (Router $router) use ($appointmentController, $userController, $roleController) {
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
            $data = $inspectionController->uploadMedia($user, $id, $file);
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
        $financialCategoryController = new \App\Services\Financial\FinancialCategoryController($connection, $gate);

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
    });

    // Settings routes (Admin only)
    $router->group([Middleware::auth(), Middleware::role('admin')], function (Router $router) use ($connection, $gate, $settingsRepository) {

        $settingsController = new \App\Services\Settings\SettingsController(
            $settingsRepository,
            $gate
        );

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
};
