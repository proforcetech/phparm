<?php

namespace App\Services\Invoice;

use App\Support\Pdf\InvoicePdfGenerator;
use InvalidArgumentException;
use RuntimeException;

class InvoicePublicController
{
    private InvoiceService $service;
    private PaymentProcessingService $payments;
    private ?InvoicePdfGenerator $pdfGenerator;

    public function __construct(
        InvoiceService $service,
        PaymentProcessingService $payments,
        ?InvoicePdfGenerator $pdfGenerator = null
    ) {
        $this->service = $service;
        $this->payments = $payments;
        $this->pdfGenerator = $pdfGenerator;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $token): array
    {
        $invoice = $this->service->findByPublicToken($token);

        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice link is invalid or has expired');
        }

        return $invoice->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCheckout(string $token, array $data): array
    {
        $invoice = $this->service->findByPublicToken($token);
        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice link is invalid or has expired');
        }

        if ($invoice->status === 'paid') {
            throw new InvalidArgumentException('Invoice is already paid');
        }

        if (!isset($data['provider'])) {
            throw new InvalidArgumentException('provider is required (stripe, square, or paypal)');
        }

        $provider = (string) $data['provider'];
        $options = array_merge(
            $data,
            [
                'success_url' => $data['success_url'] ?? "/public/invoices/{$token}?status=success",
                'cancel_url' => $data['cancel_url'] ?? "/public/invoices/{$token}?status=cancel",
            ]
        );

        $result = $this->payments->createCheckoutSession($invoice->id, $provider, $options);

        return [
            'checkout_url' => $result['checkout_url'] ?? null,
            'session_id' => $result['session_id'] ?? $result['payment_id'] ?? null,
            'invoice_id' => $invoice->id,
            'provider' => $provider,
            'data' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function downloadPdf(string $token, array $settings = []): string
    {
        $invoice = $this->service->findByPublicToken($token);
        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice link is invalid or has expired');
        }

        if ($this->pdfGenerator === null) {
            throw new RuntimeException('PDF generation not available');
        }

        return $this->pdfGenerator->generate($invoice, $settings);
    }
}
