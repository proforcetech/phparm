<?php

namespace App\Services\Invoice;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class InvoiceController
{
    private InvoiceService $service;
    private PaymentProcessingService $payments;
    private AccessGate $gate;

    public function __construct(
        InvoiceService $service,
        PaymentProcessingService $payments,
        AccessGate $gate
    ) {
        $this->service = $service;
        $this->payments = $payments;
        $this->gate = $gate;
    }

    /**
     * List all invoices with filters
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'invoices.view')) {
            throw new UnauthorizedException('Cannot view invoices');
        }

        $invoices = $this->service->list($filters);

        return array_map(static fn ($invoice) => $invoice->toArray(), $invoices);
    }

    /**
     * Get a single invoice
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'invoices.view')) {
            throw new UnauthorizedException('Cannot view invoices');
        }

        $invoice = $this->service->findById($id);

        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice not found');
        }

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
        // Customer can pay their own invoices
        if (!$this->gate->can($user, 'invoices.view')) {
            throw new UnauthorizedException('Cannot access invoice');
        }

        if (!isset($data['provider'])) {
            throw new InvalidArgumentException('provider is required (stripe, square, or paypal)');
        }

        $checkoutUrl = $this->payments->createCheckoutSession($id, (string) $data['provider'], $data);

        return [
            'checkout_url' => $checkoutUrl,
            'invoice_id' => $id,
            'provider' => $data['provider'],
        ];
    }

    /**
     * Handle payment webhook
     *
     * @param array<string, mixed> $payload
     */
    public function handleWebhook(string $provider, array $payload): bool
    {
        // Webhooks don't require user authentication
        return $this->payments->handleWebhook($provider, $payload);
    }
}
