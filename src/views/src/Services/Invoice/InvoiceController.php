<?php

namespace App\Services\Invoice;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use RuntimeException;

class InvoiceController
{
    private InvoiceService $service;
    private PaymentProcessingService $payments;
    private AccessGate $gate;
    private ?\App\Support\Pdf\InvoicePdfGenerator $pdfGenerator;

    public function __construct(
        InvoiceService $service,
        PaymentProcessingService $payments,
        AccessGate $gate,
        ?\App\Support\Pdf\InvoicePdfGenerator $pdfGenerator = null
    ) {
        $this->service = $service;
        $this->payments = $payments;
        $this->gate = $gate;
        $this->pdfGenerator = $pdfGenerator;
    }

    /**
     * List all invoices with filters
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        $this->assertViewAccess($user);

        if ($user->role === 'customer' && $user->customer_id !== null) {
            $filters['customer_id'] = $user->customer_id;
        }

        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 50;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        $filters = array_diff_key($filters, ['limit' => true, 'offset' => true]);

        $invoices = $this->service->list($filters, $limit, $offset);

        return array_map(static fn ($invoice) => $invoice->toArray(), $invoices);
    }

    /**
     * Get a single invoice
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->assertViewAccess($user);

        $invoice = $this->service->findById($id);

        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice not found');
        }

        $this->assertCustomerOwnership($user, $invoice->customer_id);

        return $invoice->toArray();
    }

    /**
     * Create invoice from estimate
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createFromEstimate(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'invoices.create')) {
            throw new UnauthorizedException('Cannot create invoices');
        }

        if (!isset($data['estimate_id'], $data['job_ids'])) {
            throw new InvalidArgumentException('estimate_id and job_ids are required');
        }

        $invoice = $this->service->createFromEstimate(
            (int) $data['estimate_id'],
            (array) $data['job_ids'],
            $user->id
        );

        if ($invoice === null) {
            throw new InvalidArgumentException('Failed to create invoice from estimate');
        }

        return $invoice->toArray();
    }

    /**
     * Create standalone invoice
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'invoices.create')) {
            throw new UnauthorizedException('Cannot create invoices');
        }

        $invoice = $this->service->createStandalone($data, $user->id);

        return $invoice->toArray();
    }

    /**
     * Update invoice status
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateStatus(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'invoices.update')) {
            throw new UnauthorizedException('Cannot update invoices');
        }

        if (!isset($data['status'])) {
            throw new InvalidArgumentException('status is required');
        }

        $invoice = $this->service->updateStatus($id, (string) $data['status'], $user->id);

        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice not found');
        }

        return $invoice->toArray();
    }

    /**
     * Create payment checkout session
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCheckout(User $user, int $id, array $data): array
    {
        $this->assertViewAccess($user);

        $invoice = $this->service->findById($id);
        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice not found');
        }

        $this->assertCustomerOwnership($user, $invoice->customer_id);

        if (!isset($data['provider'])) {
            throw new InvalidArgumentException('provider is required (stripe, square, or paypal)');
        }

        $result = $this->payments->createCheckoutSession($id, (string) $data['provider'], $data);

        return [
            'checkout_url' => $result['checkout_url'] ?? null,
            'session_id' => $result['session_id'] ?? $result['payment_id'] ?? null,
            'invoice_id' => $id,
            'provider' => $data['provider'],
            'data' => $result,
        ];
    }

    /**
     * Handle payment webhook
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handleWebhook(string $provider, array $payload, string $signature = ''): array
    {
        // Webhooks don't require user authentication
        return $this->payments->handleWebhook($provider, $payload, $signature);
    }

    /**
     * Get available payment gateways
     *
     * @return array<string, mixed>
     */
    public function getAvailableGateways(User $user): array
    {
        // Anyone can view available payment methods
        return [
            'gateways' => $this->payments->getAvailableGateways(),
        ];
    }

    /**
     * Process refund for an invoice payment
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function refundPayment(User $user, int $id, array $data): array
    {
        $this->gate->assert($user, 'invoices.refund');

        if (!isset($data['transaction_id'])) {
            throw new InvalidArgumentException('transaction_id is required');
        }

        if (!isset($data['amount'])) {
            throw new InvalidArgumentException('amount is required');
        }

        $result = $this->payments->refundPayment(
            $id,
            (string) $data['transaction_id'],
            (float) $data['amount'],
            (string) ($data['reason'] ?? '')
        );

        return [
            'refund_id' => $result['refund_id'],
            'status' => $result['status'],
            'amount' => $result['amount'],
            'message' => 'Refund processed successfully',
        ];
    }

    /**
     * Generate and download invoice PDF
     *
     * @param array<string, mixed> $settings
     */
    public function downloadPdf(User $user, int $id, array $settings = []): string
    {
        $invoice = $this->service->findById($id);
        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice not found');
        }

        if ($user->role === 'customer') {
            $this->assertCustomerOwnership($user, $invoice->customer_id);
        } elseif (!$this->gate->can($user, 'invoices.view')) {
            throw new UnauthorizedException('Cannot view invoices');
        }

        if ($this->pdfGenerator === null) {
            throw new RuntimeException('PDF generation not available');
        }

        return $this->pdfGenerator->generate($invoice, $settings);
    }

    private function assertCustomerOwnership(User $user, int $customerId): void
    {
        if ($user->role !== 'customer') {
            return;
        }

        if ($user->customer_id === null || $user->customer_id !== $customerId) {
            throw new UnauthorizedException('Cannot access another customer\'s invoice');
        }
    }

    private function assertViewAccess(User $user): void
    {
        if ($this->gate->can($user, 'invoices.view')) {
            return;
        }

        if ($this->gate->can($user, 'portal.invoices')) {
            return;
        }

        throw new UnauthorizedException('Cannot view invoices');
    }
}
