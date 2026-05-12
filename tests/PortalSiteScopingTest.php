<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PortalAccount;
use App\Models\User;
use App\Models\Workorder;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Customer\CustomerRepository;
use App\Services\Invoice\InvoicePublicPaymentTokenService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\PaymentProcessingService;
use App\Services\Portal\PortalBillingService;
use App\Services\Portal\PortalContractService;
use App\Services\Portal\PortalPaymentMethodRepository;
use App\Services\Portal\PortalWorkorderService;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;

/**
 * R-05 / AUD-067 — strict portal site-scoping regression tests.
 *
 * Strict policy: a portal account narrowed to allowed_site_ids = [N] sees
 * only rows that resolve to one of those site ids. Rows with no resolvable
 * site (invoices/workorders with site_asset_id = NULL, contracts with no
 * contract_sites linking rows) are excluded — not silently passed through.
 *
 * Cross-site getInvoice/getWorkorder/getContract must surface the same
 * UnauthorizedException("...belongs to a different company") message used
 * for the company-mismatch branch so we don't leak whether the row exists.
 *
 * Counterpoint: an unscoped account (allowed_site_ids = NULL) sees every
 * company-scoped row including legacy NULL-site rows.
 */

// ---------------------------------------------------------------------------
// Shared fakes
// ---------------------------------------------------------------------------

class PssAudit extends AuditLogger
{
    public array $entries = [];
    public function __construct() {}
    public function log(AuditEntry $entry): void { $this->entries[] = $entry; }
}

class PssCustomers extends CustomerRepository
{
    /** @var array<int, Customer> */
    public array $store = [];
    public function __construct() {}
    public function find(int $id): ?Customer { return $this->store[$id] ?? null; }
    public function listIdsForCompany(int $companyId): array
    {
        $ids = [];
        foreach ($this->store as $c) {
            if ((int) ($c->company_id ?? 0) === $companyId) {
                $ids[] = $c->id;
            }
        }
        sort($ids);
        return $ids;
    }
    public function seed(int $id, int $companyId): Customer
    {
        $c = new Customer();
        $c->id = $id;
        $c->company_id = $companyId;
        $this->store[$id] = $c;
        return $c;
    }
}

class PssInvoices extends InvoiceService
{
    /** @var array<int, Invoice> */
    public array $store = [];
    public function __construct() {}
    public function findById(int $id): ?Invoice { return $this->store[$id] ?? null; }
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $out = [];
        $cid = (int) ($filters['customer_id'] ?? 0);
        $status = $filters['status'] ?? null;
        foreach ($this->store as $i) {
            if ($cid !== 0 && $i->customer_id !== $cid) continue;
            if ($status !== null && $i->status !== $status) continue;
            $out[] = $i;
        }
        return $out;
    }
    public function seed(int $id, int $customerId, ?int $siteAssetId, string $status = 'sent'): Invoice
    {
        $i = new Invoice();
        $i->id = $id;
        $i->number = 'INV-' . $id;
        $i->customer_id = $customerId;
        $i->site_asset_id = $siteAssetId;
        $i->status = $status;
        $i->issue_date = '2026-04-' . str_pad((string) ($id % 28 + 1), 2, '0', STR_PAD_LEFT);
        $i->total = 100.0;
        $i->balance_due = 100.0;
        $this->store[$id] = $i;
        return $i;
    }
}

class PssWorkorders extends WorkorderRepository
{
    /** @var array<int, Workorder> */
    public array $store = [];
    public function __construct() {}
    public function find(int $id): ?Workorder { return $this->store[$id] ?? null; }
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $out = [];
        $cid = (int) ($filters['customer_id'] ?? 0);
        $status = $filters['status'] ?? null;
        foreach ($this->store as $w) {
            if ($cid !== 0 && $w->customer_id !== $cid) continue;
            if (is_array($status) && !in_array($w->status, $status, true)) continue;
            if (is_string($status) && $w->status !== $status) continue;
            $out[] = $w;
        }
        return $out;
    }
    public function getJobsWithItems(int $workorderId): array { return []; }
    public function getStatusHistory(int $workorderId): array { return []; }
    public function seed(int $id, int $customerId, ?int $siteAssetId, string $status = 'pending'): Workorder
    {
        $w = new Workorder();
        $w->id = $id;
        $w->number = 'WO-' . $id;
        $w->customer_id = $customerId;
        $w->site_asset_id = $siteAssetId;
        $w->status = $status;
        $w->created_at = '2026-04-' . str_pad((string) ($id % 28 + 1), 2, '0', STR_PAD_LEFT);
        $this->store[$id] = $w;
        return $w;
    }
}

class PssContracts extends ContractRepository
{
    /** @var array<int, Contract> */
    public array $store = [];
    /** @var array<int, array<int, int>> */
    public array $sites = []; // contractId => [siteId,...]
    /** @var array<int, array<string, mixed>> */
    public array $searchCalls = [];
    public function __construct() {}
    public function findById(int $id): ?Contract { return $this->store[$id] ?? null; }
    public function search(array $filters = []): array
    {
        $this->searchCalls[] = $filters;
        $rows = [];
        $allowedSites = $filters['allowed_site_ids'] ?? null;
        foreach ($this->store as $c) {
            if (isset($filters['company_id']) && $c->company_id !== (int) $filters['company_id']) {
                continue;
            }
            if (is_array($allowedSites) && $allowedSites !== []) {
                $rowSites = $this->sites[$c->id] ?? [];
                $hit = false;
                foreach ($rowSites as $s) {
                    if (in_array((int) $s, array_map('intval', $allowedSites), true)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) continue;
            }
            $rows[] = $c;
        }
        return ['data' => $rows, 'total' => count($rows)];
    }
    public function listSiteIdsForContractIds(array $contractIds): array
    {
        $out = [];
        foreach ($contractIds as $cid) {
            $cid = (int) $cid;
            if (isset($this->sites[$cid]) && $this->sites[$cid] !== []) {
                $out[$cid] = $this->sites[$cid];
            }
        }
        return $out;
    }
    public function seed(int $id, int $companyId, array $siteIds): Contract
    {
        $c = new Contract();
        $c->id = $id;
        $c->contract_number = 'C-' . $id;
        $c->company_id = $companyId;
        $c->title = 'Contract ' . $id;
        $c->status = 'active';
        $c->start_date = '2026-01-01';
        $c->end_date = '2026-12-31';
        $this->store[$id] = $c;
        $this->sites[$id] = $siteIds;
        return $c;
    }
}

class PssSiteAssets extends SiteAssetRepository
{
    /** @var array<int, int> assetId => siteId */
    public array $assetToSite = [];
    public int $resolveCalls = 0;
    public function __construct() {}
    public function resolveSiteIdsForAssetIds(array $assetIds): array
    {
        $this->resolveCalls++;
        $out = [];
        foreach ($assetIds as $aid) {
            $aid = (int) $aid;
            if (isset($this->assetToSite[$aid])) {
                $out[$aid] = $this->assetToSite[$aid];
            }
        }
        return $out;
    }
    public function seed(int $assetId, int $siteId): void
    {
        $this->assetToSite[$assetId] = $siteId;
    }
}

class PssPayments extends PaymentProcessingService
{
    public function __construct() {}
}

class PssTokens extends InvoicePublicPaymentTokenService
{
    public function __construct() {}
}

class PssMethods extends PortalPaymentMethodRepository
{
    public function __construct() {}
    public function listForAccount(int $portalAccountId): array { return []; }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function pssAccount(int $companyId = 10, ?array $allowedSites = null): PortalAccount
{
    $a = new PortalAccount();
    $a->id = 77;
    $a->user_id = 999;
    $a->company_id = $companyId;
    $a->is_active = true;
    $a->revoked_at = null;
    $a->allowed_site_ids = $allowedSites;
    return $a;
}

function pssUser(): User
{
    $u = new User();
    $u->id = 999;
    return $u;
}

function pssAssertThrows(callable $fn, string $exceptionClass, string $msgNeedle, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $exceptionClass)) {
            throw new RuntimeException(
                "{$label}: expected {$exceptionClass}, got " . $e::class . ' — ' . $e->getMessage()
            );
        }
        if ($msgNeedle !== '' && stripos($e->getMessage(), $msgNeedle) === false) {
            throw new RuntimeException(
                "{$label}: expected message [{$msgNeedle}], got [{$e->getMessage()}]"
            );
        }
        return;
    }
    throw new RuntimeException("{$label}: expected {$exceptionClass} but nothing was thrown");
}

function pssOk(string $label): void
{
    echo "  ok — {$label}\n";
}

// ---------------------------------------------------------------------------
// Billing (invoices) — strict scoping via site_asset_id → site_assets.site_id
// ---------------------------------------------------------------------------

echo "PortalSiteScopingTest\n";

(function () {
    $customers = new PssCustomers();
    $invoices = new PssInvoices();
    $assets = new PssSiteAssets();
    $service = new PortalBillingService(
        $customers, $invoices, new PssPayments(), new PssTokens(),
        new PssMethods(), new PssAudit(), null, $assets,
    );

    $customers->seed(1, 10);
    $assets->seed(101, 5); // asset 101 → site 5
    $assets->seed(102, 6); // asset 102 → site 6

    $invoices->seed(200, 1, 101, 'sent'); // site 5
    $invoices->seed(201, 1, 102, 'sent'); // site 6
    $invoices->seed(202, 1, null, 'sent'); // legacy NULL-site

    // Unscoped account sees all three.
    $unscoped = pssAccount(10, null);
    $out = $service->listInvoices($unscoped);
    $ids = array_map(fn($i) => $i['id'], $out);
    sort($ids);
    if ($ids !== [200, 201, 202]) {
        throw new RuntimeException('unscoped expected [200,201,202], got ' . json_encode($ids));
    }
    pssOk('billing/list: unscoped account sees all rows including NULL-site legacy invoices');
})();

(function () {
    $customers = new PssCustomers();
    $invoices = new PssInvoices();
    $assets = new PssSiteAssets();
    $service = new PortalBillingService(
        $customers, $invoices, new PssPayments(), new PssTokens(),
        new PssMethods(), new PssAudit(), null, $assets,
    );

    $customers->seed(1, 10);
    $assets->seed(101, 5);
    $assets->seed(102, 6);
    $invoices->seed(200, 1, 101, 'sent'); // site 5 — keep
    $invoices->seed(201, 1, 102, 'sent'); // site 6 — drop
    $invoices->seed(202, 1, null, 'sent'); // NULL — drop (strict)

    $scoped = pssAccount(10, [5]);
    $out = $service->listInvoices($scoped);
    $ids = array_map(fn($i) => $i['id'], $out);
    sort($ids);
    if ($ids !== [200]) {
        throw new RuntimeException('scoped[5] expected [200], got ' . json_encode($ids));
    }
    pssOk('billing/list: scoped account excludes other-site invoices AND NULL-site invoices');
})();

(function () {
    $customers = new PssCustomers();
    $invoices = new PssInvoices();
    $assets = new PssSiteAssets();
    $service = new PortalBillingService(
        $customers, $invoices, new PssPayments(), new PssTokens(),
        new PssMethods(), new PssAudit(), null, $assets,
    );

    $customers->seed(1, 10);
    $assets->seed(101, 5);
    $assets->seed(102, 6);
    $invoices->seed(200, 1, 101, 'sent');
    $invoices->seed(201, 1, 102, 'sent');
    $invoices->seed(202, 1, null, 'sent');

    $scoped = pssAccount(10, [5]);

    // In-scope hit: succeeds.
    $ok = $service->getInvoice($scoped, 200);
    if ($ok['id'] !== 200) {
        throw new RuntimeException('expected to load invoice 200');
    }

    // Wrong-site hit: looks identical to "different company".
    pssAssertThrows(
        fn() => $service->getInvoice($scoped, 201),
        UnauthorizedException::class, 'different company',
        'getInvoice cross-site (correct company, wrong site) must look like company mismatch'
    );

    // NULL-site hit: same opaque error (strict policy).
    pssAssertThrows(
        fn() => $service->getInvoice($scoped, 202),
        UnauthorizedException::class, 'different company',
        'getInvoice NULL-site (correct company, no site_asset_id) excluded under strict policy'
    );
    pssOk('billing/get: cross-site and NULL-site rows raise the same opaque error as cross-company');
})();

// ---------------------------------------------------------------------------
// Workorders — strict scoping via site_asset_id → site_assets.site_id
// ---------------------------------------------------------------------------

(function () {
    $customers = new PssCustomers();
    $workorders = new PssWorkorders();
    $assets = new PssSiteAssets();
    $service = new PortalWorkorderService($workorders, $customers, $assets);

    $customers->seed(1, 10);
    $assets->seed(101, 5);
    $assets->seed(102, 6);
    $workorders->seed(300, 1, 101, 'pending');
    $workorders->seed(301, 1, 102, 'pending');
    $workorders->seed(302, 1, null, 'pending');

    // Unscoped: all three.
    $out = $service->listForPortal(pssUser(), pssAccount(10, null));
    $ids = array_map(fn($r) => $r['id'], $out['data']);
    sort($ids);
    if ($ids !== [300, 301, 302]) {
        throw new RuntimeException('unscoped expected [300,301,302], got ' . json_encode($ids));
    }
    if ($out['total'] !== 3) {
        throw new RuntimeException('unscoped total expected 3, got ' . $out['total']);
    }
    pssOk('workorder/list: unscoped account sees all rows including NULL-site workorders');
})();

(function () {
    $customers = new PssCustomers();
    $workorders = new PssWorkorders();
    $assets = new PssSiteAssets();
    $service = new PortalWorkorderService($workorders, $customers, $assets);

    $customers->seed(1, 10);
    $assets->seed(101, 5);
    $assets->seed(102, 6);
    $workorders->seed(300, 1, 101, 'pending'); // keep
    $workorders->seed(301, 1, 102, 'pending'); // drop
    $workorders->seed(302, 1, null, 'pending'); // drop (strict)

    $out = $service->listForPortal(pssUser(), pssAccount(10, [5]));
    $ids = array_map(fn($r) => $r['id'], $out['data']);
    sort($ids);
    if ($ids !== [300]) {
        throw new RuntimeException('scoped[5] expected [300], got ' . json_encode($ids));
    }
    if ($out['total'] !== 1) {
        throw new RuntimeException('scoped[5] total expected 1, got ' . $out['total']);
    }
    pssOk('workorder/list: scoped account drops other-site AND NULL-site workorders');
})();

(function () {
    $customers = new PssCustomers();
    $workorders = new PssWorkorders();
    $assets = new PssSiteAssets();
    $service = new PortalWorkorderService($workorders, $customers, $assets);

    $customers->seed(1, 10);
    $assets->seed(101, 5);
    $assets->seed(102, 6);
    $workorders->seed(300, 1, 101);
    $workorders->seed(301, 1, 102);
    $workorders->seed(302, 1, null);

    $scoped = pssAccount(10, [5]);

    $ok = $service->getForPortal(pssUser(), $scoped, 300);
    if ($ok['id'] !== 300) {
        throw new RuntimeException('expected to load workorder 300');
    }

    pssAssertThrows(
        fn() => $service->getForPortal(pssUser(), $scoped, 301),
        UnauthorizedException::class, 'different company',
        'getWorkorder cross-site must look like company mismatch'
    );
    pssAssertThrows(
        fn() => $service->getForPortal(pssUser(), $scoped, 302),
        UnauthorizedException::class, 'different company',
        'getWorkorder NULL-site excluded under strict policy with opaque error'
    );
    pssOk('workorder/get: cross-site and NULL-site rows raise the same opaque error as cross-company');
})();

// ---------------------------------------------------------------------------
// Contracts — strict scoping via contract_sites linking rows (ANY-match)
// ---------------------------------------------------------------------------

(function () {
    $contracts = new PssContracts();
    $service = new PortalContractService($contracts);

    $contracts->seed(400, 10, [5]);
    $contracts->seed(401, 10, [6]);
    $contracts->seed(402, 10, []); // no contract_sites linking rows

    // Unscoped: filter is NOT forwarded; sees all three.
    $out = $service->listForPortal(pssUser(), pssAccount(10, null));
    $ids = array_map(fn($r) => $r['id'], $out['data']);
    sort($ids);
    if ($ids !== [400, 401, 402]) {
        throw new RuntimeException('unscoped expected [400,401,402], got ' . json_encode($ids));
    }
    if (array_key_exists('allowed_site_ids', $contracts->searchCalls[0])) {
        throw new RuntimeException('unscoped account must NOT pass allowed_site_ids into search()');
    }
    pssOk('contract/list: unscoped account sees all contracts and does not push site filter');
})();

(function () {
    $contracts = new PssContracts();
    $service = new PortalContractService($contracts);

    $contracts->seed(400, 10, [5]);
    $contracts->seed(401, 10, [6]);
    $contracts->seed(402, 10, []); // no contract_sites — strict drop

    // Scoped: search() must receive allowed_site_ids and EXISTS-filter at the SQL layer.
    $out = $service->listForPortal(pssUser(), pssAccount(10, [5]));
    $ids = array_map(fn($r) => $r['id'], $out['data']);
    sort($ids);
    if ($ids !== [400]) {
        throw new RuntimeException('scoped[5] expected [400], got ' . json_encode($ids));
    }
    if (($contracts->searchCalls[0]['allowed_site_ids'] ?? null) !== [5]) {
        throw new RuntimeException(
            'scoped contract search must forward allowed_site_ids=[5] to repo, got '
            . json_encode($contracts->searchCalls[0])
        );
    }
    pssOk('contract/list: scoped account forwards allowed_site_ids and excludes no-link contracts');
})();

(function () {
    $contracts = new PssContracts();
    $service = new PortalContractService($contracts);

    $contracts->seed(400, 10, [5]);
    $contracts->seed(401, 10, [6]);
    $contracts->seed(402, 10, []); // legacy / unlinked
    $contracts->seed(403, 11, [5]); // wrong company entirely

    $scoped = pssAccount(10, [5]);

    $ok = $service->getForPortal(pssUser(), $scoped, 400);
    if ($ok['id'] !== 400) {
        throw new RuntimeException('expected to load contract 400');
    }

    pssAssertThrows(
        fn() => $service->getForPortal(pssUser(), $scoped, 401),
        UnauthorizedException::class, 'different company',
        'getContract cross-site must look like company mismatch'
    );
    pssAssertThrows(
        fn() => $service->getForPortal(pssUser(), $scoped, 402),
        UnauthorizedException::class, 'different company',
        'getContract no-contract_sites excluded under strict policy with opaque error'
    );
    pssAssertThrows(
        fn() => $service->getForPortal(pssUser(), $scoped, 403),
        UnauthorizedException::class, 'different company',
        'getContract wrong-company stays opaque'
    );
    pssOk('contract/get: cross-site, no-link, and cross-company rows all raise the same opaque error');
})();

// ---------------------------------------------------------------------------
// Multi-site contract: ANY-match wins
// ---------------------------------------------------------------------------

(function () {
    $contracts = new PssContracts();
    $service = new PortalContractService($contracts);

    // Contract spans sites 5 and 6 — account allowed at site 6 should match.
    $contracts->seed(500, 10, [5, 6]);

    $scoped6 = pssAccount(10, [6]);
    $ok = $service->getForPortal(pssUser(), $scoped6, 500);
    if ($ok['id'] !== 500) {
        throw new RuntimeException('expected ANY-match to allow contract 500 for scoped[6]');
    }

    $out = $service->listForPortal(pssUser(), $scoped6);
    $ids = array_map(fn($r) => $r['id'], $out['data']);
    if ($ids !== [500]) {
        throw new RuntimeException('expected list to include contract 500 for scoped[6]');
    }
    pssOk('contract: multi-site contract matches via ANY of its linked sites');
})();

// ---------------------------------------------------------------------------
// Optimization: unscoped accounts must not perform site-resolution work
// ---------------------------------------------------------------------------

(function () {
    $customers = new PssCustomers();
    $invoices = new PssInvoices();
    $assets = new PssSiteAssets();
    $service = new PortalBillingService(
        $customers, $invoices, new PssPayments(), new PssTokens(),
        new PssMethods(), new PssAudit(), null, $assets,
    );

    $customers->seed(1, 10);
    $assets->seed(101, 5);
    $invoices->seed(200, 1, 101, 'sent');

    $unscoped = pssAccount(10, null);
    $service->listInvoices($unscoped);
    $service->getInvoice($unscoped, 200);

    if ($assets->resolveCalls !== 0) {
        throw new RuntimeException(
            'unscoped account should never trigger SiteAssetRepository::resolveSiteIdsForAssetIds, '
            . "called {$assets->resolveCalls} time(s)"
        );
    }
    pssOk('billing: unscoped account skips site_assets lookup entirely (no extra query)');
})();

echo "\nAll PortalSiteScopingTest cases passed.\n";
