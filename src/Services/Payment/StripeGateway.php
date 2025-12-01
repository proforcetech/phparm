<?php

namespace App\Services\Payment;

use InvalidArgumentException;
use RuntimeException;

/**
 * Stripe Payment Gateway Implementation
 *
 * Requires: composer require stripe/stripe-php
 */
class StripeGateway implements PaymentGatewayInterface
{
    private string $secretKey;
    private string $webhookSecret;
    private bool $isTestMode;

    /**
     * @param array<string, mixed> $config Configuration array
     */
    public function __construct(array $config)
    {
        $this->secretKey = (string) ($config['secret_key'] ?? '');
        $this->webhookSecret = (string) ($config['webhook_secret'] ?? '');
        $this->isTestMode = (bool) ($config['test_mode'] ?? true);

        if ($this->secretKey && class_exists('\Stripe\Stripe')) {
            \Stripe\Stripe::setApiKey($this->secretKey);
        }
    }

    public function createCheckoutSession(array $invoiceData, array $options = []): array
    {
        $this->assertConfigured();
        $this->validateInvoiceData($invoiceData);

        try {
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => $options['payment_methods'] ?? ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $options['currency'] ?? 'usd',
                        'product_data' => [
                            'name' => $invoiceData['description'] ?? 'Invoice #' . $invoiceData['id'],
                            'description' => $invoiceData['notes'] ?? null,
                        ],
                        'unit_amount' => (int) ($invoiceData['amount'] * 100), // Convert to cents
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $options['success_url'] ?? '',
                'cancel_url' => $options['cancel_url'] ?? '',
                'client_reference_id' => (string) $invoiceData['id'],
                'customer_email' => $invoiceData['customer_email'] ?? null,
                'metadata' => [
                    'invoice_id' => (string) $invoiceData['id'],
                    'customer_id' => (string) ($invoiceData['customer_id'] ?? ''),
                ],
            ]);

            return [
                'checkout_url' => $session->url,
                'session_id' => $session->id,
                'payment_intent' => $session->payment_intent,
                'expires_at' => $session->expires_at,
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Stripe checkout session creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function processPayment(array $paymentData): array
    {
        $this->assertConfigured();

        if (!isset($paymentData['amount']) || !isset($paymentData['payment_method'])) {
            throw new InvalidArgumentException('Amount and payment_method are required');
        }

        try {
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => (int) ($paymentData['amount'] * 100), // Convert to cents
                'currency' => $paymentData['currency'] ?? 'usd',
                'payment_method' => $paymentData['payment_method'],
                'confirm' => true,
                'description' => $paymentData['description'] ?? null,
                'metadata' => $paymentData['metadata'] ?? [],
            ]);

            return [
                'transaction_id' => $paymentIntent->id,
                'status' => $this->normalizeStatus($paymentIntent->status),
                'amount' => $paymentIntent->amount / 100,
                'currency' => $paymentIntent->currency,
                'payment_method' => $paymentIntent->payment_method,
                'created_at' => date('Y-m-d H:i:s', $paymentIntent->created),
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Stripe payment processing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function handleWebhook(array $payload, string $signature = ''): array
    {
        $this->assertConfigured();

        if (!$signature) {
            throw new InvalidArgumentException('Webhook signature is required');
        }

        try {
            // Verify webhook signature
            $event = \Stripe\Webhook::constructEvent(
                json_encode($payload),
                $signature,
                $this->webhookSecret
            );

            // Handle different event types
            switch ($event->type) {
                case 'checkout.session.completed':
                    return $this->handleCheckoutCompleted($event->data->object);

                case 'payment_intent.succeeded':
                    return $this->handlePaymentSucceeded($event->data->object);

                case 'payment_intent.payment_failed':
                    return $this->handlePaymentFailed($event->data->object);

                case 'charge.refunded':
                    return $this->handleRefund($event->data->object);

                default:
                    return [
                        'event_type' => $event->type,
                        'handled' => false,
                    ];
            }

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            throw new InvalidArgumentException('Invalid webhook signature: ' . $e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('Webhook processing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getTransaction(string $transactionId): array
    {
        $this->assertConfigured();

        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($transactionId);

            return [
                'transaction_id' => $paymentIntent->id,
                'status' => $this->normalizeStatus($paymentIntent->status),
                'amount' => $paymentIntent->amount / 100,
                'currency' => $paymentIntent->currency,
                'payment_method' => $paymentIntent->payment_method,
                'created_at' => date('Y-m-d H:i:s', $paymentIntent->created),
                'metadata' => (array) $paymentIntent->metadata,
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Failed to retrieve transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    public function refund(string $transactionId, float $amount, string $reason = ''): array
    {
        $this->assertConfigured();

        try {
            $refund = \Stripe\Refund::create([
                'payment_intent' => $transactionId,
                'amount' => (int) ($amount * 100), // Convert to cents
                'reason' => $reason ?: null,
            ]);

            return [
                'refund_id' => $refund->id,
                'transaction_id' => $transactionId,
                'status' => $refund->status,
                'amount' => $refund->amount / 100,
                'reason' => $refund->reason,
                'created_at' => date('Y-m-d H:i:s', $refund->created),
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new RuntimeException('Refund failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return !empty($this->secretKey) && class_exists('\Stripe\Stripe');
    }

    /**
     * @param array<string, mixed> $invoiceData
     */
    private function validateInvoiceData(array $invoiceData): void
    {
        if (!isset($invoiceData['id'])) {
            throw new InvalidArgumentException('Invoice ID is required');
        }

        if (!isset($invoiceData['amount']) || $invoiceData['amount'] <= 0) {
            throw new InvalidArgumentException('Valid invoice amount is required');
        }
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            if (!class_exists('\Stripe\Stripe')) {
                throw new RuntimeException('Stripe PHP library not installed. Run: composer require stripe/stripe-php');
            }
            throw new RuntimeException('Stripe gateway is not configured. Please set secret_key in configuration.');
        }
    }

    /**
     * Normalize Stripe status to our standard statuses
     */
    private function normalizeStatus(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'succeeded' => 'succeeded',
            'processing' => 'processing',
            'requires_payment_method', 'requires_confirmation', 'requires_action' => 'pending',
            'canceled' => 'canceled',
            'failed' => 'failed',
            default => 'unknown',
        };
    }

    /**
     * @param \Stripe\Checkout\Session $session
     * @return array<string, mixed>
     */
    private function handleCheckoutCompleted($session): array
    {
        return [
            'event_type' => 'checkout.completed',
            'invoice_id' => (int) ($session->metadata->invoice_id ?? 0),
            'transaction_id' => $session->payment_intent,
            'amount' => $session->amount_total / 100,
            'currency' => $session->currency,
            'status' => 'succeeded',
            'customer_email' => $session->customer_email,
            'handled' => true,
        ];
    }

    /**
     * @param \Stripe\PaymentIntent $paymentIntent
     * @return array<string, mixed>
     */
    private function handlePaymentSucceeded($paymentIntent): array
    {
        return [
            'event_type' => 'payment.succeeded',
            'invoice_id' => (int) ($paymentIntent->metadata->invoice_id ?? 0),
            'transaction_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount / 100,
            'currency' => $paymentIntent->currency,
            'status' => 'succeeded',
            'handled' => true,
        ];
    }

    /**
     * @param \Stripe\PaymentIntent $paymentIntent
     * @return array<string, mixed>
     */
    private function handlePaymentFailed($paymentIntent): array
    {
        return [
            'event_type' => 'payment.failed',
            'invoice_id' => (int) ($paymentIntent->metadata->invoice_id ?? 0),
            'transaction_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount / 100,
            'currency' => $paymentIntent->currency,
            'status' => 'failed',
            'error' => $paymentIntent->last_payment_error?->message ?? 'Payment failed',
            'handled' => true,
        ];
    }

    /**
     * @param \Stripe\Charge $charge
     * @return array<string, mixed>
     */
    private function handleRefund($charge): array
    {
        return [
            'event_type' => 'refund',
            'transaction_id' => $charge->payment_intent,
            'amount' => $charge->amount_refunded / 100,
            'currency' => $charge->currency,
            'status' => 'refunded',
            'handled' => true,
        ];
    }
}
