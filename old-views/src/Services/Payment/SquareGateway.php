<?php

namespace App\Services\Payment;

use InvalidArgumentException;
use RuntimeException;

/**
 * Square Payment Gateway Implementation
 *
 * Requires: composer require square/square
 */
class SquareGateway implements PaymentGatewayInterface
{
    private string $accessToken;
    private string $locationId;
    private string $webhookSignatureKey;
    private bool $isProduction;
    private ?\Square\SquareClient $client = null;

    /**
     * @param array<string, mixed> $config Configuration array
     */
    public function __construct(array $config)
    {
        $this->accessToken = (string) ($config['access_token'] ?? '');
        $this->locationId = (string) ($config['location_id'] ?? '');
        $this->webhookSignatureKey = (string) ($config['webhook_signature_key'] ?? '');
        $this->isProduction = (bool) ($config['production'] ?? false);

        if ($this->isConfigured()) {
            $this->initializeClient();
        }
    }

    public function createCheckoutSession(array $invoiceData, array $options = []): array
    {
        $this->assertConfigured();
        $this->validateInvoiceData($invoiceData);

        try {
            $checkoutApi = $this->client->getCheckoutApi();

            $amountMoney = new \Square\Models\Money();
            $amountMoney->setAmount((int) ($invoiceData['amount'] * 100)); // Convert to cents
            $amountMoney->setCurrency($options['currency'] ?? 'USD');

            $lineItem = new \Square\Models\CreateOrderRequestLineItem(1);
            $lineItem->setName($invoiceData['description'] ?? 'Invoice #' . $invoiceData['id']);
            $lineItem->setBasePriceMoney($amountMoney);
            if (isset($invoiceData['notes'])) {
                $lineItem->setNote($invoiceData['notes']);
            }

            $order = new \Square\Models\CreateOrderRequest();
            $order->setLocationId($this->locationId);
            $order->setLineItems([$lineItem]);
            $order->setReferenceId('invoice_' . $invoiceData['id']);

            $checkoutRequest = new \Square\Models\CreateCheckoutRequest(
                uniqid('checkout_'),
                $order
            );
            $checkoutRequest->setRedirectUrl($options['success_url'] ?? '');
            $checkoutRequest->setMerchantSupportEmail($options['merchant_email'] ?? null);

            if (isset($invoiceData['customer_email'])) {
                $checkoutRequest->setPrePopulateBuyerEmail($invoiceData['customer_email']);
            }

            $apiResponse = $checkoutApi->createCheckout($this->locationId, $checkoutRequest);

            if ($apiResponse->isSuccess()) {
                $checkout = $apiResponse->getResult()->getCheckout();

                return [
                    'checkout_url' => $checkout->getCheckoutPageUrl(),
                    'session_id' => $checkout->getId(),
                    'order_id' => $checkout->getOrderId(),
                    'created_at' => $checkout->getCreatedAt(),
                ];
            }

            $errors = $apiResponse->getErrors();
            throw new RuntimeException('Square checkout creation failed: ' . json_encode($errors));

        } catch (\Exception $e) {
            throw new RuntimeException('Square checkout session creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function processPayment(array $paymentData): array
    {
        $this->assertConfigured();

        if (!isset($paymentData['amount']) || !isset($paymentData['source_id'])) {
            throw new InvalidArgumentException('Amount and source_id are required');
        }

        try {
            $paymentsApi = $this->client->getPaymentsApi();

            $amountMoney = new \Square\Models\Money();
            $amountMoney->setAmount((int) ($paymentData['amount'] * 100)); // Convert to cents
            $amountMoney->setCurrency($paymentData['currency'] ?? 'USD');

            $paymentRequest = new \Square\Models\CreatePaymentRequest(
                $paymentData['source_id'],
                uniqid('payment_')
            );
            $paymentRequest->setAmountMoney($amountMoney);
            $paymentRequest->setLocationId($this->locationId);

            if (isset($paymentData['customer_id'])) {
                $paymentRequest->setCustomerId($paymentData['customer_id']);
            }

            if (isset($paymentData['reference_id'])) {
                $paymentRequest->setReferenceId($paymentData['reference_id']);
            }

            if (isset($paymentData['note'])) {
                $paymentRequest->setNote($paymentData['note']);
            }

            $apiResponse = $paymentsApi->createPayment($paymentRequest);

            if ($apiResponse->isSuccess()) {
                $payment = $apiResponse->getResult()->getPayment();

                return [
                    'transaction_id' => $payment->getId(),
                    'status' => $this->normalizeStatus($payment->getStatus()),
                    'amount' => $payment->getAmountMoney()->getAmount() / 100,
                    'currency' => $payment->getAmountMoney()->getCurrency(),
                    'receipt_url' => $payment->getReceiptUrl(),
                    'created_at' => $payment->getCreatedAt(),
                ];
            }

            $errors = $apiResponse->getErrors();
            throw new RuntimeException('Square payment failed: ' . json_encode($errors));

        } catch (\Exception $e) {
            throw new RuntimeException('Square payment processing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function handleWebhook(array $payload, string $signature = ''): array
    {
        $this->assertConfigured();

        if ($signature && $this->webhookSignatureKey) {
            if (!$this->verifyWebhookSignature($payload, $signature)) {
                throw new InvalidArgumentException('Invalid webhook signature');
            }
        }

        $eventType = $payload['type'] ?? '';

        return match ($eventType) {
            'payment.created' => $this->handlePaymentCreated($payload['data']['object']['payment'] ?? []),
            'payment.updated' => $this->handlePaymentUpdated($payload['data']['object']['payment'] ?? []),
            'refund.created' => $this->handleRefundCreated($payload['data']['object']['refund'] ?? []),
            default => [
                'event_type' => $eventType,
                'handled' => false,
            ],
        };
    }

    public function getTransaction(string $transactionId): array
    {
        $this->assertConfigured();

        try {
            $paymentsApi = $this->client->getPaymentsApi();
            $apiResponse = $paymentsApi->getPayment($transactionId);

            if ($apiResponse->isSuccess()) {
                $payment = $apiResponse->getResult()->getPayment();

                return [
                    'transaction_id' => $payment->getId(),
                    'status' => $this->normalizeStatus($payment->getStatus()),
                    'amount' => $payment->getAmountMoney()->getAmount() / 100,
                    'currency' => $payment->getAmountMoney()->getCurrency(),
                    'order_id' => $payment->getOrderId(),
                    'receipt_url' => $payment->getReceiptUrl(),
                    'created_at' => $payment->getCreatedAt(),
                ];
            }

            $errors = $apiResponse->getErrors();
            throw new RuntimeException('Failed to retrieve payment: ' . json_encode($errors));

        } catch (\Exception $e) {
            throw new RuntimeException('Failed to retrieve transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    public function refund(string $transactionId, float $amount, string $reason = ''): array
    {
        $this->assertConfigured();

        try {
            $refundsApi = $this->client->getRefundsApi();

            $amountMoney = new \Square\Models\Money();
            $amountMoney->setAmount((int) ($amount * 100)); // Convert to cents
            $amountMoney->setCurrency('USD');

            $refundRequest = new \Square\Models\RefundPaymentRequest(
                uniqid('refund_'),
                $amountMoney
            );
            $refundRequest->setPaymentId($transactionId);
            if ($reason) {
                $refundRequest->setReason($reason);
            }

            $apiResponse = $refundsApi->refundPayment($refundRequest);

            if ($apiResponse->isSuccess()) {
                $refund = $apiResponse->getResult()->getRefund();

                return [
                    'refund_id' => $refund->getId(),
                    'transaction_id' => $transactionId,
                    'status' => $refund->getStatus(),
                    'amount' => $refund->getAmountMoney()->getAmount() / 100,
                    'reason' => $refund->getReason(),
                    'created_at' => $refund->getCreatedAt(),
                ];
            }

            $errors = $apiResponse->getErrors();
            throw new RuntimeException('Refund failed: ' . json_encode($errors));

        } catch (\Exception $e) {
            throw new RuntimeException('Refund failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getName(): string
    {
        return 'square';
    }

    public function isConfigured(): bool
    {
        return !empty($this->accessToken)
            && !empty($this->locationId)
            && class_exists('\Square\SquareClient');
    }

    private function initializeClient(): void
    {
        $this->client = new \Square\SquareClient([
            'accessToken' => $this->accessToken,
            'environment' => $this->isProduction
                ? \Square\Environment::PRODUCTION
                : \Square\Environment::SANDBOX,
        ]);
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
            if (!class_exists('\Square\SquareClient')) {
                throw new RuntimeException('Square PHP SDK not installed. Run: composer require square/square');
            }
            throw new RuntimeException('Square gateway is not configured. Please set access_token and location_id.');
        }
    }

    private function normalizeStatus(string $squareStatus): string
    {
        return match ($squareStatus) {
            'COMPLETED' => 'succeeded',
            'APPROVED' => 'succeeded',
            'PENDING' => 'processing',
            'CANCELED' => 'canceled',
            'FAILED' => 'failed',
            default => 'unknown',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verifyWebhookSignature(array $payload, string $signature): bool
    {
        if (!$this->webhookSignatureKey) {
            return true; // Skip verification if no key configured
        }

        try {
            return \Square\Utils\WebhooksHelper::isValidWebhookEventSignature(
                json_encode($payload),
                $signature,
                $this->webhookSignatureKey,
                env('APP_URL', '')
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function handlePaymentCreated(array $payment): array
    {
        return [
            'event_type' => 'payment.created',
            'transaction_id' => $payment['id'] ?? '',
            'amount' => ($payment['amount_money']['amount'] ?? 0) / 100,
            'currency' => $payment['amount_money']['currency'] ?? 'USD',
            'status' => $this->normalizeStatus($payment['status'] ?? 'PENDING'),
            'order_id' => $payment['order_id'] ?? null,
            'handled' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function handlePaymentUpdated(array $payment): array
    {
        return [
            'event_type' => 'payment.updated',
            'transaction_id' => $payment['id'] ?? '',
            'amount' => ($payment['amount_money']['amount'] ?? 0) / 100,
            'currency' => $payment['amount_money']['currency'] ?? 'USD',
            'status' => $this->normalizeStatus($payment['status'] ?? 'PENDING'),
            'order_id' => $payment['order_id'] ?? null,
            'handled' => true,
        ];
    }

    /**
     * @param array<string, mixed> $refund
     * @return array<string, mixed>
     */
    private function handleRefundCreated(array $refund): array
    {
        return [
            'event_type' => 'refund.created',
            'refund_id' => $refund['id'] ?? '',
            'transaction_id' => $refund['payment_id'] ?? '',
            'amount' => ($refund['amount_money']['amount'] ?? 0) / 100,
            'currency' => $refund['amount_money']['currency'] ?? 'USD',
            'status' => 'refunded',
            'reason' => $refund['reason'] ?? '',
            'handled' => true,
        ];
    }
}
