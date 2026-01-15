<?php

namespace App\Services\Invoice;

use App\Support\Pdf\InvoicePdfGenerator;
use InvalidArgumentException;
use RuntimeException;

class InvoicePublicController
{
    private InvoiceService $service;
    private PaymentProcessingService $payments;
    private InvoicePublicPaymentTokenService $paymentTokens;
    private ?InvoicePdfGenerator $pdfGenerator;

    public function __construct(
        InvoiceService $service,
        PaymentProcessingService $payments,
        InvoicePublicPaymentTokenService $paymentTokens,
        ?InvoicePdfGenerator $pdfGenerator = null
    ) {
        $this->service = $service;
        $this->payments = $payments;
        $this->paymentTokens = $paymentTokens;
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

        $data = $invoice->toArray();
        $amountDue = (float) ($invoice->balance_due > 0 ? $invoice->balance_due : $invoice->total);

        $paymentToken = null;
        $paymentTokenExpiresAt = null;

        if ($invoice->status !== 'paid' && $amountDue > 0) {
            $issued = $this->paymentTokens->issueToken($invoice->id, $amountDue);
            $paymentToken = $issued['token'];
            $paymentTokenExpiresAt = $issued['expires_at'];
        }

        $data['payment_token'] = $paymentToken;
        $data['payment_token_expires_at'] = $paymentTokenExpiresAt;
        $data['payment_amount'] = $amountDue;
        $data['payment_providers'] = $this->payments->getAvailableGateways();

        return $data;
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

        if (empty($data['payment_token'])) {
            throw new InvalidArgumentException('payment_token is required');
        }

        $provider = (string) $data['provider'];
        $amountDue = (float) ($invoice->balance_due > 0 ? $invoice->balance_due : $invoice->total);
        if ($amountDue <= 0) {
            throw new InvalidArgumentException('Invoice has no balance due');
        }

        $tokenRecord = $this->paymentTokens->validateToken($invoice->id, (string) $data['payment_token']);
        $tokenAmount = (float) ($tokenRecord['amount'] ?? 0);

        if (abs($tokenAmount - $amountDue) > 0.01) {
            throw new InvalidArgumentException('Payment amount does not match invoice balance.');
        }

        $options = array_merge(
            $data,
            [
                'success_url' => $data['success_url'] ?? "/public/invoices/{$token}?status=success",
                'cancel_url' => $data['cancel_url'] ?? "/public/invoices/{$token}?status=cancel",
                'amount' => $tokenAmount,
            ]
        );
        unset($options['payment_token']);

        $result = $this->payments->createCheckoutSession($invoice->id, $provider, $options);
        $this->paymentTokens->consumeToken((int) $tokenRecord['id']);

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
