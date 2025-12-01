<?php

namespace App\Services\Payment;

use InvalidArgumentException;
use RuntimeException;

/**
 * PayPal Payment Gateway Implementation
 *
 * Requires: composer require paypal/rest-api-sdk-php
 */
class PayPalGateway implements PaymentGatewayInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $webhookId;
    private bool $isSandbox;
    private ?\PayPal\Rest\ApiContext $apiContext = null;

    /**
     * @param array<string, mixed> $config Configuration array
     */
    public function __construct(array $config)
    {
        $this->clientId = (string) ($config['client_id'] ?? '');
        $this->clientSecret = (string) ($config['client_secret'] ?? '');
        $this->webhookId = (string) ($config['webhook_id'] ?? '');
        $this->isSandbox = (bool) ($config['sandbox'] ?? true);

        if ($this->isConfigured()) {
            $this->initializeApiContext();
        }
    }

    public function createCheckoutSession(array $invoiceData, array $options = []): array
    {
        $this->assertConfigured();
        $this->validateInvoiceData($invoiceData);

        try {
            $payer = new \PayPal\Api\Payer();
            $payer->setPaymentMethod('paypal');

            $item = new \PayPal\Api\Item();
            $item->setName($invoiceData['description'] ?? 'Invoice #' . $invoiceData['id'])
                ->setCurrency($options['currency'] ?? 'USD')
                ->setQuantity(1)
                ->setPrice($invoiceData['amount']);

            $itemList = new \PayPal\Api\ItemList();
            $itemList->setItems([$item]);

            $amount = new \PayPal\Api\Amount();
            $amount->setCurrency($options['currency'] ?? 'USD')
                ->setTotal($invoiceData['amount']);

            $transaction = new \PayPal\Api\Transaction();
            $transaction->setAmount($amount)
                ->setItemList($itemList)
                ->setDescription($invoiceData['notes'] ?? 'Payment for Invoice #' . $invoiceData['id'])
                ->setInvoiceNumber('INV-' . $invoiceData['id']);

            $redirectUrls = new \PayPal\Api\RedirectUrls();
            $redirectUrls->setReturnUrl($options['success_url'] ?? '')
                ->setCancelUrl($options['cancel_url'] ?? '');

            $payment = new \PayPal\Api\Payment();
            $payment->setIntent('sale')
                ->setPayer($payer)
                ->setRedirectUrls($redirectUrls)
                ->setTransactions([$transaction]);

            $payment->create($this->apiContext);

            $approvalUrl = '';
            foreach ($payment->getLinks() as $link) {
                if ($link->getRel() === 'approval_url') {
                    $approvalUrl = $link->getHref();
                    break;
                }
            }

            return [
                'checkout_url' => $approvalUrl,
                'session_id' => $payment->getId(),
                'payment_id' => $payment->getId(),
                'state' => $payment->getState(),
                'created_at' => $payment->getCreateTime(),
            ];

        } catch (\PayPal\Exception\PayPalConnectionException $e) {
            throw new RuntimeException('PayPal checkout creation failed: ' . $e->getData(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('PayPal checkout session creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function processPayment(array $paymentData): array
    {
        $this->assertConfigured();

        if (!isset($paymentData['payment_id']) || !isset($paymentData['payer_id'])) {
            throw new InvalidArgumentException('payment_id and payer_id are required for PayPal payment execution');
        }

        try {
            $payment = \PayPal\Api\Payment::get($paymentData['payment_id'], $this->apiContext);

            $execution = new \PayPal\Api\PaymentExecution();
            $execution->setPayerId($paymentData['payer_id']);

            $result = $payment->execute($execution, $this->apiContext);

            $sale = $result->getTransactions()[0]->getRelatedResources()[0]->getSale();

            return [
                'transaction_id' => $sale->getId(),
                'payment_id' => $result->getId(),
                'status' => $this->normalizeStatus($sale->getState()),
                'amount' => $sale->getAmount()->getTotal(),
                'currency' => $sale->getAmount()->getCurrency(),
                'created_at' => $sale->getCreateTime(),
                'payer_email' => $result->getPayer()->getPayerInfo()?->getEmail(),
            ];

        } catch (\PayPal\Exception\PayPalConnectionException $e) {
            throw new RuntimeException('PayPal payment execution failed: ' . $e->getData(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('PayPal payment processing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function handleWebhook(array $payload, string $signature = ''): array
    {
        $this->assertConfigured();

        // PayPal webhook verification
        if ($signature && $this->webhookId) {
            if (!$this->verifyWebhookSignature($payload, $signature)) {
                throw new InvalidArgumentException('Invalid webhook signature');
            }
        }

        $eventType = $payload['event_type'] ?? '';

        return match (true) {
            str_contains($eventType, 'PAYMENT.SALE.COMPLETED') => $this->handlePaymentCompleted($payload['resource'] ?? []),
            str_contains($eventType, 'PAYMENT.SALE.REFUNDED') => $this->handlePaymentRefunded($payload['resource'] ?? []),
            str_contains($eventType, 'PAYMENT.SALE.DENIED') => $this->handlePaymentDenied($payload['resource'] ?? []),
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
            $sale = \PayPal\Api\Sale::get($transactionId, $this->apiContext);

            return [
                'transaction_id' => $sale->getId(),
                'payment_id' => $sale->getParentPayment(),
                'status' => $this->normalizeStatus($sale->getState()),
                'amount' => $sale->getAmount()->getTotal(),
                'currency' => $sale->getAmount()->getCurrency(),
                'created_at' => $sale->getCreateTime(),
                'updated_at' => $sale->getUpdateTime(),
            ];

        } catch (\PayPal\Exception\PayPalConnectionException $e) {
            throw new RuntimeException('Failed to retrieve transaction: ' . $e->getData(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('Failed to retrieve transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    public function refund(string $transactionId, float $amount, string $reason = ''): array
    {
        $this->assertConfigured();

        try {
            $sale = \PayPal\Api\Sale::get($transactionId, $this->apiContext);

            $refundAmount = new \PayPal\Api\Amount();
            $refundAmount->setCurrency('USD')
                ->setTotal($amount);

            $refundRequest = new \PayPal\Api\RefundRequest();
            $refundRequest->setAmount($refundAmount);
            if ($reason) {
                $refundRequest->setDescription($reason);
            }

            $refund = $sale->refundSale($refundRequest, $this->apiContext);

            return [
                'refund_id' => $refund->getId(),
                'transaction_id' => $transactionId,
                'sale_id' => $refund->getSaleId(),
                'status' => $this->normalizeStatus($refund->getState()),
                'amount' => $refund->getAmount()->getTotal(),
                'reason' => $reason,
                'created_at' => $refund->getCreateTime(),
            ];

        } catch (\PayPal\Exception\PayPalConnectionException $e) {
            throw new RuntimeException('Refund failed: ' . $e->getData(), 0, $e);
        } catch (\Exception $e) {
            throw new RuntimeException('Refund failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getName(): string
    {
        return 'paypal';
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId)
            && !empty($this->clientSecret)
            && class_exists('\PayPal\Rest\ApiContext');
    }

    private function initializeApiContext(): void
    {
        $this->apiContext = new \PayPal\Rest\ApiContext(
            new \PayPal\Auth\OAuthTokenCredential(
                $this->clientId,
                $this->clientSecret
            )
        );

        $this->apiContext->setConfig([
            'mode' => $this->isSandbox ? 'sandbox' : 'live',
            'log.LogEnabled' => false,
            'log.FileName' => '',
            'log.LogLevel' => 'INFO',
            'cache.enabled' => true,
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
            if (!class_exists('\PayPal\Rest\ApiContext')) {
                throw new RuntimeException('PayPal SDK not installed. Run: composer require paypal/rest-api-sdk-php');
            }
            throw new RuntimeException('PayPal gateway is not configured. Please set client_id and client_secret.');
        }
    }

    private function normalizeStatus(string $paypalStatus): string
    {
        return match ($paypalStatus) {
            'completed', 'approved' => 'succeeded',
            'pending' => 'processing',
            'refunded', 'partially_refunded' => 'refunded',
            'denied', 'failed' => 'failed',
            'canceled' => 'canceled',
            default => 'unknown',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verifyWebhookSignature(array $payload, string $signature): bool
    {
        if (!$this->webhookId) {
            return true; // Skip verification if no webhook ID configured
        }

        try {
            $webhookEvent = new \PayPal\Api\WebhookEvent();
            $webhookEvent->fromArray($payload);

            $webhookEvent->validateReceivedEvent($this->apiContext, [
                'webhook_id' => $this->webhookId,
                'transmission_id' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? '',
                'transmission_time' => $_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? '',
                'cert_url' => $_SERVER['HTTP_PAYPAL_CERT_URL'] ?? '',
                'auth_algo' => $_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? '',
                'transmission_sig' => $signature,
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function handlePaymentCompleted(array $resource): array
    {
        return [
            'event_type' => 'payment.completed',
            'transaction_id' => $resource['id'] ?? '',
            'amount' => ($resource['amount']['total'] ?? 0),
            'currency' => $resource['amount']['currency'] ?? 'USD',
            'status' => 'succeeded',
            'handled' => true,
        ];
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function handlePaymentRefunded(array $resource): array
    {
        return [
            'event_type' => 'payment.refunded',
            'transaction_id' => $resource['sale_id'] ?? '',
            'refund_id' => $resource['id'] ?? '',
            'amount' => ($resource['amount']['total'] ?? 0),
            'currency' => $resource['amount']['currency'] ?? 'USD',
            'status' => 'refunded',
            'handled' => true,
        ];
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function handlePaymentDenied(array $resource): array
    {
        return [
            'event_type' => 'payment.denied',
            'transaction_id' => $resource['id'] ?? '',
            'amount' => ($resource['amount']['total'] ?? 0),
            'currency' => $resource['amount']['currency'] ?? 'USD',
            'status' => 'failed',
            'handled' => true,
        ];
    }
}
