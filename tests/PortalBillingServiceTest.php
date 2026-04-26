<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PortalAccount;
use App\Models\PortalPaymentMethod;
use App\Models\User;
use App\Services\Customer\CustomerRepository;
use App\Services\Invoice\InvoicePublicPaymentTokenService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\PaymentProcessingService;
use App\Services\Portal\PortalBillingService;
use App\Services\Portal\PortalPaymentMethodRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 6.4 of docs/expansion-plan.md — customer-portal billing surface.
 *
 * Covers scope + transition + audit + validation invariants:
 *   * invoice scope resolves via customers.company_id (legacy schema has
 *     no company_id on invoices) — cross-company invoices reject with
 *     UnauthorizedException;
 *   * listInvoices walks customers.listIdsForCompany then filters by
 *     status — unpaid_only narrows the status whitelist to [pending,
 *     sent, partial];
 *   * startCheckout pins the amount via InvoicePublicPaymentTokenService,
 *     rejects already-paid invoices, rejects zero-balance invoices,
 *     validates provider enum, emits portal.payment.checkout_started;
 *   * savePaymentMethod enforces gateway enum, external_method_id
 *     length, last4 exactly 4 digits, exp_month 1-12, exp_year 2000-
 *     2100; first saved method auto-promotes to default;
 *   * setDefaultMethod/deletePaymentMethod load scoped method first —
 *     cross-account method IDs report as "not found" (no leakage);
 *   * revoked portal_accounts are rejected at every entry point.
 */

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class PbFakeAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public function __construct() {}
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class PbFakeCustomers extends CustomerRepository
{
    /** @var array<int, Customer> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function find(int $id): ?Customer
    {
        return $this->store[$id] ?? null;
    }
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
    public function seed(array $row): Customer
    {
        $c = new Customer();
        $c->id = $row['id'] ?? $this->nextId++;
        $c->first_name = $row['first_name'] ?? 'A';
        $c->last_name = $row['last_name'] ?? 'B';
        $c->email = $row['email'] ?? 'x@y.z';
        $c->phone = $row['phone'] ?? '555';
        $c->company_id = $row['company_id'] ?? null;
        $this->store[$c->id] = $c;
        return $c;
    }
}

class PbFakeInvoices extends InvoiceService
{
    /** @var array<int, Invoice> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function findById(int $id): ?Invoice
    {
        return $this->store[$id] ?? null;
    }
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $out = [];
        $customerId = (int) ($filters['customer_id'] ?? 0);
        $status = $filters['status'] ?? null;
        foreach ($this->store as $i) {
            if ($customerId !== 0 && $i->customer_id !== $customerId) {
                continue;
            }
            if ($status !== null && $i->status !== $status) {
                continue;
            }
            $out[] = $i;
        }
        return $out;
    }
    public function seed(array $row): Invoice
    {
        $i = new Invoice();
        $i->id = $row['id'] ?? $this->nextId++;
        $i->number = $row['number'] ?? ('INV-' . $i->id);
        $i->customer_id = (int) ($row['customer_id'] ?? 0);
        $i->status = $row['status'] ?? 'pending';
        $i->issue_date = $row['issue_date'] ?? '2026-04-01';
        $i->due_date = $row['due_date'] ?? '2026-05-01';
        $i->subtotal = (float) ($row['subtotal'] ?? 100);
        $i->tax = (float) ($row['tax'] ?? 0);
        $i->total = (float) ($row['total'] ?? 100);
        $i->amount_paid = (float) ($row['amount_paid'] ?? 0);
        $i->balance_due = (float) ($row['balance_due'] ?? ($i->total - $i->amount_paid));
        $i->is_credit_memo = (bool) ($row['is_credit_memo'] ?? false);
        $this->store[$i->id] = $i;
        return $i;
    }
}

class PbFakePayments extends PaymentProcessingService
{
    /** @var array<int, array{invoice_id: int, provider: string, options: array<string, mixed>}> */
    public array $sessionCalls = [];
    public array $nextSession = [
        'checkout_url' => 'https://pay.example/session/abc',
        'session_id' => 'sess_abc',
    ];
    public function __construct() {}
    public function createCheckoutSession(int $invoiceId, string $provider, array $options = []): array
    {
        $this->sessionCalls[] = [
            'invoice_id' => $invoiceId,
            'provider' => $provider,
            'options' => $options,
        ];
        return $this->nextSession;
    }
}

class PbFakeTokens extends InvoicePublicPaymentTokenService
{
    /** @var array<int, array{invoice_id: int, amount: float}> */
    public array $issued = [];
    /** @var int[] */
    public array $consumed = [];
    public int $nextId = 500;
    public function __construct() {}
    public function issueToken(int $invoiceId, float $amount): array
    {
        $this->issued[$this->nextId] = ['invoice_id' => $invoiceId, 'amount' => $amount];
        $token = 't_' . $this->nextId;
        $id = $this->nextId++;
        return ['id' => $id, 'token' => $token, 'amount' => $amount];
    }
    public function validateToken(int $invoiceId, string $token): array
    {
        $id = (int) substr($token, 2);
        $rec = $this->issued[$id] ?? null;
        if ($rec === null || $rec['invoice_id'] !== $invoiceId) {
            throw new RuntimeException('invalid token');
        }
        return ['id' => $id, 'invoice_id' => $invoiceId, 'amount' => $rec['amount']];
    }
    public function consumeToken(int $tokenId): void
    {
        $this->consumed[] = $tokenId;
    }
}

class PbFakeMethods extends PortalPaymentMethodRepository
{
    /** @var array<int, PortalPaymentMethod> */
    public array $store = [];
    public int $nextId = 1;
    /** @var int[] */
    public array $deleted = [];
    /** @var array<int, array{aid:int, mid:int}> */
    public array $setDefaultCalls = [];
    public function __construct() {}
    public function listForAccount(int $portalAccountId): array
    {
        $out = [];
        foreach ($this->store as $m) {
            if ($m->portal_account_id === $portalAccountId) {
                $out[] = $m;
            }
        }
        usort($out, fn($a, $b) => ($b->is_default <=> $a->is_default) ?: ($b->id <=> $a->id));
        return $out;
    }
    public function findById(int $id): ?PortalPaymentMethod
    {
        return $this->store[$id] ?? null;
    }
    public function create(array $data): PortalPaymentMethod
    {
        $m = new PortalPaymentMethod();
        $m->id = $this->nextId++;
        $m->portal_account_id = (int) $data['portal_account_id'];
        $m->gateway = (string) $data['gateway'];
        $m->external_customer_id = $data['external_customer_id'] ?? null;
        $m->external_method_id = (string) $data['external_method_id'];
        $m->brand = $data['brand'] ?? null;
        $m->last4 = $data['last4'] ?? null;
        $m->exp_month = isset($data['exp_month']) ? (int) $data['exp_month'] : null;
        $m->exp_year = isset($data['exp_year']) ? (int) $data['exp_year'] : null;
        $m->label = $data['label'] ?? null;
        $m->is_default = (bool) ($data['is_default'] ?? false);
        $m->created_at = '2026-04-23 00:00:00';
        $this->store[$m->id] = $m;
        return $m;
    }
    public function delete(int $id): void
    {
        $this->deleted[] = $id;
        unset($this->store[$id]);
    }
    public function setDefault(int $portalAccountId, int $methodId): void
    {
        $this->setDefaultCalls[] = ['aid' => $portalAccountId, 'mid' => $methodId];
        foreach ($this->store as $m) {
            if ($m->portal_account_id === $portalAccountId) {
                $m->is_default = false;
            }
        }
        if (isset($this->store[$methodId])) {
            $this->store[$methodId]->is_default = true;
        }
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeBillingFixture(
    int $companyId = 10,
    bool $accountActive = true,
    ?string $revokedAt = null,
): array {
    $audit = new PbFakeAudit();
    $customers = new PbFakeCustomers();
    $invoices = new PbFakeInvoices();
    $payments = new PbFakePayments();
    $tokens = new PbFakeTokens();
    $methods = new PbFakeMethods();
    $service = new PortalBillingService(
        $customers, $invoices, $payments, $tokens, $methods, $audit,
    );

    $user = new User();
    $user->id = 999;

    $account = new PortalAccount();
    $account->id = 77;
    $account->user_id = 999;
    $account->company_id = $companyId;
    $account->is_active = $accountActive;
    $account->revoked_at = $revokedAt;

    return compact(
        'service', 'customers', 'invoices', 'payments', 'tokens',
        'methods', 'audit', 'user', 'account',
    );
}

function assertPbThrows(callable $fn, string $exceptionClass, string $msgNeedle, string $label): void
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

function pbPass(string $label): void
{
    echo "  ok — {$label}\n";
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

echo "PortalBillingServiceTest\n";

// -- listInvoices -------------------------------------------------------------

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var PbFakeInvoices $invoices */
    $invoices = $fx['invoices'];

    $customers->seed(['id' => 1, 'company_id' => 10]);
    $customers->seed(['id' => 2, 'company_id' => 10]);
    $customers->seed(['id' => 3, 'company_id' => 11]); // cross-company

    $invoices->seed(['id' => 100, 'customer_id' => 1, 'status' => 'sent', 'issue_date' => '2026-01-01']);
    $invoices->seed(['id' => 101, 'customer_id' => 2, 'status' => 'paid', 'issue_date' => '2026-02-01']);
    $invoices->seed(['id' => 102, 'customer_id' => 1, 'status' => 'partial', 'issue_date' => '2026-03-01']);
    $invoices->seed(['id' => 103, 'customer_id' => 3, 'status' => 'sent', 'issue_date' => '2026-04-01']); // cross company
    $invoices->seed(['id' => 104, 'customer_id' => 1, 'status' => 'void', 'issue_date' => '2026-04-05']); // not in whitelist

    $out = $fx['service']->listInvoices($fx['account']);
    $ids = array_map(fn($i) => $i['id'], $out);
    sort($ids);
    if ($ids !== [100, 101, 102]) {
        throw new RuntimeException('expected [100,101,102], got ' . json_encode($ids));
    }
    // Sorted newest first by issue_date (102 > 101 > 100)
    $idsInOrder = array_map(fn($i) => $i['id'], $out);
    if ($idsInOrder[0] !== 102) {
        throw new RuntimeException('expected 102 first (newest), got ' . $idsInOrder[0]);
    }
    pbPass('listInvoices scopes by customers.company_id and filters to visible statuses');
})();

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var PbFakeInvoices $invoices */
    $invoices = $fx['invoices'];

    $customers->seed(['id' => 1, 'company_id' => 10]);
    $invoices->seed(['id' => 100, 'customer_id' => 1, 'status' => 'sent']);
    $invoices->seed(['id' => 101, 'customer_id' => 1, 'status' => 'paid']);
    $invoices->seed(['id' => 102, 'customer_id' => 1, 'status' => 'partial']);

    $out = $fx['service']->listInvoices($fx['account'], ['unpaid_only' => true]);
    $ids = array_map(fn($i) => $i['id'], $out);
    sort($ids);
    if ($ids !== [100, 102]) {
        throw new RuntimeException('expected unpaid [100,102], got ' . json_encode($ids));
    }
    pbPass('listInvoices unpaid_only narrows to [pending, sent, partial]');
})();

(function () {
    $fx = makeBillingFixture(10);
    $out = $fx['service']->listInvoices($fx['account']);
    if ($out !== []) {
        throw new RuntimeException('expected empty, got ' . json_encode($out));
    }
    pbPass('listInvoices returns empty for company with no customers');
})();

(function () {
    $fx = makeBillingFixture(10, false);
    assertPbThrows(
        fn() => $fx['service']->listInvoices($fx['account']),
        UnauthorizedException::class, 'not usable',
        'listInvoices on inactive account'
    );
    pbPass('listInvoices rejects inactive account');
})();

// -- getInvoice --------------------------------------------------------------

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var PbFakeInvoices $invoices */
    $invoices = $fx['invoices'];
    $customers->seed(['id' => 1, 'company_id' => 10]);
    $invoices->seed(['id' => 100, 'customer_id' => 1, 'status' => 'sent', 'total' => 250.0]);

    $out = $fx['service']->getInvoice($fx['account'], 100);
    if ($out['id'] !== 100 || (float) $out['total'] !== 250.0) {
        throw new RuntimeException('unexpected payload: ' . json_encode($out));
    }
    pbPass('getInvoice returns scoped serialized payload');
})();

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var PbFakeInvoices $invoices */
    $invoices = $fx['invoices'];
    $customers->seed(['id' => 1, 'company_id' => 11]); // wrong company
    $invoices->seed(['id' => 100, 'customer_id' => 1, 'status' => 'sent']);
    assertPbThrows(
        fn() => $fx['service']->getInvoice($fx['account'], 100),
        UnauthorizedException::class, 'different company',
        'getInvoice cross-company'
    );
    pbPass('getInvoice rejects cross-company invoice');
})();

(function () {
    $fx = makeBillingFixture(10);
    assertPbThrows(
        fn() => $fx['service']->getInvoice($fx['account'], 999),
        InvalidArgumentException::class, 'not found',
        'getInvoice unknown id'
    );
    assertPbThrows(
        fn() => $fx['service']->getInvoice($fx['account'], 0),
        InvalidArgumentException::class, 'invoice id',
        'getInvoice id=0'
    );
    pbPass('getInvoice rejects unknown + non-positive ids');
})();

// -- startCheckout -----------------------------------------------------------

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var PbFakeInvoices $invoices */
    $invoices = $fx['invoices'];
    /** @var PbFakePayments $payments */
    $payments = $fx['payments'];
    /** @var PbFakeTokens $tokens */
    $tokens = $fx['tokens'];
    /** @var PbFakeAudit $audit */
    $audit = $fx['audit'];

    $customers->seed(['id' => 1, 'company_id' => 10]);
    $invoices->seed(['id' => 100, 'customer_id' => 1, 'status' => 'sent',
        'total' => 200.0, 'amount_paid' => 50.0, 'balance_due' => 150.0]);

    $out = $fx['service']->startCheckout(
        $fx['user'], $fx['account'], 100, 'stripe',
        ['success_url' => 'https://portal.example/ok', 'cancel_url' => 'https://portal.example/cancel']
    );

    if ($out['invoice_id'] !== 100 || $out['provider'] !== 'stripe') {
        throw new RuntimeException('checkout payload malformed: ' . json_encode($out));
    }
    if ((float) $out['amount'] !== 150.0) {
        throw new RuntimeException('expected amount 150 (balance_due), got ' . $out['amount']);
    }
    if ($payments->sessionCalls[0]['provider'] !== 'stripe'
        || (float) $payments->sessionCalls[0]['options']['amount'] !== 150.0
        || $payments->sessionCalls[0]['options']['portal_account_id'] !== 77
    ) {
        throw new RuntimeException('gateway called incorrectly: ' . json_encode($payments->sessionCalls[0]));
    }
    if (count($tokens->issued) !== 1 || count($tokens->consumed) !== 1) {
        throw new RuntimeException('token not issued+consumed exactly once');
    }
    $ev = $audit->entries[0];
    if ($ev->event !== 'portal.payment.checkout_started'
        || $ev->entityId !== 100
        || ($ev->context['portal_account_id'] ?? null) !== 77
        || ($ev->context['provider'] ?? null) !== 'stripe'
    ) {
        throw new RuntimeException('audit wrong: ' . json_encode($ev->context));
    }
    pbPass('startCheckout pins amount via token, calls gateway, audits');
})();

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var PbFakeInvoices $invoices */
    $invoices = $fx['invoices'];
    $customers->seed(['id' => 1, 'company_id' => 10]);
    $invoices->seed(['id' => 100, 'customer_id' => 1, 'status' => 'paid',
        'total' => 200.0, 'amount_paid' => 200.0, 'balance_due' => 0.0]);
    assertPbThrows(
        fn() => $fx['service']->startCheckout($fx['user'], $fx['account'], 100, 'stripe'),
        InvalidArgumentException::class, 'already paid',
        'startCheckout on paid invoice'
    );
    pbPass('startCheckout rejects already-paid invoice');
})();

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var PbFakeInvoices $invoices */
    $invoices = $fx['invoices'];
    $customers->seed(['id' => 1, 'company_id' => 10]);
    $invoices->seed(['id' => 100, 'customer_id' => 1, 'status' => 'sent',
        'total' => 0.0, 'amount_paid' => 0.0, 'balance_due' => 0.0]);
    assertPbThrows(
        fn() => $fx['service']->startCheckout($fx['user'], $fx['account'], 100, 'stripe'),
        InvalidArgumentException::class, 'no balance',
        'startCheckout zero-balance'
    );
    pbPass('startCheckout rejects zero-balance invoice');
})();

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var PbFakeInvoices $invoices */
    $invoices = $fx['invoices'];
    $customers->seed(['id' => 1, 'company_id' => 10]);
    $invoices->seed(['id' => 100, 'customer_id' => 1, 'status' => 'sent', 'balance_due' => 50.0]);
    assertPbThrows(
        fn() => $fx['service']->startCheckout($fx['user'], $fx['account'], 100, 'mystery'),
        InvalidArgumentException::class, 'provider',
        'startCheckout bad provider'
    );
    pbPass('startCheckout validates provider enum');
})();

// -- savePaymentMethod -------------------------------------------------------

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeMethods $methods */
    $methods = $fx['methods'];
    /** @var PbFakeAudit $audit */
    $audit = $fx['audit'];

    $out = $fx['service']->savePaymentMethod($fx['user'], $fx['account'], [
        'gateway' => 'stripe',
        'external_customer_id' => 'cus_abc',
        'external_method_id' => 'pm_xyz',
        'brand' => 'Visa',
        'last4' => '4242',
        'exp_month' => 6,
        'exp_year' => 2030,
        'label' => 'Work card',
    ]);
    if ($out['gateway'] !== 'stripe' || $out['brand'] !== 'Visa' || $out['last4'] !== '4242') {
        throw new RuntimeException('save payload wrong: ' . json_encode($out));
    }
    // external IDs must NOT leak to caller
    if (array_key_exists('external_customer_id', $out) || array_key_exists('external_method_id', $out)) {
        throw new RuntimeException('external ids leaked in response: ' . json_encode($out));
    }
    if ($out['is_default'] !== true) {
        throw new RuntimeException('first save should auto-default, got is_default=' . var_export($out['is_default'], true));
    }
    // setDefault was called once (since this was first method)
    if (count($methods->setDefaultCalls) !== 1 || $methods->setDefaultCalls[0]['mid'] !== $out['id']) {
        throw new RuntimeException('expected setDefault once on first save: ' . json_encode($methods->setDefaultCalls));
    }
    $ev = $audit->entries[0];
    if ($ev->event !== 'portal.payment_method.saved'
        || ($ev->context['gateway'] ?? null) !== 'stripe'
        || ($ev->context['is_default'] ?? null) !== true
    ) {
        throw new RuntimeException('audit wrong: ' . json_encode($ev->context));
    }
    pbPass('savePaymentMethod auto-promotes first method to default and audits');
})();

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeMethods $methods */
    $methods = $fx['methods'];

    // Seed an existing method so the new one is NOT first
    $methods->create([
        'portal_account_id' => 77, 'gateway' => 'stripe',
        'external_method_id' => 'pm_existing', 'last4' => '1111',
        'is_default' => true,
    ]);
    $methods->setDefaultCalls = []; // reset — we only care about the next save

    $out = $fx['service']->savePaymentMethod($fx['user'], $fx['account'], [
        'gateway' => 'square',
        'external_method_id' => 'card_new',
        'last4' => '9999',
    ]);
    if ($out['is_default'] !== false) {
        throw new RuntimeException('second save should NOT auto-default, got ' . var_export($out['is_default'], true));
    }
    if (count($methods->setDefaultCalls) !== 0) {
        throw new RuntimeException('setDefault should not be called on non-first save');
    }
    pbPass('savePaymentMethod does not auto-default when another method already exists');
})();

(function () {
    $fx = makeBillingFixture(10);
    assertPbThrows(
        fn() => $fx['service']->savePaymentMethod($fx['user'], $fx['account'], [
            'gateway' => 'bitcoin',
            'external_method_id' => 'pm_x',
        ]),
        InvalidArgumentException::class, 'gateway',
        'savePaymentMethod bad gateway'
    );
    assertPbThrows(
        fn() => $fx['service']->savePaymentMethod($fx['user'], $fx['account'], [
            'gateway' => 'stripe',
            'external_method_id' => '',
        ]),
        InvalidArgumentException::class, 'external_method_id',
        'savePaymentMethod blank external_method_id'
    );
    assertPbThrows(
        fn() => $fx['service']->savePaymentMethod($fx['user'], $fx['account'], [
            'gateway' => 'stripe',
            'external_method_id' => str_repeat('a', 129),
        ]),
        InvalidArgumentException::class, 'external_method_id',
        'savePaymentMethod oversize external_method_id'
    );
    assertPbThrows(
        fn() => $fx['service']->savePaymentMethod($fx['user'], $fx['account'], [
            'gateway' => 'stripe',
            'external_method_id' => 'pm_x',
            'last4' => '12345',
        ]),
        InvalidArgumentException::class, 'last4',
        'savePaymentMethod bad last4'
    );
    assertPbThrows(
        fn() => $fx['service']->savePaymentMethod($fx['user'], $fx['account'], [
            'gateway' => 'stripe',
            'external_method_id' => 'pm_x',
            'exp_month' => 13,
        ]),
        InvalidArgumentException::class, 'exp_month',
        'savePaymentMethod bad exp_month'
    );
    assertPbThrows(
        fn() => $fx['service']->savePaymentMethod($fx['user'], $fx['account'], [
            'gateway' => 'stripe',
            'external_method_id' => 'pm_x',
            'exp_year' => 1999,
        ]),
        InvalidArgumentException::class, 'exp_year',
        'savePaymentMethod bad exp_year'
    );
    pbPass('savePaymentMethod validates gateway, external_method_id, last4, exp_month, exp_year');
})();

// -- listPaymentMethods ------------------------------------------------------

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeMethods $methods */
    $methods = $fx['methods'];
    $methods->create([
        'portal_account_id' => 77, 'gateway' => 'stripe',
        'external_method_id' => 'pm_1', 'last4' => '0001', 'is_default' => false,
    ]);
    $methods->create([
        'portal_account_id' => 77, 'gateway' => 'stripe',
        'external_method_id' => 'pm_2', 'last4' => '0002', 'is_default' => true,
    ]);
    $methods->create([
        'portal_account_id' => 78, 'gateway' => 'stripe', // other account
        'external_method_id' => 'pm_3', 'last4' => '0003',
    ]);

    $out = $fx['service']->listPaymentMethods($fx['account']);
    if (count($out) !== 2) {
        throw new RuntimeException('expected 2 rows, got ' . count($out));
    }
    foreach ($out as $row) {
        if (array_key_exists('external_method_id', $row)
            || array_key_exists('external_customer_id', $row)
        ) {
            throw new RuntimeException('external ids leaked: ' . json_encode($row));
        }
    }
    pbPass('listPaymentMethods is scoped to account.id and hides external ids');
})();

// -- setDefaultMethod --------------------------------------------------------

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeMethods $methods */
    $methods = $fx['methods'];
    /** @var PbFakeAudit $audit */
    $audit = $fx['audit'];

    $m1 = $methods->create(['portal_account_id' => 77, 'gateway' => 'stripe',
        'external_method_id' => 'pm_1', 'is_default' => true]);
    $m2 = $methods->create(['portal_account_id' => 77, 'gateway' => 'stripe',
        'external_method_id' => 'pm_2', 'is_default' => false]);

    $out = $fx['service']->setDefaultMethod($fx['user'], $fx['account'], $m2->id);
    if ($out['is_default'] !== true) {
        throw new RuntimeException('new default should report is_default=true');
    }
    if ($methods->store[$m1->id]->is_default !== false) {
        throw new RuntimeException('old default should have been demoted');
    }
    $ev = end($audit->entries);
    if ($ev->event !== 'portal.payment_method.set_default'
        || $ev->entityId !== $m2->id
    ) {
        throw new RuntimeException('set_default audit wrong: ' . json_encode($ev->toArray()));
    }
    pbPass('setDefaultMethod swaps default atomically and audits');
})();

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeMethods $methods */
    $methods = $fx['methods'];
    // Seed a method for a DIFFERENT account
    $other = $methods->create(['portal_account_id' => 88, 'gateway' => 'stripe',
        'external_method_id' => 'pm_other']);
    // Cross-account access must look identical to "not found"
    assertPbThrows(
        fn() => $fx['service']->setDefaultMethod($fx['user'], $fx['account'], $other->id),
        InvalidArgumentException::class, 'not found',
        'setDefaultMethod cross-account'
    );
    assertPbThrows(
        fn() => $fx['service']->setDefaultMethod($fx['user'], $fx['account'], 9999),
        InvalidArgumentException::class, 'not found',
        'setDefaultMethod unknown id'
    );
    pbPass('setDefaultMethod treats cross-account IDs as not-found (no leak)');
})();

// -- deletePaymentMethod -----------------------------------------------------

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeMethods $methods */
    $methods = $fx['methods'];
    /** @var PbFakeAudit $audit */
    $audit = $fx['audit'];
    $m = $methods->create(['portal_account_id' => 77, 'gateway' => 'stripe',
        'external_method_id' => 'pm_todelete']);
    $fx['service']->deletePaymentMethod($fx['user'], $fx['account'], $m->id);
    if (!in_array($m->id, $methods->deleted, true)) {
        throw new RuntimeException('expected method to be deleted');
    }
    $ev = end($audit->entries);
    if ($ev->event !== 'portal.payment_method.deleted'
        || $ev->entityId !== $m->id
    ) {
        throw new RuntimeException('delete audit wrong: ' . json_encode($ev->toArray()));
    }
    pbPass('deletePaymentMethod removes method and audits');
})();

(function () {
    $fx = makeBillingFixture(10);
    /** @var PbFakeMethods $methods */
    $methods = $fx['methods'];
    $other = $methods->create(['portal_account_id' => 88, 'gateway' => 'stripe',
        'external_method_id' => 'pm_cross']);
    assertPbThrows(
        fn() => $fx['service']->deletePaymentMethod($fx['user'], $fx['account'], $other->id),
        InvalidArgumentException::class, 'not found',
        'deletePaymentMethod cross-account'
    );
    // The original should still exist
    if (!isset($methods->store[$other->id])) {
        throw new RuntimeException('cross-account method should not have been deleted');
    }
    pbPass('deletePaymentMethod refuses cross-account IDs without leaking');
})();

// -- Revoked account block ---------------------------------------------------

(function () {
    $fx = makeBillingFixture(10, true, '2026-04-01 00:00:00');
    /** @var PbFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var PbFakeInvoices $invoices */
    $invoices = $fx['invoices'];
    /** @var PbFakeMethods $methods */
    $methods = $fx['methods'];
    $customers->seed(['id' => 1, 'company_id' => 10]);
    $invoices->seed(['id' => 100, 'customer_id' => 1, 'status' => 'sent', 'balance_due' => 50.0]);
    $m = $methods->create(['portal_account_id' => 77, 'gateway' => 'stripe',
        'external_method_id' => 'pm_ok']);

    assertPbThrows(
        fn() => $fx['service']->listInvoices($fx['account']),
        UnauthorizedException::class, 'not usable',
        'listInvoices revoked'
    );
    assertPbThrows(
        fn() => $fx['service']->getInvoice($fx['account'], 100),
        UnauthorizedException::class, 'not usable',
        'getInvoice revoked'
    );
    assertPbThrows(
        fn() => $fx['service']->startCheckout($fx['user'], $fx['account'], 100, 'stripe'),
        UnauthorizedException::class, 'not usable',
        'startCheckout revoked'
    );
    assertPbThrows(
        fn() => $fx['service']->listPaymentMethods($fx['account']),
        UnauthorizedException::class, 'not usable',
        'listPaymentMethods revoked'
    );
    assertPbThrows(
        fn() => $fx['service']->savePaymentMethod($fx['user'], $fx['account'], [
            'gateway' => 'stripe', 'external_method_id' => 'pm_x',
        ]),
        UnauthorizedException::class, 'not usable',
        'savePaymentMethod revoked'
    );
    assertPbThrows(
        fn() => $fx['service']->setDefaultMethod($fx['user'], $fx['account'], $m->id),
        UnauthorizedException::class, 'not usable',
        'setDefaultMethod revoked'
    );
    assertPbThrows(
        fn() => $fx['service']->deletePaymentMethod($fx['user'], $fx['account'], $m->id),
        UnauthorizedException::class, 'not usable',
        'deletePaymentMethod revoked'
    );
    pbPass('revoked portal_account blocked from every billing action');
})();

echo "\nAll PortalBillingServiceTest cases passed.\n";
