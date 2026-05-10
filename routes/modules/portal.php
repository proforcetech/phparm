<?php

use App\Services\Assets\AssetAcquisitionRepository;
use App\Services\Assets\AssetAcquisitionService;
use App\Services\Assets\AssetDecommissionRepository;
use App\Services\Assets\AssetLeaseRepository;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Contracts\ContractAmendmentRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSignatureRepository;
use App\Services\Crm\CompanyRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Customer\CustomerRepository;
use App\Services\Estimate\EstimateRepository;
use App\Services\Inventory\CoreReturnService;
use App\Services\Invoice\InvoicePublicPaymentTokenService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\PaymentProcessingService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Portal\PortalAccountRepository;
use App\Services\Portal\PortalApiTokenController;
use App\Services\Portal\PortalApiTokenRepository;
use App\Services\Portal\PortalApiTokenService;
use App\Services\Portal\PortalApprovalController;
use App\Services\Portal\PortalApprovalService;
use App\Services\Portal\PortalAssetController;
use App\Services\Portal\PortalAssetViewService;
use App\Services\Portal\PortalAuditController;
use App\Services\Portal\PortalAuditService;
use App\Services\Portal\PortalAuthService;
use App\Services\Portal\PortalBillingController;
use App\Services\Portal\PortalBillingService;
use App\Services\Portal\PortalContractController;
use App\Services\Portal\PortalContractService;
use App\Services\Portal\PortalContractSigningController;
use App\Services\Portal\PortalContractSigningService;
use App\Services\Portal\PortalController;
use App\Services\Portal\PortalCsatController;
use App\Services\Portal\PortalCsatRepository;
use App\Services\Portal\PortalCsatService;
use App\Services\Portal\PortalEtaPromiseController;
use App\Services\Portal\PortalEtaPromiseRepository;
use App\Services\Portal\PortalEtaPromiseService;
use App\Services\Portal\PortalLifecycleController;
use App\Services\Portal\PortalLifecycleService;
use App\Services\Portal\PortalMessagingController;
use App\Services\Portal\PortalMessagingService;
use App\Services\Portal\PortalNotificationPreferenceController;
use App\Services\Portal\PortalNotificationPreferenceRepository;
use App\Services\Portal\PortalNotificationPreferenceService;
use App\Services\Portal\PortalPaymentMethodRepository;
use App\Services\Portal\PortalPermissionService;
use App\Services\Portal\PortalRequestController;
use App\Services\Portal\PortalRequestWizardService;
use App\Services\Portal\PortalSsoController;
use App\Services\Portal\PortalSsoService;
use App\Services\Sso\OidcHttpClient;
use App\Services\Sso\SsoLoginAttemptRepository;
use App\Services\Sso\SsoProviderRepository;
use App\Services\Sso\SsoUserLinkRepository;
use App\Services\Tickets\ItHelpdeskService;
use App\Services\Portal\PortalThemeController;
use App\Services\Portal\PortalThemeRepository;
use App\Services\Portal\PortalThemeService;
use App\Services\Portal\PortalUploadController;
use App\Services\Portal\PortalUploadRepository;
use App\Services\Portal\PortalUploadService;
use App\Services\Portal\PortalUploadStorage;
use App\Services\Portal\PortalWorkorderController;
use App\Services\Portal\PortalWorkorderService;
use App\Services\Tickets\TicketCategoryRepository;
use App\Services\Tickets\TicketEventRepository;
use App\Services\Tickets\TicketRepository;
use App\Services\Tickets\TicketRoutingRuleRepository;
use App\Services\Tickets\TicketRoutingService;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Auth\JwtService;
use App\Support\Auth\PasswordPolicy;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Customer portal endpoints (Phase 6.1 of docs/expansion-plan.md).
 *
 * Two groups:
 *   1. Admin/staff provisioning — Middleware::auth() + users.create gate.
 *   2. Portal-user facing — Middleware::portalAuth($portalAuthService),
 *      which enforces scope='portal' on the JWT and requires an active
 *      portal_accounts row. These deliberately do NOT mix with the staff
 *      auth stack so a leaked JWT from one context cannot access the other.
 */
return function (Router $router, RouteContext $ctx): void {
    $authConfig = $ctx->config['auth'] ?? [];
    $jwtConfig = $authConfig['jwt'] ?? [];
    $jwtService = new JwtService(
        $ctx->connection,
        $jwtConfig['secret'] ?? 'default-secret-key-change-in-production',
        $jwtConfig['ttl'] ?? 3600,
        $jwtConfig['refresh_ttl'] ?? 604800,
    );
    $passwordPolicy = new PasswordPolicy($authConfig);

    $accounts = new PortalAccountRepository($ctx->connection);
    $portalPermissions = new PortalPermissionService();
    $portalAuth = new PortalAuthService(
        $ctx->connection,
        $accounts,
        new CompanyRepository($ctx->connection),
        new SiteRepository($ctx->connection),
        $jwtService,
        $passwordPolicy,
        $ctx->gate,
        $ctx->auditLogger,
        $authConfig,
        $portalPermissions,
    );
    $controller = new PortalController($portalAuth, $accounts, $portalPermissions);

    // Wizard dependencies (Phase 6.2). Re-use the ticket infrastructure
    // from Phase 3.1/3.3 so portal-submitted tickets flow through the
    // same routing engine as staff-created ones.
    $siteAssetRepo = new SiteAssetRepository($ctx->connection);
    $ticketCategoryRepo = new TicketCategoryRepository($ctx->connection);
    $ticketRepo = new TicketRepository($ctx->connection);
    $ticketEventRepo = new TicketEventRepository($ctx->connection);
    $routingRuleRepo = new TicketRoutingRuleRepository($ctx->connection);
    $routingService = new TicketRoutingService($routingRuleRepo, $siteAssetRepo);
    $wizardService = new PortalRequestWizardService(
        $ticketCategoryRepo,
        $ticketRepo,
        $ticketEventRepo,
        $routingService,
        $portalAuth,
        new SiteRepository($ctx->connection),
        $siteAssetRepo,
        $ctx->auditLogger,
        new ItHelpdeskService(),
        $portalPermissions,
    );
    $wizardController = new PortalRequestController($wizardService);

    // Phase 6.3 — approval center. Reuses Phase 2.2 estimate infra and
    // Phase 4.1/4.3 contract infra so staff and portal act on the same
    // rows — only the authn/scope path differs.
    $approvalService = new PortalApprovalService(
        new EstimateRepository($ctx->connection, $ctx->auditLogger),
        new CustomerRepository($ctx->connection),
        new ContractRepository($ctx->connection),
        new ContractAmendmentRepository($ctx->connection),
        $ctx->auditLogger,
        $portalPermissions,
    );
    $approvalController = new PortalApprovalController($approvalService);

    // Phase 6.4 — site asset view + invoices + payments + saved methods.
    $assetViewService = new PortalAssetViewService(
        new SiteRepository($ctx->connection),
        $siteAssetRepo,
        $portalAuth,
    );
    $assetController = new PortalAssetController($assetViewService);

    $paymentConfig = require __DIR__ . '/../../config/payments.php';
    $portalGatewayFactory = new PaymentGatewayFactory($paymentConfig);
    $portalCoreReturns = new CoreReturnService($ctx->connection, $ctx->auditLogger);
    $billingService = new PortalBillingService(
        new CustomerRepository($ctx->connection),
        new InvoiceService($ctx->connection, $portalCoreReturns, $ctx->auditLogger),
        new PaymentProcessingService($ctx->connection, $portalGatewayFactory, $ctx->auditLogger),
        new InvoicePublicPaymentTokenService($ctx->connection),
        new PortalPaymentMethodRepository($ctx->connection),
        $ctx->auditLogger,
        $portalPermissions,
    );
    $billingController = new PortalBillingController($billingService);

    // Phase 6.5 — messaging tied to tickets/WOs (reuses MessageThread).
    $portalWorkorderRepo = new WorkorderRepository($ctx->connection, $ctx->auditLogger);
    $portalCustomerRepo = new CustomerRepository($ctx->connection);
    $messagingService = new PortalMessagingService(
        $ctx->connection,
        $ticketRepo,
        $portalWorkorderRepo,
        $portalCustomerRepo,
        $portalAuth,
        $ctx->auditLogger,
        $portalPermissions,
    );
    $messagingController = new PortalMessagingController($messagingService);

    // Phase 6.6 — document/photo upload (polymorphic across ticket/WO/thread).
    $uploadService = new PortalUploadService(
        $ctx->connection,
        new PortalUploadRepository($ctx->connection),
        new PortalUploadStorage(),
        $ticketRepo,
        $portalWorkorderRepo,
        $portalCustomerRepo,
        $portalAuth,
        $ctx->auditLogger,
        $portalPermissions,
    );
    $uploadController = new PortalUploadController($uploadService);

    // Phase 6.7 — ETA tracking (portal-read surface; staff-write lives in
    // routes/modules/eta.php).
    $etaService = new PortalEtaPromiseService(
        $ctx->connection,
        new PortalEtaPromiseRepository($ctx->connection),
        $ticketRepo,
        $portalWorkorderRepo,
        $portalCustomerRepo,
        $portalAuth,
        $ctx->gate,
        $ctx->auditLogger,
    );
    $etaController = new PortalEtaPromiseController($etaService);

    // Phase 6.8 — white-label theming (admin CRUD, portal self-read,
    // and pre-auth host resolution for the login page).
    $themeService = new PortalThemeService(
        $ctx->connection,
        new PortalThemeRepository($ctx->connection),
        new CompanyRepository($ctx->connection),
        $portalAuth,
        $ctx->gate,
        $ctx->auditLogger,
    );
    $themeController = new PortalThemeController($themeService);

    // Phase 13 (#123) — portal-facing lease/acquisition/decommission view.
    // Reuses the staff acquisition service for approve/reject so the
    // matrix + audit rules stay single-sourced; lease decisions go
    // through the repo helper. Decommissions are read-only on the portal.
    $lifecycleAcqRepo = new AssetAcquisitionRepository($ctx->connection);
    $lifecycleAcqService = new AssetAcquisitionService(
        $lifecycleAcqRepo,
        $ctx->gate,
        $ctx->auditLogger,
    );
    $lifecycleService = new PortalLifecycleService(
        new AssetLeaseRepository($ctx->connection),
        $lifecycleAcqRepo,
        $lifecycleAcqService,
        new AssetDecommissionRepository($ctx->connection),
        $portalCustomerRepo,
        $ctx->auditLogger,
        $portalPermissions,
    );
    $lifecycleController = new PortalLifecycleController($lifecycleService);

    // Phase 2c — read-only contracts surface (the approval center handles
    // pending sign-off; this service exposes the full contract roll for
    // history/SLA visibility).
    $contractService = new PortalContractService(new ContractRepository($ctx->connection));
    $contractController = new PortalContractController($contractService);

    // Portal-scoped contract e-signing. Authorizes by portal_account
    // ownership (company_id) + SIGN_CONTRACTS permission instead of by
    // a public link token; reuses the same signature audit + status
    // transitions as the staff/public paths.
    $contractSigningService = new PortalContractSigningService(
        new ContractRepository($ctx->connection),
        new ContractSignatureRepository($ctx->connection),
        $portalPermissions,
        $ctx->auditLogger,
    );
    $contractSigningController = new PortalContractSigningController($contractSigningService);

    // Phase 2c — read-only workorders surface (jobs + status history,
    // scoped via customers.company_id like invoices).
    $workorderService = new PortalWorkorderService($portalWorkorderRepo, $portalCustomerRepo);
    $workorderController = new PortalWorkorderController($workorderService);

    // Phase 2e (Decision D) — portal-side OIDC. Reuses the staff repos
    // (provider/link/attempt) but resolves to portal_accounts and issues
    // a portal-scoped JWT. Provider scoping (company_id NULL = global,
    // set = per-company) is enforced by SsoProviderRepository helpers.
    $portalSsoService = new PortalSsoService(
        $ctx->connection,
        new SsoProviderRepository($ctx->connection),
        new SsoUserLinkRepository($ctx->connection),
        new SsoLoginAttemptRepository($ctx->connection),
        $accounts,
        new CompanyRepository($ctx->connection),
        new OidcHttpClient(),
        $ctx->auditLogger,
    );
    $portalSsoController = new PortalSsoController(
        $portalSsoService,
        $jwtService,
        $portalPermissions,
    );

    // Phase 2f — CSAT, notification preferences, audit trail, API tokens.
    // Each surface is a thin service+controller pair; all share the same
    // portalAuth gate. The API token service is also handed to the
    // portalAuth middleware so `pat_*` bearers route through it instead
    // of JWT validation (see Middleware::portalAuth signature).
    $csatRepo = new PortalCsatRepository($ctx->connection);
    $csatService = new PortalCsatService(
        $csatRepo,
        $portalWorkorderRepo,
        $portalCustomerRepo,
        $ctx->auditLogger,
    );
    $csatController = new PortalCsatController($csatService);

    $notifRepo = new PortalNotificationPreferenceRepository($ctx->connection);
    $notifService = new PortalNotificationPreferenceService($notifRepo, $ctx->auditLogger);
    $notifController = new PortalNotificationPreferenceController($notifService);

    $auditService = new PortalAuditService($ctx->connection, $portalCustomerRepo);
    $auditController = new PortalAuditController($auditService);

    $apiTokenRepo = new PortalApiTokenRepository($ctx->connection);
    $apiTokenService = new PortalApiTokenService($apiTokenRepo, $ctx->auditLogger);
    $apiTokenController = new PortalApiTokenController($apiTokenService);

    // --- Public login endpoint (no auth, strict throttle) ---
    $router->group(
        [Middleware::throttleStrict(10, 60)],
        function (Router $router) use ($controller, $themeController, $portalSsoController) {
            $router->post('/api/portal/auth/login', function (Request $request) use ($controller) {
                return Response::json($controller->login(
                    $request->body(),
                    $request->getClientIp(),
                    $request->header('User-Agent') ?? $request->header('HTTP_USER_AGENT'),
                ));
            });

            // Phase 6.8 — public theme lookup keyed on Host header so the
            // login page can render branded before any JWT exists. Always
            // returns a payload (default if no match), so callers don't
            // have to branch on 404.
            $router->get('/api/portal/theme', function (Request $request) use ($themeController) {
                return Response::json($themeController->publicResolveByHost(
                    $request->header('Host'),
                ));
            });

            // Phase 2e — portal SSO. List, start, callback. Throttled like
            // password login (same anon surface, same brute-force concern).
            $router->get(
                '/api/portal/auth/sso/providers',
                function (Request $request) use ($portalSsoController) {
                    return Response::json($portalSsoController->listProviders($request->query()));
                }
            );

            $router->post(
                '/api/portal/auth/sso/{slug}/start',
                function (Request $request) use ($portalSsoController) {
                    return Response::json($portalSsoController->start(
                        (string) $request->getAttribute('slug'),
                        $request->body(),
                    ));
                }
            );

            $router->get(
                '/api/portal/auth/sso/callback',
                function (Request $request) use ($portalSsoController) {
                    return Response::json($portalSsoController->callback($request->query()));
                }
            );
        }
    );

    // --- Public CSAT response by token (Phase 2f) ---
    // No auth, strict throttle: the token in the URL is the bearer of
    // record. Lives outside the portalAuth group so emailed survey
    // links work without forcing recipients to log in.
    $router->group(
        [Middleware::throttleStrict(20, 60)],
        function (Router $router) use ($csatController) {
            $router->post(
                '/api/portal/csat/public/{token}',
                function (Request $request) use ($csatController) {
                    return Response::json($csatController->submitPublic(
                        (string) $request->getAttribute('token'),
                        $request->body(),
                    ));
                }
            );
        }
    );

    // --- Portal-scoped routes (portal_user + portal_account required) ---
    // Phase 2a — portalTenantGate is layered AFTER portalAuth so a portal
    // JWT minted for tenant A cannot be used to read tenant B's surfaces
    // when served from tenant B's white-label host.
    // Phase 2f — apiTokenService is passed to portalAuth so `pat_*`
    // bearers route through the personal-access-token validator instead
    // of the JWT path.
    $router->group([
        Middleware::portalAuth($portalAuth, $apiTokenService),
        Middleware::portalTenantGate($themeService),
    ], function (Router $router) use (
        $controller, $wizardController, $approvalController, $assetController,
        $billingController, $messagingController, $uploadController, $uploadService,
        $etaController, $themeController, $lifecycleController,
        $contractController, $workorderController, $contractSigningController,
        $csatController, $notifController, $auditController, $apiTokenController,
    ) {
        $router->get('/api/portal/auth/me', function (Request $request) use ($controller) {
            $account = $request->getAttribute('portal_account');
            return Response::json($controller->me(
                $request->getAttribute('user'),
                $account->id,
            ));
        });

        // Phase 6.2 — request wizard
        $router->get('/api/portal/request-types', function (Request $request) use ($wizardController) {
            return Response::json($wizardController->listRequestTypes(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
            ));
        });

        $router->get('/api/portal/request-types/{id}/subcategories', function (Request $request) use ($wizardController) {
            return Response::json($wizardController->listCategoriesForType(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/portal/requests', function (Request $request) use ($wizardController) {
            return Response::created($wizardController->submit(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->body(),
            ));
        });

        // Phase 6.3 — approval center
        $router->get('/api/portal/approvals', function (Request $request) use ($approvalController) {
            return Response::json($approvalController->listPending(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
            ));
        });

        $router->post('/api/portal/approvals/estimates/{id}/approve', function (Request $request) use ($approvalController) {
            return Response::json($approvalController->approveEstimate(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->post('/api/portal/approvals/estimates/{id}/reject', function (Request $request) use ($approvalController) {
            return Response::json($approvalController->rejectEstimate(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->post('/api/portal/approvals/contracts/{id}/approve', function (Request $request) use ($approvalController) {
            return Response::json($approvalController->approveContract(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
                $request->getClientIp(),
                $request->header('User-Agent') ?? $request->header('HTTP_USER_AGENT'),
            ));
        });

        $router->post('/api/portal/approvals/contracts/{id}/reject', function (Request $request) use ($approvalController) {
            return Response::json($approvalController->rejectContract(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        // Phase 6.4 — site + asset view (read-only)
        $router->get('/api/portal/sites', function (Request $request) use ($assetController) {
            return Response::json($assetController->listSites(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
            ));
        });

        $router->get('/api/portal/sites/{id}', function (Request $request) use ($assetController) {
            return Response::json($assetController->getSite(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->get('/api/portal/sites/{id}/assets', function (Request $request) use ($assetController) {
            return Response::json($assetController->listAssetsAtSite(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->query(),
            ));
        });

        $router->get('/api/portal/assets/{id}', function (Request $request) use ($assetController) {
            return Response::json($assetController->getAsset(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        // Phase 6.4 — invoices + checkout
        $router->get('/api/portal/invoices', function (Request $request) use ($billingController) {
            return Response::json($billingController->listInvoices(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->query(),
            ));
        });

        $router->get('/api/portal/invoices/{id}', function (Request $request) use ($billingController) {
            return Response::json($billingController->getInvoice(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/portal/invoices/{id}/checkout', function (Request $request) use ($billingController) {
            return Response::json($billingController->startCheckout(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        // Phase 6.4 — saved payment methods
        $router->get('/api/portal/payment-methods', function (Request $request) use ($billingController) {
            return Response::json($billingController->listPaymentMethods(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
            ));
        });

        $router->post('/api/portal/payment-methods', function (Request $request) use ($billingController) {
            return Response::created($billingController->savePaymentMethod(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->body(),
            ));
        });

        $router->post('/api/portal/payment-methods/{id}/default', function (Request $request) use ($billingController) {
            return Response::json($billingController->setDefaultMethod(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->delete('/api/portal/payment-methods/{id}', function (Request $request) use ($billingController) {
            $billingController->deletePaymentMethod(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            );
            return Response::noContent();
        });

        // Phase 6.5 — messaging
        $router->get('/api/portal/tickets/{id}/threads', function (Request $request) use ($messagingController) {
            return Response::json($messagingController->listThreadsForTicket(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/portal/tickets/{id}/threads', function (Request $request) use ($messagingController) {
            return Response::created($messagingController->startThreadForTicket(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->get('/api/portal/workorders/{id}/threads', function (Request $request) use ($messagingController) {
            return Response::json($messagingController->listThreadsForWorkorder(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/portal/workorders/{id}/threads', function (Request $request) use ($messagingController) {
            return Response::created($messagingController->startThreadForWorkorder(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->get('/api/portal/threads/{id}/messages', function (Request $request) use ($messagingController) {
            return Response::json($messagingController->listMessages(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/portal/threads/{id}/messages', function (Request $request) use ($messagingController) {
            return Response::created($messagingController->postMessage(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->post('/api/portal/threads/{id}/read', function (Request $request) use ($messagingController) {
            $messagingController->markRead(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            );
            return Response::noContent();
        });

        // Phase 6.6 — document/photo upload
        $router->post('/api/portal/tickets/{id}/uploads', function (Request $request) use ($uploadController) {
            return Response::created($uploadController->uploadForTicket(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->file('file'),
            ));
        });

        $router->post('/api/portal/workorders/{id}/uploads', function (Request $request) use ($uploadController) {
            return Response::created($uploadController->uploadForWorkorder(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->file('file'),
            ));
        });

        $router->post('/api/portal/threads/{id}/uploads', function (Request $request) use ($uploadController) {
            return Response::created($uploadController->uploadForThread(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->file('file'),
            ));
        });

        $router->get('/api/portal/tickets/{id}/uploads', function (Request $request) use ($uploadController) {
            return Response::json($uploadController->listForTicket(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->get('/api/portal/workorders/{id}/uploads', function (Request $request) use ($uploadController) {
            return Response::json($uploadController->listForWorkorder(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->get('/api/portal/threads/{id}/uploads', function (Request $request) use ($uploadController) {
            return Response::json($uploadController->listForThread(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        // Download re-runs scope on every hit. Stream-ish via file_get_contents:
        // we've already capped upload size at 10MB so reading into memory is
        // acceptable and keeps us inside the existing Response abstraction.
        $router->get('/api/portal/uploads/{id}', function (Request $request) use ($uploadService) {
            $upload = $uploadService->getUploadForDownload(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            );
            $storage = new PortalUploadStorage();
            $abs = $storage->absPathFor($upload->stored_path);
            if ($abs === null || !is_file($abs) || !is_readable($abs)) {
                return Response::notFound('upload file is no longer available');
            }
            $body = file_get_contents($abs);
            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $upload->original_name) ?? 'download';
            return Response::make(
                $body !== false ? $body : '',
                200,
                [
                    'Content-Type' => $upload->mime_type,
                    'Content-Length' => (string) $upload->size_bytes,
                    'Content-Disposition' => 'attachment; filename="' . $safeName . '"',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store',
                ],
            );
        });

        $router->delete('/api/portal/uploads/{id}', function (Request $request) use ($uploadController) {
            $uploadController->deleteUpload(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            );
            return Response::noContent();
        });

        // Phase 6.7 — ETA tracking (portal-read only; staff publish/cancel
        // lives under /api/eta in routes/modules/eta.php).
        $router->get('/api/portal/tickets/{id}/eta', function (Request $request) use ($etaController) {
            return Response::json($etaController->readForTicketPortal(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->get('/api/portal/workorders/{id}/eta', function (Request $request) use ($etaController) {
            return Response::json($etaController->readForWorkorderPortal(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        // Phase 6.8 — portal user's own company theme (falls back to
        // platform default if none configured).
        $router->get('/api/portal/theme/me', function (Request $request) use ($themeController) {
            return Response::json($themeController->readForPortal(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
            ));
        });

        // Phase 13 (#123) — leases (read + end-of-lease decision)
        $router->get('/api/portal/leases', function (Request $request) use ($lifecycleController) {
            return Response::json($lifecycleController->listLeases(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->query(),
            ));
        });

        $router->get('/api/portal/leases/{id}', function (Request $request) use ($lifecycleController) {
            return Response::json($lifecycleController->getLease(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/portal/leases/{id}/decision', function (Request $request) use ($lifecycleController) {
            return Response::json($lifecycleController->recordLeaseDecision(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        // Phase 13 (#123) — acquisitions (read + customer approve/reject)
        $router->get('/api/portal/acquisitions', function (Request $request) use ($lifecycleController) {
            return Response::json($lifecycleController->listAcquisitions(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->query(),
            ));
        });

        $router->get('/api/portal/acquisitions/{id}', function (Request $request) use ($lifecycleController) {
            return Response::json($lifecycleController->getAcquisition(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->post('/api/portal/acquisitions/{id}/approve', function (Request $request) use ($lifecycleController) {
            return Response::json($lifecycleController->approveAcquisition(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->post('/api/portal/acquisitions/{id}/reject', function (Request $request) use ($lifecycleController) {
            return Response::json($lifecycleController->rejectAcquisition(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        // Phase 13 (#123) — decommissions (read-only on the portal)
        $router->get('/api/portal/decommissions', function (Request $request) use ($lifecycleController) {
            return Response::json($lifecycleController->listDecommissions(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->query(),
            ));
        });

        $router->get('/api/portal/decommissions/{id}', function (Request $request) use ($lifecycleController) {
            return Response::json($lifecycleController->getDecommission(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        // Phase 2c — contracts (read-only)
        $router->get('/api/portal/contracts', function (Request $request) use ($contractController) {
            return Response::json($contractController->listForPortal(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->query(),
            ));
        });

        $router->get('/api/portal/contracts/{id}', function (Request $request) use ($contractController) {
            return Response::json($contractController->getForPortal(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        // Portal-scoped contract e-sign. Authorization is by portal_account
        // ownership (company_id) + SIGN_CONTRACTS — no public-link
        // round-trip needed for in-portal signers.
        $router->get(
            '/api/portal/contracts/{id}/signatures',
            function (Request $request) use ($contractSigningController) {
                return Response::json($contractSigningController->listSignatures(
                    $request->getAttribute('user'),
                    $request->getAttribute('portal_account'),
                    (int) $request->getAttribute('id'),
                ));
            }
        );

        $router->post(
            '/api/portal/contracts/{id}/sign',
            function (Request $request) use ($contractSigningController) {
                return Response::created($contractSigningController->sign(
                    $request->getAttribute('user'),
                    $request->getAttribute('portal_account'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                    $request->getClientIp(),
                    $request->header('User-Agent'),
                ));
            }
        );

        // Phase 2c — workorders (read-only with jobs + status history)
        $router->get('/api/portal/workorders', function (Request $request) use ($workorderController) {
            return Response::json($workorderController->listForPortal(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->query(),
            ));
        });

        $router->get('/api/portal/workorders/{id}', function (Request $request) use ($workorderController) {
            return Response::json($workorderController->getForPortal(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            ));
        });

        // Phase 2c — standalone messages inbox (cross-thread aggregator)
        $router->get('/api/portal/messages', function (Request $request) use ($messagingController) {
            return Response::json($messagingController->inbox(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->query(),
            ));
        });

        // Phase 2f — CSAT (authenticated paths; public token-link path
        // lives in the unauthenticated group above).
        $router->get('/api/portal/csat/pending', function (Request $request) use ($csatController) {
            return Response::json($csatController->listPending(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
            ));
        });

        $router->get('/api/portal/csat/history', function (Request $request) use ($csatController) {
            return Response::json($csatController->listHistory(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
            ));
        });

        $router->post('/api/portal/csat/workorders/{id}', function (Request $request) use ($csatController) {
            return Response::json($csatController->submit(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        // Phase 2f — notification preferences (matrix read + cell upsert)
        $router->get('/api/portal/notification-preferences', function (Request $request) use ($notifController) {
            return Response::json($notifController->listMatrix(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
            ));
        });

        $router->put('/api/portal/notification-preferences', function (Request $request) use ($notifController) {
            return Response::json($notifController->set(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->body(),
            ));
        });

        // Phase 2f — read-only audit timeline scoped to entities the
        // portal account owns (work orders, invoices, contracts, etc.)
        $router->get('/api/portal/audit-trail', function (Request $request) use ($auditController) {
            return Response::json($auditController->listForAccount(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->query(),
            ));
        });

        // Phase 2f — self-issued personal access tokens. Plaintext is
        // returned exactly once at issue time; thereafter only the
        // prefix is observable.
        $router->get('/api/portal/api-tokens', function (Request $request) use ($apiTokenController) {
            return Response::json($apiTokenController->listForAccount(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
            ));
        });

        $router->post('/api/portal/api-tokens', function (Request $request) use ($apiTokenController) {
            return Response::created($apiTokenController->issue(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                $request->body(),
            ));
        });

        $router->delete('/api/portal/api-tokens/{id}', function (Request $request) use ($apiTokenController) {
            $apiTokenController->revoke(
                $request->getAttribute('user'),
                $request->getAttribute('portal_account'),
                (int) $request->getAttribute('id'),
            );
            return Response::noContent();
        });
    });

    // --- Admin provisioning (staff auth + users.create gate) ---
    $router->group([Middleware::auth()], function (Router $router) use ($controller, $themeController) {
        $router->post('/api/portal-accounts', function (Request $request) use ($controller) {
            return Response::created($controller->provision(
                $request->getAttribute('user'),
                $request->body(),
            ));
        });

        $router->get('/api/portal-accounts', function (Request $request) use ($controller) {
            $companyId = (int) $request->queryParam('company_id');
            if ($companyId <= 0) {
                return Response::badRequest('company_id query param is required');
            }
            $activeOnly = ((string) $request->queryParam('active_only')) === '1';
            return Response::json($controller->listForCompany(
                $request->getAttribute('user'),
                $companyId,
                $activeOnly,
            ));
        });

        $router->put('/api/portal-accounts/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateAccount(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->delete('/api/portal-accounts/{id}', function (Request $request) use ($controller) {
            $controller->revoke(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            );
            return Response::noContent();
        });

        // Phase 6.8 — admin-only CRUD for a company's portal theme.
        // Upsert is idempotent by company (UNIQUE company_id), so PUT
        // doubles as create+update. Gated by users.create inside the
        // service so updates to branding require the same authority as
        // provisioning portal accounts.
        $router->get('/api/companies/{id}/portal-theme', function (Request $request) use ($themeController) {
            return Response::json($themeController->getForCompany(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });

        $router->put('/api/companies/{id}/portal-theme', function (Request $request) use ($themeController) {
            return Response::json($themeController->upsertForCompany(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body(),
            ));
        });

        $router->delete('/api/companies/{id}/portal-theme', function (Request $request) use ($themeController) {
            return Response::json($themeController->deleteForCompany(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
            ));
        });
    });
};
