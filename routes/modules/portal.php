<?php

use App\Services\Assets\AssetAcquisitionRepository;
use App\Services\Assets\AssetAcquisitionService;
use App\Services\Assets\AssetDecommissionRepository;
use App\Services\Assets\AssetLeaseRepository;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Contracts\ContractAmendmentRepository;
use App\Services\Contracts\ContractRepository;
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
use App\Services\Portal\PortalApprovalController;
use App\Services\Portal\PortalApprovalService;
use App\Services\Portal\PortalAssetController;
use App\Services\Portal\PortalAssetViewService;
use App\Services\Portal\PortalAuthService;
use App\Services\Portal\PortalBillingController;
use App\Services\Portal\PortalBillingService;
use App\Services\Portal\PortalContractController;
use App\Services\Portal\PortalContractService;
use App\Services\Portal\PortalController;
use App\Services\Portal\PortalEtaPromiseController;
use App\Services\Portal\PortalEtaPromiseRepository;
use App\Services\Portal\PortalEtaPromiseService;
use App\Services\Portal\PortalLifecycleController;
use App\Services\Portal\PortalLifecycleService;
use App\Services\Portal\PortalMessagingController;
use App\Services\Portal\PortalMessagingService;
use App\Services\Portal\PortalPaymentMethodRepository;
use App\Services\Portal\PortalRequestController;
use App\Services\Portal\PortalRequestWizardService;
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
    );
    $controller = new PortalController($portalAuth, $accounts);

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
    );
    $lifecycleController = new PortalLifecycleController($lifecycleService);

    // Phase 2c — read-only contracts surface (the approval center handles
    // pending sign-off; this service exposes the full contract roll for
    // history/SLA visibility).
    $contractService = new PortalContractService(new ContractRepository($ctx->connection));
    $contractController = new PortalContractController($contractService);

    // Phase 2c — read-only workorders surface (jobs + status history,
    // scoped via customers.company_id like invoices).
    $workorderService = new PortalWorkorderService($portalWorkorderRepo, $portalCustomerRepo);
    $workorderController = new PortalWorkorderController($workorderService);

    // --- Public login endpoint (no auth, strict throttle) ---
    $router->group([Middleware::throttleStrict(10, 60)], function (Router $router) use ($controller, $themeController) {
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
    });

    // --- Portal-scoped routes (portal_user + portal_account required) ---
    // Phase 2a — portalTenantGate is layered AFTER portalAuth so a portal
    // JWT minted for tenant A cannot be used to read tenant B's surfaces
    // when served from tenant B's white-label host.
    $router->group([
        Middleware::portalAuth($portalAuth),
        Middleware::portalTenantGate($themeService),
    ], function (Router $router) use (
        $controller, $wizardController, $approvalController, $assetController,
        $billingController, $messagingController, $uploadController, $uploadService,
        $etaController, $themeController, $lifecycleController,
        $contractController, $workorderController,
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
