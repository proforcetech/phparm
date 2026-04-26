<?php

namespace App\Services\Portal;

use App\Models\Invoice;
use App\Models\PortalAccount;
use App\Models\PortalPaymentMethod;
use App\Models\User;
use App\Services\Customer\CustomerRepository;
use App\Services\Invoice\InvoicePublicPaymentTokenService;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\PaymentProcessingService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Phase 6.4 of docs/expansion-plan.md — portal-facing invoice list +
 * checkout initiation + saved payment method CRUD.
 *
 * Scope layers:
 *   * invoice ownership resolves via customers.company_id (the legacy
 *     invoices table has no company_id column, matching the estimate
 *     scoping in Phase 6.3);
 *   * listPendingInvoices walks the set of customer_ids owned by the
 *     portal_account's company and filters invoice status to the unpaid
 *     statuses — only what the portal user needs to pay;
 *   * payment method rows are keyed on portal_account_id and never
 *     trust IDs from the request body for scoping.
 *
 * Checkout initiation delegates to PaymentProcessingService (same path
 * staff uses for customer self-serve) after re-validating invoice scope.
 * We never trust arbitrary success_url / cancel_url from the client —
 * callers (controllers) sanitize those to the portal domain and pass
 * through only safe options.
 */
class PortalBillingService
{
    public const PORTAL_VISIBLE_INVOICE_STATUSES = ['pending', 'sent', 'partial', 'paid'];
    public const UNPAID_INVOICE_STATUSES = ['pending', 'sent', 'partial'];

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly InvoiceService $invoices,
        private readonly PaymentProcessingService $payments,
        private readonly InvoicePublicPaymentTokenService $paymentTokens,
        private readonly PortalPaymentMethodRepository $methods,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array{unpaid_only?: bool, limit?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function listInvoices(PortalAccount $account, array $filters = []): array
    {
        $this->assertUsable($account);
        $customerIds = $this->customers->listIdsForCompany($account->company_id);
        if ($customerIds === []) {
            return [];
        }
        $unpaidOnly = (bool) ($filters['unpaid_only'] ?? false);
        $statuses = $unpaidOnly
            ? self::UNPAID_INVOICE_STATUSES
            : self::PORTAL_VISIBLE_INVOICE_STATUSES;

        $out = [];
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        foreach ($customerIds as $customerId) {
            foreach ($statuses as $status) {
                foreach ($this->invoices->list(
                    ['customer_id' => $customerId, 'status' => $status],
                    $limit,
                    0,
                ) as $invoice) {
                    $out[] = $this->serializeInvoice($invoice);
                }
            }
        }
        // Sort by issue_date desc so the portal UI sees newest first.
        usort($out, static fn($a, $b) => strcmp((string) ($b['issue_date'] ?? ''), (string) ($a['issue_date'] ?? '')));
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoice(PortalAccount $account, int $invoiceId): array
    {
        $invoice = $this->loadScopedInvoice($account, $invoiceId);
        return $this->serializeInvoice($invoice);
    }

    /**
     * Initiate a checkout session. Mirrors the public-link flow: a
     * short-lived payment token pins the amount, then we hand off to
     * the chosen gateway. The portal controller supplies provider +
     * any return URLs it trusts.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function startCheckout(
        User $user,
        PortalAccount $account,
        int $invoiceId,
        string $provider,
        array $options = [],
    ): array {
        $invoice = $this->loadScopedInvoice($account, $invoiceId);
        if ($invoice->status === 'paid') {
            throw new InvalidArgumentException('invoice is already paid');
        }
        $provider = strtolower(trim($provider));
        if (!in_array($provider, ['stripe', 'square', 'paypal'], true)) {
            throw new InvalidArgumentException('provider must be stripe, square, or paypal');
        }
        $amountDue = (float) ($invoice->balance_due > 0 ? $invoice->balance_due : $invoice->total);
        if ($amountDue <= 0) {
            throw new InvalidArgumentException('invoice has no balance due');
        }

        $tokenIssue = $this->paymentTokens->issueToken($invoice->id, $amountDue);
        $tokenRecord = $this->paymentTokens->validateToken($invoice->id, $tokenIssue['token']);

        $checkoutOptions = [
            'amount' => $amountDue,
            'success_url' => $options['success_url'] ?? null,
            'cancel_url' => $options['cancel_url'] ?? null,
            'portal_account_id' => $account->id,
        ];
        // Drop any null URLs so gateways apply their own defaults instead
        // of choking on a literal null.
        $checkoutOptions = array_filter(
            $checkoutOptions,
            static fn($v) => $v !== null
        );

        $result = $this->payments->createCheckoutSession(
            $invoice->id,
            $provider,
            $checkoutOptions,
        );

        $this->paymentTokens->consumeToken((int) $tokenRecord['id']);

        $this->audit->log(new AuditEntry(
            'portal.payment.checkout_started',
            'invoice',
            $invoice->id,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'customer_id' => $invoice->customer_id,
                'provider' => $provider,
                'amount' => $amountDue,
            ]
        ));

        return [
            'invoice_id' => $invoice->id,
            'provider' => $provider,
            'amount' => $amountDue,
            'checkout_url' => $result['checkout_url'] ?? null,
            'session_id' => $result['session_id'] ?? $result['payment_id'] ?? null,
            'data' => $result,
        ];
    }

    // ── Saved payment methods ─────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPaymentMethods(PortalAccount $account): array
    {
        $this->assertUsable($account);
        return array_map(
            fn(PortalPaymentMethod $m) => $this->serializeMethod($m),
            $this->methods->listForAccount($account->id)
        );
    }

    /**
     * Save a tokenized payment method. The external_method_id is whatever
     * the gateway returned after client-side tokenization — we never see
     * raw card data.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function savePaymentMethod(User $user, PortalAccount $account, array $input): array
    {
        $this->assertUsable($account);

        $gateway = strtolower(trim((string) ($input['gateway'] ?? '')));
        if (!in_array($gateway, PortalPaymentMethodRepository::ALLOWED_GATEWAYS, true)) {
            throw new InvalidArgumentException('gateway must be stripe, square, or paypal');
        }
        $externalMethodId = trim((string) ($input['external_method_id'] ?? ''));
        if ($externalMethodId === '' || strlen($externalMethodId) > 128) {
            throw new InvalidArgumentException('external_method_id is required (1-128 chars)');
        }
        $externalCustomerId = isset($input['external_customer_id'])
            ? (string) $input['external_customer_id']
            : null;
        if ($externalCustomerId !== null && strlen($externalCustomerId) > 128) {
            throw new InvalidArgumentException('external_customer_id must be ≤ 128 chars');
        }
        $brand = isset($input['brand']) ? substr((string) $input['brand'], 0, 32) : null;
        $last4 = isset($input['last4']) ? preg_replace('/\D/', '', (string) $input['last4']) : null;
        if ($last4 !== null && $last4 !== '' && strlen($last4) !== 4) {
            throw new InvalidArgumentException('last4 must be exactly 4 digits when provided');
        }
        $expMonth = isset($input['exp_month']) ? (int) $input['exp_month'] : null;
        if ($expMonth !== null && ($expMonth < 1 || $expMonth > 12)) {
            throw new InvalidArgumentException('exp_month must be between 1 and 12');
        }
        $expYear = isset($input['exp_year']) ? (int) $input['exp_year'] : null;
        if ($expYear !== null && ($expYear < 2000 || $expYear > 2100)) {
            throw new InvalidArgumentException('exp_year must be a realistic 4-digit year');
        }
        $label = isset($input['label']) ? substr(trim((string) $input['label']), 0, 80) : null;
        if ($label === '') {
            $label = null;
        }

        $makeDefault = (bool) ($input['is_default'] ?? false);

        // If this is the portal user's first saved method, promote it to
        // default automatically — no point in having a single non-default.
        $existing = $this->methods->listForAccount($account->id);
        if ($existing === []) {
            $makeDefault = true;
        }

        $method = $this->methods->create([
            'portal_account_id' => $account->id,
            'gateway' => $gateway,
            'external_customer_id' => $externalCustomerId,
            'external_method_id' => $externalMethodId,
            'brand' => $brand,
            'last4' => $last4,
            'exp_month' => $expMonth,
            'exp_year' => $expYear,
            'label' => $label,
            'is_default' => $makeDefault,
        ]);

        if ($makeDefault) {
            // Re-run as an atomic swap so existing rows flip off correctly.
            $this->methods->setDefault($account->id, $method->id);
        }

        $this->audit->log(new AuditEntry(
            'portal.payment_method.saved',
            'portal_payment_method',
            $method->id,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'gateway' => $gateway,
                'brand' => $brand,
                'last4' => $last4,
                'is_default' => $makeDefault,
            ]
        ));

        $refreshed = $this->methods->findById($method->id);
        if ($refreshed === null) {
            throw new \RuntimeException('saved payment method vanished immediately after insert');
        }
        return $this->serializeMethod($refreshed);
    }

    public function setDefaultMethod(User $user, PortalAccount $account, int $methodId): array
    {
        $method = $this->loadScopedMethod($account, $methodId);
        $this->methods->setDefault($account->id, $method->id);
        $this->audit->log(new AuditEntry(
            'portal.payment_method.set_default',
            'portal_payment_method',
            $method->id,
            $user->id,
            [
                'portal_account_id' => $account->id,
            ]
        ));
        $refreshed = $this->methods->findById($method->id);
        if ($refreshed === null) {
            throw new \RuntimeException('payment method vanished after setDefault');
        }
        return $this->serializeMethod($refreshed);
    }

    public function deletePaymentMethod(User $user, PortalAccount $account, int $methodId): void
    {
        $method = $this->loadScopedMethod($account, $methodId);
        $this->methods->delete($method->id);
        $this->audit->log(new AuditEntry(
            'portal.payment_method.deleted',
            'portal_payment_method',
            $method->id,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'gateway' => $method->gateway,
            ]
        ));
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function loadScopedInvoice(PortalAccount $account, int $invoiceId): Invoice
    {
        $this->assertUsable($account);
        if ($invoiceId <= 0) {
            throw new InvalidArgumentException('invoice id is required');
        }
        $invoice = $this->invoices->findById($invoiceId);
        if ($invoice === null) {
            throw new InvalidArgumentException("invoice {$invoiceId} not found");
        }
        $customer = $this->customers->find($invoice->customer_id);
        if ($customer === null
            || (int) ($customer->company_id ?? 0) !== $account->company_id
        ) {
            throw new UnauthorizedException(
                'invoice belongs to a different company'
            );
        }
        return $invoice;
    }

    private function loadScopedMethod(PortalAccount $account, int $methodId): PortalPaymentMethod
    {
        $this->assertUsable($account);
        if ($methodId <= 0) {
            throw new InvalidArgumentException('payment method id is required');
        }
        $method = $this->methods->findById($methodId);
        if ($method === null || $method->portal_account_id !== $account->id) {
            // Same error as "not found" to avoid leaking another account's
            // method ids via timing/message differences.
            throw new InvalidArgumentException("payment method {$methodId} not found");
        }
        return $method;
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeInvoice(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status,
            'customer_id' => $i->customer_id,
            'issue_date' => $i->issue_date,
            'due_date' => $i->due_date,
            'subtotal' => $i->subtotal,
            'tax' => $i->tax,
            'total' => $i->total,
            'amount_paid' => $i->amount_paid,
            'balance_due' => $i->balance_due,
            'is_credit_memo' => $i->is_credit_memo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMethod(PortalPaymentMethod $m): array
    {
        return [
            'id' => $m->id,
            'gateway' => $m->gateway,
            'brand' => $m->brand,
            'last4' => $m->last4,
            'exp_month' => $m->exp_month,
            'exp_year' => $m->exp_year,
            'label' => $m->label,
            'is_default' => $m->is_default,
            // External IDs are never surfaced to the client — they're
            // gateway-internal references only the server needs.
            'created_at' => $m->created_at,
        ];
    }
}
