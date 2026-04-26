<?php

use App\Services\Crm\BillingContactRepository;
use App\Services\Crm\CompanyRepository;
use App\Services\Crm\CrmController;
use App\Services\Crm\CustomerLinkageService;
use App\Services\Crm\SiteBlackoutWindowRepository;
use App\Services\Crm\SiteContactRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Customer\CustomerRepository;
use App\Support\Crypto\FieldCipher;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * CRM — companies, sites, contacts (Phase 1.1 of docs/expansion-plan.md).
 *
 * Read endpoints require crm.{companies,sites,contacts}.view, writes require
 * the corresponding .manage permission. Mutations are audited.
 */
return function (Router $router, RouteContext $ctx): void {
    $companyRepo = new CompanyRepository($ctx->connection);
    $siteRepo = new SiteRepository($ctx->connection);
    $customerRepo = new CustomerRepository($ctx->connection);
    $linkage = new CustomerLinkageService(
        $ctx->connection,
        $customerRepo,
        $companyRepo,
        $siteRepo,
        $ctx->auditLogger
    );
    $controller = new CrmController(
        $companyRepo,
        $siteRepo,
        new SiteContactRepository($ctx->connection),
        new BillingContactRepository($ctx->connection),
        new SiteBlackoutWindowRepository($ctx->connection),
        new FieldCipher(),
        $linkage,
        $ctx->gate,
        $ctx->auditLogger
    );

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // Companies
        $router->get('/api/companies', function (Request $request) use ($controller) {
            return Response::json($controller->searchCompanies(
                $request->getAttribute('user'),
                [
                    'query' => $request->queryParam('query'),
                    'status' => $request->queryParam('status'),
                    'division_id' => $request->queryParam('division_id'),
                    'limit' => $request->queryParam('limit', 50),
                    'offset' => $request->queryParam('offset', 0),
                ]
            ));
        });

        $router->get('/api/companies/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getCompany(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/companies', function (Request $request) use ($controller) {
            return Response::created($controller->createCompany(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/companies/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateCompany(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/companies/{id}', function (Request $request) use ($controller) {
            $controller->deleteCompany(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Sites — listed under a company, addressed globally for {id}
        $router->get('/api/companies/{id}/sites', function (Request $request) use ($controller) {
            return Response::json($controller->listSites(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                (bool) $request->queryParam('include_inactive', false)
            ));
        });

        $router->post('/api/sites', function (Request $request) use ($controller) {
            return Response::created($controller->createSite(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->get('/api/sites/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getSite(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->put('/api/sites/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateSite(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/sites/{id}', function (Request $request) use ($controller) {
            $controller->deleteSite(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Site contacts
        $router->get('/api/sites/{id}/contacts', function (Request $request) use ($controller) {
            return Response::json($controller->listSiteContacts(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                (bool) $request->queryParam('include_inactive', false)
            ));
        });

        $router->post('/api/site-contacts', function (Request $request) use ($controller) {
            return Response::created($controller->createSiteContact(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/site-contacts/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateSiteContact(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/site-contacts/{id}', function (Request $request) use ($controller) {
            $controller->deleteSiteContact(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Billing contacts
        $router->get('/api/companies/{id}/billing-contacts', function (Request $request) use ($controller) {
            return Response::json($controller->listBillingContacts(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                (bool) $request->queryParam('include_inactive', false)
            ));
        });

        $router->post('/api/billing-contacts', function (Request $request) use ($controller) {
            return Response::created($controller->createBillingContact(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/billing-contacts/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateBillingContact(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/billing-contacts/{id}', function (Request $request) use ($controller) {
            $controller->deleteBillingContact(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Site codes (alarm/gate) — dedicated perm gate because they're sensitive.
        $router->get('/api/sites/{id}/codes', function (Request $request) use ($controller) {
            return Response::json($controller->revealSiteCodes(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        // Blackout windows
        $router->get('/api/sites/{id}/blackout-windows', function (Request $request) use ($controller) {
            return Response::json($controller->listBlackoutWindows(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                (bool) $request->queryParam('include_inactive', false)
            ));
        });

        $router->post('/api/site-blackout-windows', function (Request $request) use ($controller) {
            return Response::created($controller->createBlackoutWindow(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/site-blackout-windows/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateBlackoutWindow(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/site-blackout-windows/{id}', function (Request $request) use ($controller) {
            $controller->deleteBlackoutWindow(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // Customer → company shim (Phase 1.5 of docs/expansion-plan.md). Read
        // the current linkage, or promote a legacy customer to a full CRM
        // company + primary site record.
        $router->get('/api/customers/{id}/company-linkage', function (Request $request) use ($controller) {
            return Response::json($controller->getCustomerLinkage(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/customers/{id}/promote-to-company', function (Request $request) use ($controller) {
            return Response::json($controller->promoteCustomer(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });
    });
};
