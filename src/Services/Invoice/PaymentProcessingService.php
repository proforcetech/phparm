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

            $amount = $this->resolveInvoiceAmount($invoice, $options);

            // Prepare invoice data for gateway
            $invoiceData = [
                'id' => $invoiceId,
                'amount' => $amount,
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
    public function refundPayment(
        int $invoiceId,
        string $transactionId,
        float $amount,
        string $reason = '',
        ?string $refundMethod = null
    ): array
    {
        // Get payment record to determine which gateway was used
        $payment = $this->getPaymentByTransaction($transactionId);
        if (!$payment) {
            throw new InvalidArgumentException('Payment not found');
        }

        $provider = $payment['gateway'] ?? $payment['method'] ?? 'stripe';
        $originalMethod = strtolower((string) ($payment['method'] ?? $payment['gateway'] ?? ''));
        $requestedMethod = $refundMethod !== null ? strtolower(trim($refundMethod)) : '';

        if ($requestedMethod === '') {
            throw new InvalidArgumentException('payment_method is required');
        }

        if ($originalMethod !== '' && $requestedMethod !== $originalMethod) {
            $methodLabel = $payment['method'] ?? $payment['gateway'] ?? 'original method';
            throw new InvalidArgumentException(
                sprintf('Refund method must match original payment method (%s).', $methodLabel)
            );
        }

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
        $method = (string) ($paymentData['payment_method'] ?? $paymentData['method'] ?? $provider);
        $reference = $paymentData['reference'] ?? $transactionId;
        $metadata = $paymentData;
        $metadata['original_method'] = $method;
        $metadata['original_gateway'] = $provider;

        // Insert or update payment record
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payments (invoice_id, gateway, method, transaction_id, amount, reference, status, metadata, paid_at, created_at) '
            . 'VALUES (:invoice_id, :gateway, :method, :transaction_id, :amount, :reference, :status, :metadata, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
            . 'ON DUPLICATE KEY UPDATE status = :status, metadata = :metadata, method = :method, gateway = :gateway'
        );

        $stmt->execute([
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'gateway' => $provider,
            'method' => $method,
            'transaction_id' => $transactionId,
            'reference' => $reference,
            'status' => $status,
            'metadata' => json_encode($metadata),
        ]);

        // Update invoice status based on payment
        $this->syncInvoiceStatus($invoiceId, $status, $amount, $provider, $paymentData);
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
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM payments WHERE reference = :reference OR transaction_id = :reference'
        );
        $stmt->execute(['reference' => $transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $paymentData
     */
    private function syncInvoiceStatus(
        int $invoiceId,
        string $status,
        float $amount,
        string $provider,
        array $paymentData
    ): void
    {
        $pdo = $this->connection->pdo();
        if ($status !== 'succeeded') {
            $pdo->prepare('UPDATE invoices SET status = :status WHERE id = :id')->execute([
                'status' => 'pending',
                'id' => $invoiceId,
            ]);
            $this->log('invoice.balance_synced', $invoiceId, ['status' => $status, 'amount' => $amount]);
            return;
        }

        if ($amount > 0) {
            $pdo->prepare(
                'UPDATE invoices SET amount_paid = COALESCE(amount_paid, 0) + :amount, '
                . 'balance_due = GREATEST(total - (COALESCE(amount_paid, 0) + :amount), 0) '
                . 'WHERE id = :id'
            )->execute([
                'amount' => $amount,
                'id' => $invoiceId,
            ]);
        }

        $balanceStmt = $pdo->prepare('SELECT balance_due FROM invoices WHERE id = :id');
        $balanceStmt->execute(['id' => $invoiceId]);
        $balance = (float) ($balanceStmt->fetch(PDO::FETCH_ASSOC)['balance_due'] ?? 0.0);

        $newStatus = $balance <= 0.0 ? 'paid' : 'partial';
        $pdo->prepare('UPDATE invoices SET status = :status WHERE id = :id')->execute([
            'status' => $newStatus,
            'id' => $invoiceId,
        ]);

        if ($newStatus === 'paid') {
            $pdo->prepare('UPDATE invoices SET paid_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $invoiceId]);
        }

        $this->recordLedgerEntry($invoiceId, $provider, $paymentData, $amount);
        $this->log('invoice.balance_synced', $invoiceId, ['status' => $newStatus, 'amount' => $amount]);
    }

    private function fetchInvoice(int $invoiceId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM invoices WHERE id = :id');
        $stmt->execute(['id' => $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $options
     */
    private function resolveInvoiceAmount(array $invoice, array $options): float
    {
        if (isset($options['amount']) && is_numeric($options['amount'])) {
            return (float) $options['amount'];
        }

        $balanceDue = isset($invoice['balance_due']) ? (float) $invoice['balance_due'] : 0.0;
        if ($balanceDue > 0) {
            return $balanceDue;
        }

        return (float) ($invoice['total'] ?? 0.0);
    }

    /**
     * @param array<string, mixed> $paymentData
     */
    private function recordLedgerEntry(int $invoiceId, string $provider, array $paymentData, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $transactionId = $paymentData['transaction_id'] ?? null;
        if ($transactionId === null || $transactionId === '') {
            return;
        }

        $invoice = $this->fetchInvoice($invoiceId);
        if ($invoice === null) {
            return;
        }

        $customerName = $this->getCustomerName((int) ($invoice['customer_id'] ?? 0));
        $receiptUrl = $paymentData['receipt_url'] ?? null;

        $description = sprintf(
            'Payment received via %s. Transaction %s.',
            strtoupper($provider),
            $transactionId
        );

        if ($receiptUrl) {
            $description .= ' Receipt: ' . $receiptUrl;
        }

        $entryService = new \App\Services\Financial\FinancialEntryService($this->connection, $this->audit);
        $entryService->create([
            'type' => 'income',
            'category' => 'Invoice Payment',
            'reference' => 'invoice-' . $invoiceId,
            'purchase_order' => 'invoice',
            'amount' => $amount,
            'entry_date' => date('Y-m-d'),
            'vendor' => $customerName ?: 'Customer',
            'description' => $description,
            'attachment_path' => $receiptUrl ?: null,
            'idempotency_key' => 'invoice-payment-' . $invoiceId . '-' . $transactionId,
        ], 0);
    }

    private function getCustomerName(int $customerId): ?string
    {
        if ($customerId === 0) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare('SELECT CONCAT(first_name, " ", last_name) AS name FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? trim((string) ($row['name'] ?? '')) : null;
    }

    private function log(string $action, int $entityId, array $payload = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($action, 'invoice', $entityId, null, $payload));
    }
}
