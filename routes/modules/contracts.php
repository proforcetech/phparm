<?php

use App\Services\Contracts\ContractAmendmentRepository;
use App\Services\Contracts\ContractBillingService;
use App\Services\Contracts\ContractConsumptionRepository;
use App\Services\Contracts\ContractController;
use App\Services\Contracts\ContractEntitlementRepository;
use App\Services\Contracts\ContractPublicLinkRepository;
use App\Services\Contracts\ContractRenewalService;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSignatureRepository;
use App\Services\Contracts\ContractSignerRepository;
use App\Services\Contracts\ContractSignerService;
use App\Services\Contracts\ContractSiteRepository;
use App\Services\Contracts\ContractSigningService;
use App\Services\Contracts\ContractUtilizationService;
use App\Services\Crm\CompanyRepository;
use App\Services\Crm\SiteRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationLogRepository;
use App\Support\Notifications\TemplateEngine;

/**
 * Contracts endpoints (Phase 4.1 of docs/expansion-plan.md).
 *
 * Read perms: contracts.view
 * Write perms: contracts.manage
 */
return function (Router $router, RouteContext $ctx): void {
    $contractRepo = new ContractRepository($ctx->connection);
    $linkRepo = new ContractPublicLinkRepository($ctx->connection);
    $signatureRepo = new ContractSignatureRepository($ctx->connection);

    $controller = new ContractController(
        $contractRepo,
        new ContractSiteRepository($ctx->connection),
        new ContractEntitlementRepository($ctx->connection),
        new ContractAmendmentRepository($ctx->connection),
        new CompanyRepository($ctx->connection),
        new SiteRepository($ctx->connection),
        $ctx->gate,
        $ctx->auditLogger,
    );

    $signingService = new ContractSigningService(
        $contractRepo,
        $linkRepo,
        $signatureRepo,
        $ctx->gate,
        $ctx->auditLogger,
    );

    // R-02c — invitation roster. Wires through the signing service so
    // each invite issues a bound public link. The signer service is also
    // late-bound into the signing service so capture-time signature
    // events can stamp the matching signer row.
    $signerRepo = new ContractSignerRepository($ctx->connection);
    $notificationsConfig = require __DIR__ . '/../../config/notifications.php';
    $notificationDispatcher = new NotificationDispatcher(
        $notificationsConfig,
        new TemplateEngine(),
        new NotificationLogRepository($ctx->connection),
        $ctx->auditLogger,
    );
    $signerService = new ContractSignerService(
        $contractRepo,
        $signerRepo,
        $signingService,
        $ctx->gate,
        $ctx->auditLogger,
        $notificationDispatcher,
    );
    $signingService->setSignerService($signerService);

    $siteRepo = new ContractSiteRepository($ctx->connection);
    $entitlementRepo = new ContractEntitlementRepository($ctx->connection);
    $amendmentRepo = new ContractAmendmentRepository($ctx->connection);

    $renewalService = new ContractRenewalService(
        $contractRepo,
        $siteRepo,
        $entitlementRepo,
        $amendmentRepo,
        $ctx->auditLogger,
    );

    $utilizationService = new ContractUtilizationService(
        $contractRepo,
        $entitlementRepo,
        $ctx->gate,
    );

    $consumptionRepo = new ContractConsumptionRepository($ctx->connection);
    $billingService = new ContractBillingService(
        $contractRepo,
        $entitlementRepo,
        $consumptionRepo,
        $ctx->gate,
        $ctx->auditLogger,
    );

    $baseUrlResolver = static function (Request $request): string {
        $scheme = $request->header('X-Forwarded-Proto') ?? 'https';
        $host = $request->header('Host') ?? 'localhost';
        return $scheme . '://' . $host;
    };

    // Public signing endpoints (no auth, CSRF-exempt via /api/public/*).
    // Rate-limited per R-02a (AUD-064): per-IP and per-link buckets.
    $publicLinkLimiter = $ctx->publicLinkLimiter;
    $applyPublicLinkThrottle = static function (\App\Support\Http\Route $route) use ($publicLinkLimiter): \App\Support\Http\Route {
        if ($publicLinkLimiter !== null) {
            $route->middleware(Middleware::publicLinkThrottle($publicLinkLimiter));
        }
        return $route;
    };

    $applyPublicLinkThrottle($router->get('/api/public/contract/view', function (Request $request) use ($signingService) {
        $token = $request->queryParam('token');
        $shortCode = $request->queryParam('code');
        $result = $signingService->fetchPublicView(
            is_string($token) ? $token : null,
            is_string($shortCode) ? $shortCode : null
        );
        return Response::json([
            'data' => [
                'contract' => $result['contract']->toArray(),
                'link' => [
                    'short_code' => $result['link']->short_code,
                    'expires_at' => $result['link']->expires_at,
                    'signer_email' => $result['link']->signer_email,
                ],
            ],
        ]);
    }));

    $applyPublicLinkThrottle($router->get('/api/public/contract/by-code/{shortCode}', function (Request $request) use ($signingService) {
        $result = $signingService->fetchPublicView(
            null,
            (string) $request->getAttribute('shortCode')
        );
        return Response::json([
            'data' => [
                'contract' => $result['contract']->toArray(),
                'link' => [
                    'short_code' => $result['link']->short_code,
                    'expires_at' => $result['link']->expires_at,
                    'signer_email' => $result['link']->signer_email,
                ],
            ],
        ]);
    }));

    $applyPublicLinkThrottle($router->post('/api/public/contract/sign', function (Request $request) use ($signingService) {
        $body = $request->body();
        // R-03 / AUD-065 — state-changing endpoint requires the long token.
        // Short codes (~40 bits of entropy) are no longer accepted on
        // sign; they remain valid only on the read-only fetch + redirect
        // paths where they cannot mutate state.
        $token = $body['token'] ?? $request->queryParam('token');
        if (!is_string($token) || $token === '') {
            return Response::badRequest(['error' => 'Token is required']);
        }
        $signature = $signingService->captureSignature(
            $token,
            [
                'signer_name' => (string) ($body['signer_name'] ?? ''),
                'signer_email' => $body['signer_email'] ?? null,
                'signer_title' => $body['signer_title'] ?? null,
                'signature_data' => (string) ($body['signature_data'] ?? ''),
                'comment' => $body['comment'] ?? null,
                'device_fingerprint' => $body['device_fingerprint'] ?? null,
                'legal_consent' => (bool) ($body['legal_consent'] ?? false),
                'consent_text' => $body['consent_text'] ?? null,
                'accept_document_changes' => (bool) ($body['accept_document_changes'] ?? false),
            ],
            $request->getClientIp(),
            $request->header('User-Agent'),
            null
        );
        return Response::created(['data' => $signature->toArray()]);
    }));

    $router->group([Middleware::auth()], function (Router $router) use (
        $controller,
        $signingService,
        $renewalService,
        $utilizationService,
        $billingService,
        $baseUrlResolver
    ) {
        // Contracts.
        $router->get('/api/contracts', function (Request $request) use ($controller) {
            return Response::json($controller->listContracts(
                $request->getAttribute('user'),
                [
                    'company_id' => $request->queryParam('company_id'),
                    'division_id' => $request->queryParam('division_id'),
                    'status' => $request->queryParam('status'),
                    'contract_type' => $request->queryParam('contract_type'),
                    'active_on' => $request->queryParam('active_on'),
                    'query' => $request->queryParam('query'),
                    'limit' => $request->queryParam('limit', 100),
                    'offset' => $request->queryParam('offset', 0),
                ]
            ));
        });

        $router->get('/api/contracts/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getContract(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/contracts', function (Request $request) use ($controller) {
            return Response::created($controller->createContract(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/contracts/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateContract(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/contracts/{id}', function (Request $request) use ($controller) {
            $controller->deleteContract(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Sites scope.
        $router->get('/api/contracts/{id}/sites', function (Request $request) use ($controller) {
            return Response::json($controller->listContractSites(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/contracts/{id}/sites', function (Request $request) use ($controller) {
            return Response::created($controller->attachSite(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/contracts/{id}/sites/{site_id}', function (Request $request) use ($controller) {
            $controller->detachSite(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                (int) $request->getAttribute('site_id')
            );
            return Response::noContent();
        });

        // Entitlements.
        $router->get('/api/contracts/{id}/entitlements', function (Request $request) use ($controller) {
            return Response::json($controller->listEntitlements(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                (bool) $request->queryParam('active_only', true)
            ));
        });

        $router->post('/api/contracts/{id}/entitlements', function (Request $request) use ($controller) {
            return Response::created($controller->createEntitlement(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->put(
            '/api/contracts/{id}/entitlements/{entitlement_id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->updateEntitlement(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    (int) $request->getAttribute('entitlement_id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/contracts/{id}/entitlements/{entitlement_id}',
            function (Request $request) use ($controller) {
                $controller->deleteEntitlement(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    (int) $request->getAttribute('entitlement_id')
                );
                return Response::noContent();
            }
        );

        // Amendments (append-only log).
        $router->get('/api/contracts/{id}/amendments', function (Request $request) use ($controller) {
            return Response::json($controller->listAmendments(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/contracts/{id}/amendments', function (Request $request) use ($controller) {
            return Response::created($controller->createAmendment(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        // Signing links + signature audit trail (Phase 4.2).
        $router->get(
            '/api/contracts/{id}/signatures',
            function (Request $request) use ($signingService) {
                $rows = $signingService->listSignatures(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                );
                return Response::json([
                    'data' => array_map(static fn($s) => $s->toArray(), $rows),
                ]);
            }
        );

        $router->post(
            '/api/contracts/{id}/signatures',
            function (Request $request) use ($signingService) {
                $signature = $signingService->captureInternalSignature(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                    $request->getClientIp(),
                    $request->header('User-Agent')
                );
                return Response::created(['data' => $signature->toArray()]);
            }
        );

        $router->get(
            '/api/contracts/{id}/links',
            function (Request $request) use ($signingService) {
                $rows = $signingService->listLinks(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                );
                return Response::json([
                    'data' => array_map(static fn($l) => $l->toArray(), $rows),
                ]);
            }
        );

        $router->post(
            '/api/contracts/{id}/links',
            function (Request $request) use ($signingService, $baseUrlResolver) {
                $body = $request->body();
                $signerEmail = isset($body['signer_email']) && is_string($body['signer_email'])
                    ? $body['signer_email']
                    : null;
                $signerInvitationId = isset($body['signer_invitation_id']) && is_numeric($body['signer_invitation_id'])
                    ? (int) $body['signer_invitation_id']
                    : null;
                $result = $signingService->issueLink(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $baseUrlResolver($request),
                    $body['expires_at'] ?? null,
                    $signerEmail,
                    $signerInvitationId
                );
                return Response::created([
                    'data' => [
                        'link' => $result['link']->toArray(),
                        'token' => $result['token'],
                        'short_url' => $result['short_url'],
                        'secure_url' => $result['secure_url'],
                    ],
                ]);
            }
        );

        $router->delete(
            '/api/contracts/{id}/links/{link_id}',
            function (Request $request) use ($signingService) {
                $signingService->revokeLink(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    (int) $request->getAttribute('link_id')
                );
                return Response::noContent();
            }
        );

        // R-02c — first-class invitation roster. Each invite creates a
        // contract_signers row and issues a bound public link in one
        // transactional unit.
        $router->get(
            '/api/contracts/{id}/signers',
            function (Request $request) use ($signerService) {
                $includeRevokedParam = $request->queryParam('include_revoked');
                $includeRevoked = $includeRevokedParam === null
                    || $includeRevokedParam === '1'
                    || $includeRevokedParam === 'true';
                $signers = $signerService->listForContract(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $includeRevoked
                );
                return Response::json([
                    'data' => array_map(static fn($s) => $s->toArray(), $signers),
                ]);
            }
        );

        $router->post(
            '/api/contracts/{id}/signers',
            function (Request $request) use ($signerService, $baseUrlResolver) {
                $result = $signerService->invite(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $baseUrlResolver($request),
                    $request->body() ?? []
                );
                return Response::created([
                    'data' => [
                        'signer' => $result['signer']->toArray(),
                        'link' => $result['link']->toArray(),
                        'token' => $result['token'],
                        'short_url' => $result['short_url'],
                        'secure_url' => $result['secure_url'],
                        'email_sent' => $result['email_sent'],
                        'email_error' => $result['email_error'],
                    ],
                ]);
            }
        );

        $router->delete(
            '/api/contracts/{id}/signers/{signer_id}',
            function (Request $request) use ($signerService) {
                $signerService->revoke(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    (int) $request->getAttribute('signer_id')
                );
                return Response::noContent();
            }
        );

        // Utilization reporting + renewals (Phase 4.3).
        $router->get(
            '/api/contracts/{id}/utilization',
            function (Request $request) use ($utilizationService) {
                $report = $utilizationService->utilizationForContract(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                );
                return Response::json(['data' => $report]);
            }
        );

        $router->get(
            '/api/companies/{company_id}/contracts-utilization',
            function (Request $request) use ($utilizationService) {
                $onDate = $request->queryParam('on_date');
                return Response::json($utilizationService->companyRollup(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('company_id'),
                    is_string($onDate) ? $onDate : null
                ));
            }
        );

        $router->get(
            '/api/contracts/renewals/due',
            function (Request $request) use ($renewalService) {
                $today = $request->queryParam('today');
                return Response::json([
                    'data' => $renewalService->listRenewalsDue(
                        is_string($today) ? $today : null
                    ),
                ]);
            }
        );

        $router->post(
            '/api/contracts/{id}/renew',
            function (Request $request) use ($renewalService) {
                $user = $request->getAttribute('user');
                if (!$user->can('contracts.manage')) {
                    return Response::forbidden();
                }
                $body = $request->body();
                $term = isset($body['renewal_term_months'])
                    ? (int) $body['renewal_term_months']
                    : null;
                $new = $renewalService->renewManually(
                    (int) $request->getAttribute('id'),
                    $term
                );
                return Response::created(['data' => $new->toArray()]);
            }
        );

        // Contract-driven billing (Phase 4.4).
        $router->get(
            '/api/contracts/{id}/consumption-ledger',
            function (Request $request) use ($billingService) {
                $limit = (int) ($request->queryParam('limit', 500));
                $rows = $billingService->listLedger(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $limit > 0 ? $limit : 500
                );
                return Response::json([
                    'data' => array_map(static fn($r) => $r->toArray(), $rows),
                ]);
            }
        );

        $router->post(
            '/api/contracts/{id}/consumption/preview',
            function (Request $request) use ($billingService) {
                $user = $request->getAttribute('user');
                if (!$user->can('contracts.view')) {
                    return Response::forbidden();
                }
                $body = $request->body();
                $coverage = $billingService->resolveCoverage(
                    (int) ($body['company_id'] ?? 0),
                    isset($body['site_id']) ? (int) $body['site_id'] : null,
                    (string) ($body['entitlement_kind'] ?? ''),
                    (float) ($body['amount'] ?? 0),
                    $body['on_date'] ?? null
                );
                $lines = [];
                if (isset($body['standard_rate_cents'])) {
                    $lines = $billingService->buildInvoiceLineSuggestions(
                        $coverage,
                        (int) $body['standard_rate_cents'],
                        (string) ($body['line_type'] ?? 'labor')
                    );
                }
                return Response::json([
                    'data' => [
                        'coverage' => $coverage,
                        'invoice_lines' => $lines,
                    ],
                ]);
            }
        );

        $router->post(
            '/api/contracts/{id}/consumption/manual-adjustment',
            function (Request $request) use ($billingService) {
                $body = $request->body();
                $row = $billingService->recordManualAdjustment(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    (string) ($body['entitlement_kind'] ?? ''),
                    (float) ($body['amount'] ?? 0),
                    (string) ($body['notes'] ?? ''),
                    isset($body['entitlement_id']) ? (int) $body['entitlement_id'] : null
                );
                return Response::created(['data' => $row->toArray()]);
            }
        );
    });
};
