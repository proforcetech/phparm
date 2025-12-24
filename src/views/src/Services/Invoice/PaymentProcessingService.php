<?php

namespace App\Services\Invoice;

use App\Database\Connection;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentGatewayInterface;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class PaymentProcessingService
{
    private Connection $connection;
    private ?AuditLogger $audit;
    private PaymentGatewayFactory $gatewayFactory;

    public function __construct(
        Connection $connection,
        PaymentGatewayFactory $gatewayFactory,
        ?AuditLogger $audit = null
    ) {
        $this->connection = $connection;
        $this->gatewayFactory = $gatewayFactory;
        $this->audit = $audit;
    }

    /**
     * Create a checkout session using the specified payment gateway
     *
     * @param array<string, mixed> $options Additional options (success_url, cancel_url, etc.)
     * @return array<string, mixed> Checkout session data including checkout_url
     */
    public function createCheckoutSession(int $invoiceId, string $provider, array $options = []): array
    {
        $invoice = $this->fetchInvoice($invoiceId);
        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice not found');
        }

        try {
            $gateway = $this->gatewayFactory->create($provider);

            // Prepare invoice data for gateway
            $invoiceData = [
                'id' => $invoiceId,
                'amount' => (float) $invoice['total'],
                'description' => 'Invoice #' . $invoiceId,
                'notes' => $invoice['notes'] ?? null,
                'customer_id' => $invoice['customer_id'] ?? null,
                'customer_email' => $this->getCustomerEmail((int) ($invoice['customer_id'] ?? 0)),
            ];

            $result = $gateway->createCheckoutSession($invoiceData, $options);

            // Store session info in database
            $this->storeCheckoutSession($invoiceId, $provider, $result);

            $this->log('payment.checkout_created', $invoiceId, [
                'provider' => $provider,
                'session_id' => $result['session_id'] ?? $result['payment_id'] ?? null,
            ]);

            return $result;

        } catch (RuntimeException $e) {
            $this->log('payment.checkout_failed', $invoiceId, [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Process a direct payment (without redirect)
     *
     * @param array<string, mixed> $paymentData Payment data
     * @return array<string, mixed> Payment result
     */
    public function processDirectPayment(int $invoiceId, string $provider, array $paymentData): array
    {
        $invoice = $this->fetchInvoice($invoiceId);
        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice not found');
        }

        try {
            $gateway = $this->gatewayFactory->create($provider);

            $paymentData['amount'] = $paymentData['amount'] ?? (float) $invoice['total'];
            $paymentData['description'] = $paymentData['description'] ?? 'Invoice #' . $invoiceId;
            $paymentData['metadata'] = array_merge($paymentData['metadata'] ?? [], [
                'invoice_id' => $invoiceId,
            ]);

            $result = $gateway->processPayment($paymentData);

            // Record payment in database
            $this->recordPayment($invoiceId, $provider, $result);

            $this->log('payment.processed', $invoiceId, [
                'provider' => $provider,
                'transaction_id' => $result['transaction_id'],
                'status' => $result['status'],
            ]);

            return $result;

        } catch (RuntimeException $e) {
            $this->log('payment.failed', $invoiceId, [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle webhook from payment gateway
     *
     * @param array<string, mixed> $payload Webhook payload
     * @return array<string, mixed> Processed webhook data
     */
    public function handleWebhook(string $provider, array $payload, string $signature = ''): array
    {
        try {
            $gateway = $this->gatewayFactory->create($provider);
            $webhookData = $gateway->handleWebhook($payload, $signature);

            // Extract invoice_id from webhook data
            $invoiceId = (int) ($webhookData['invoice_id'] ?? 0);

            if ($invoiceId && isset($webhookData['status'])) {
                $this->recordPayment($invoiceId, $provider, $webhookData);
                $this->log('payment.webhook', $invoiceId, [
                    'provider' => $provider,
                    'event_type' => $webhookData['event_type'] ?? 'unknown',
                    'status' => $webhookData['status'],
                ]);
            }

            return $webhookData;

        } catch (\Exception $e) {
            $this->log('payment.webhook_failed', 0, [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get available payment gateways
     *
     * @return array<string>
     */
    public function getAvailableGateways(): array
    {
        return $this->gatewayFactory->getAvailableGatewayNames();
    }

    /**
     * Refund a payment
     *
     * @return array<string, mixed> Refund details
     */
    public function refundPayment(int $invoiceId, string $transactionId, float $amount, string $reason = ''): array
    {
        // Get payment record to determine which gateway was used
        $payment = $this->getPaymentByTransaction($transactionId);
        if (!$payment) {
            throw new InvalidArgumentException('Payment not found');
        }

        $provider = $payment['method'] ?? 'stripe';

        try {
            $gateway = $this->gatewayFactory->create($provider);
            $result = $gateway->refund($transactionId, $amount, $reason);

            // Record refund in database
            $this->recordRefund($invoiceId, $provider, $result);

            $this->log('payment.refunded', $invoiceId, [
                'provider' => $provider,
                'transaction_id' => $transactionId,
                'refund_id' => $result['refund_id'],
                'amount' => $amount,
            ]);

            return $result;

        } catch (RuntimeException $e) {
            $this->log('payment.refund_failed', $invoiceId, [
                'provider' => $provider,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Store checkout session info
     *
     * @param array<string, mixed> $sessionData
     */
    private function storeCheckoutSession(int $invoiceId, string $provider, array $sessionData): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payment_sessions (invoice_id, provider, session_id, checkout_url, metadata, created_at) '
            . 'VALUES (:invoice_id, :provider, :session_id, :checkout_url, :metadata, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE session_id = :session_id, checkout_url = :checkout_url, metadata = :metadata'
        );

        $stmt->execute([
            'invoice_id' => $invoiceId,
            'provider' => $provider,
            'session_id' => $sessionData['session_id'] ?? $sessionData['payment_id'] ?? null,
            'checkout_url' => $sessionData['checkout_url'] ?? null,
            'metadata' => json_encode($sessionData),
        ]);
    }

    /**
     * Record a payment in the database
     *
     * @param array<string, mixed> $paymentData
     */
    private function recordPayment(int $invoiceId, string $provider, array $paymentData): void
    {
        $status = $paymentData['status'] ?? 'pending';
        $amount = (float) ($paymentData['amount'] ?? 0);
        $transactionId = $paymentData['transaction_id'] ?? null;

        // Insert or update payment record
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payments (invoice_id, amount, method, reference, status, metadata, created_at) '
            . 'VALUES (:invoice_id, :amount, :method, :reference, :status, :metadata, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE status = :status, metadata = :metadata'
        );

        $stmt->execute([
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'method' => $provider,
            'reference' => $transactionId,
            'status' => $status,
            'metadata' => json_encode($paymentData),
        ]);

        // Update invoice status based on payment
        $this->syncInvoiceStatus($invoiceId, $status, $amount);
    }

    /**
     * Record a refund in the database
     *
     * @param array<string, mixed> $refundData
     */
    private function recordRefund(int $invoiceId, string $provider, array $refundData): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO refunds (invoice_id, payment_reference, refund_id, amount, reason, status, metadata, created_at) '
            . 'VALUES (:invoice_id, :payment_reference, :refund_id, :amount, :reason, :status, :metadata, CURRENT_TIMESTAMP)'
        );

        $stmt->execute([
            'invoice_id' => $invoiceId,
            'payment_reference' => $refundData['transaction_id'] ?? null,
            'refund_id' => $refundData['refund_id'] ?? null,
            'amount' => (float) ($refundData['amount'] ?? 0),
            'reason' => $refundData['reason'] ?? '',
            'status' => $refundData['status'] ?? 'pending',
            'metadata' => json_encode($refundData),
        ]);

        // Update invoice to reflect refund
        $pdo = $this->connection->pdo();
        $pdo->prepare('UPDATE invoices SET status = :status WHERE id = :id')->execute([
            'status' => 'refunded',
            'id' => $invoiceId,
        ]);
    }

    /**
     * Get customer email for invoice
     */
    private function getCustomerEmail(int $customerId): ?string
    {
        if ($customerId === 0) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare('SELECT email FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ($row['email'] ?? null) : null;
    }

    /**
     * Get payment by transaction ID
     *
     * @return array<string, mixed>|null
     */
    private function getPaymentByTransaction(string $transactionId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM payments WHERE reference = :reference');
        $stmt->execute(['reference' => $transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function syncInvoiceStatus(int $invoiceId, string $status, float $amount): void
    {
        $pdo = $this->connection->pdo();
        $pdo->prepare('UPDATE invoices SET status = :status WHERE id = :id')->execute([
            'status' => $status === 'succeeded' ? 'paid' : 'pending',
            'id' => $invoiceId,
        ]);

        if ($status === 'succeeded') {
            $pdo->prepare('UPDATE invoices SET paid_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $invoiceId]);
        }

        $this->log('invoice.balance_synced', $invoiceId, ['status' => $status, 'amount' => $amount]);
    }

    private function fetchInvoice(int $invoiceId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM invoices WHERE id = :id');
        $stmt->execute(['id' => $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function log(string $action, int $entityId, array $payload = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($action, 'invoice', $entityId, null, $payload));
    }
}
