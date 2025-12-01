<?php

namespace App\Services\Payment;

/**
 * Payment Gateway Interface
 *
 * Defines the contract that all payment gateway implementations must follow
 */
interface PaymentGatewayInterface
{
    /**
     * Create a checkout session for an invoice
     *
     * @param array<string, mixed> $invoiceData Invoice data including id, amount, description, customer info
     * @param array<string, mixed> $options Additional gateway-specific options
     * @return array<string, mixed> Contains checkout_url, session_id, and other relevant data
     * @throws \InvalidArgumentException If required data is missing
     * @throws \RuntimeException If gateway API call fails
     */
    public function createCheckoutSession(array $invoiceData, array $options = []): array;

    /**
     * Process a direct payment (without redirect)
     *
     * @param array<string, mixed> $paymentData Payment data including amount, payment_method, etc.
     * @return array<string, mixed> Contains transaction_id, status, and other payment details
     * @throws \InvalidArgumentException If required data is missing
     * @throws \RuntimeException If payment processing fails
     */
    public function processPayment(array $paymentData): array;

    /**
     * Verify and process webhook from payment gateway
     *
     * @param array<string, mixed> $payload Webhook payload from gateway
     * @param string $signature Webhook signature for verification
     * @return array<string, mixed> Normalized payment data
     * @throws \InvalidArgumentException If webhook signature is invalid
     * @throws \RuntimeException If webhook processing fails
     */
    public function handleWebhook(array $payload, string $signature = ''): array;

    /**
     * Retrieve payment/transaction details from gateway
     *
     * @param string $transactionId Gateway transaction ID
     * @return array<string, mixed> Payment details
     * @throws \RuntimeException If retrieval fails
     */
    public function getTransaction(string $transactionId): array;

    /**
     * Refund a payment
     *
     * @param string $transactionId Gateway transaction ID
     * @param float $amount Amount to refund (full or partial)
     * @param string $reason Refund reason
     * @return array<string, mixed> Refund details including refund_id, status
     * @throws \RuntimeException If refund fails
     */
    public function refund(string $transactionId, float $amount, string $reason = ''): array;

    /**
     * Get the gateway name/identifier
     *
     * @return string Gateway name (stripe, square, paypal)
     */
    public function getName(): string;

    /**
     * Check if gateway is properly configured
     *
     * @return bool True if configured and ready to use
     */
    public function isConfigured(): bool;
}
