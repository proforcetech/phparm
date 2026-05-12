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
    private bool $isRecoveringWebhookEvents = false;
    private ?bool $hasWebhookEventStore = null;

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
    public function handleWebhook(
        string $provider,
        array $payload,
        string $signature = '',
        ?string $rawBody = null,
        ?string $requestUrl = null
    ): array
    {
        try {
            $gateway = $this->gatewayFactory->create($provider);
            $webhookData = $gateway->handleWebhook($payload, $signature, $rawBody, $requestUrl);

            $invoiceId = $this->resolveInvoiceIdFromWebhook($provider, $webhookData);
            if ($invoiceId > 0) {
                $webhookData['invoice_id'] = $invoiceId;
            }

            if ($invoiceId > 0 && isset($webhookData['status'])) {
                if ($this->isRefundWebhook($webhookData)) {
                    $this->recordRefund($invoiceId, $provider, $webhookData);
                } else {
                    $this->recordPayment($invoiceId, $provider, $webhookData);
                }

                $this->log('payment.webhook', $invoiceId, [
                    'provider' => $provider,
                    'event_type' => $webhookData['event_type'] ?? 'unknown',
                    'status' => $webhookData['status'],
                ]);
                $this->recordWebhookEvent(
                    $provider,
                    $webhookData,
                    $invoiceId,
                    $this->isRecoveringWebhookEvents ? 'recovered' : 'processed'
                );
            } elseif (($webhookData['handled'] ?? false) === true) {
                $this->recordWebhookEvent($provider, $webhookData, null, 'unmatched');
                $this->log('payment.webhook_unmatched', 0, [
                    'provider' => $provider,
                    'event_type' => $webhookData['event_type'] ?? 'unknown',
                    'transaction_id' => $webhookData['transaction_id'] ?? null,
                    'reference' => $webhookData['reference'] ?? null,
                    'payment_id' => $webhookData['payment_id'] ?? null,
                    'session_id' => $webhookData['session_id'] ?? null,
                    'order_id' => $webhookData['order_id'] ?? null,
                    'refund_id' => $webhookData['refund_id'] ?? null,
                    'webhook_data' => $webhookData,
                ]);
            } else {
                $this->recordWebhookEvent($provider, $webhookData, $invoiceId > 0 ? $invoiceId : null, 'ignored');
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
        $sessionMetadata = array_merge($sessionData, [
            'invoice_id' => $invoiceId,
            'provider' => $provider,
        ]);

        $sessionId = $sessionData['session_id'] ?? $sessionData['payment_id'] ?? null;
        $checkoutUrl = $sessionData['checkout_url'] ?? null;
        $metadata = json_encode($sessionMetadata);

        // Upsert via SELECT-then-(INSERT|UPDATE). MySQL's ON DUPLICATE KEY
        // UPDATE is not portable; this matches the pattern used elsewhere in
        // the same file (see recordWebhookEvent) and works under PDO_SQLITE
        // for the in-memory test suite.
        $existing = $this->connection->pdo()->prepare(
            'SELECT id FROM payment_sessions WHERE invoice_id = :invoice_id AND provider = :provider'
        );
        $existing->execute(['invoice_id' => $invoiceId, 'provider' => $provider]);
        $existingId = $existing->fetchColumn();

        if ($existingId !== false) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE payment_sessions SET session_id = :session_id, checkout_url = :checkout_url, '
                . 'metadata = :metadata WHERE id = :id'
            );
            $stmt->execute([
                'id' => (int) $existingId,
                'session_id' => $sessionId,
                'checkout_url' => $checkoutUrl,
                'metadata' => $metadata,
            ]);
        } else {
            $stmt = $this->connection->pdo()->prepare(
                'INSERT INTO payment_sessions (invoice_id, provider, session_id, checkout_url, metadata, created_at) '
                . 'VALUES (:invoice_id, :provider, :session_id, :checkout_url, :metadata, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                'invoice_id' => $invoiceId,
                'provider' => $provider,
                'session_id' => $sessionId,
                'checkout_url' => $checkoutUrl,
                'metadata' => $metadata,
            ]);
        }

        if (!$this->isRecoveringWebhookEvents) {
            $this->recoverUnmatchedWebhookEvents($provider, $invoiceId);
        }
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
        $existingPayment = $this->findExistingPaymentRecord($invoiceId, $transactionId, $reference);
        $pdo = $this->connection->pdo();

        if ($existingPayment !== null) {
            $stmt = $pdo->prepare(
                'UPDATE payments SET gateway = :gateway, method = :method, transaction_id = :transaction_id, amount = :amount, '
                . 'reference = :reference, status = :status, metadata = :metadata WHERE id = :id'
            );
            $stmt->execute([
                'id' => $existingPayment['id'],
                'amount' => $amount,
                'gateway' => $provider,
                'method' => $method,
                'transaction_id' => $transactionId,
                'reference' => $reference,
                'status' => $status,
                'metadata' => json_encode($metadata),
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO payments (invoice_id, gateway, method, transaction_id, amount, reference, status, metadata, paid_at, created_at) '
                . 'VALUES (:invoice_id, :gateway, :method, :transaction_id, :amount, :reference, :status, :metadata, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
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
        }

        // Update invoice status based on payment
        $this->syncInvoiceStatus($invoiceId, $status, $amount, $provider, $paymentData, $existingPayment);

        if (!$this->isRecoveringWebhookEvents) {
            $this->recoverUnmatchedWebhookEvents($provider, $invoiceId);
        }
    }

    /**
     * Record a refund in the database
     *
     * @param array<string, mixed> $refundData
     */
    private function recordRefund(int $invoiceId, string $provider, array $refundData): void
    {
        $refundId = trim((string) ($refundData['refund_id'] ?? ''));
        $paymentReference = (string) ($refundData['transaction_id'] ?? '');
        $existingRefund = $refundId !== '' ? $this->getRefundById($refundId) : null;
        $pdo = $this->connection->pdo();
        $refundAmount = (float) ($refundData['amount'] ?? 0);
        $refundStatus = (string) ($refundData['status'] ?? 'pending');

        if ($existingRefund !== null) {
            $stmt = $pdo->prepare(
                'UPDATE refunds SET payment_reference = :payment_reference, amount = :amount, reason = :reason, status = :status, metadata = :metadata WHERE id = :id'
            );
            $stmt->execute([
                'id' => $existingRefund['id'],
                'payment_reference' => $paymentReference,
                'amount' => $refundAmount,
                'reason' => $refundData['reason'] ?? '',
                'status' => $refundStatus,
                'metadata' => json_encode($refundData),
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO refunds (invoice_id, payment_reference, refund_id, amount, reason, status, metadata, created_at) '
                . 'VALUES (:invoice_id, :payment_reference, :refund_id, :amount, :reason, :status, :metadata, CURRENT_TIMESTAMP)'
            );

            $stmt->execute([
                'invoice_id' => $invoiceId,
                'payment_reference' => $paymentReference,
                'refund_id' => $refundId,
                'amount' => $refundAmount,
                'reason' => $refundData['reason'] ?? '',
                'status' => $refundStatus,
                'metadata' => json_encode($refundData),
            ]);
        }

        $this->syncInvoiceAfterRefund($invoiceId, $provider, $refundData, $existingRefund);
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
            'SELECT * FROM payments WHERE reference = :reference OR transaction_id = :reference ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['reference' => $transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function getRefundById(string $refundId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM refunds WHERE refund_id = :refund_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['refund_id' => $refundId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function findExistingPaymentRecord(int $invoiceId, mixed $transactionId, mixed $reference): ?array
    {
        $transactionId = $this->normalizeIdentifier($transactionId);
        $reference = $this->normalizeIdentifier($reference);
        if ($transactionId === null && $reference === null) {
            return null;
        }

        $clauses = [];
        $params = ['invoice_id' => $invoiceId];

        if ($transactionId !== null) {
            $clauses[] = '(transaction_id = :transaction_id OR reference = :transaction_id)';
            $params['transaction_id'] = $transactionId;
        }

        if ($reference !== null && $reference !== $transactionId) {
            $clauses[] = '(transaction_id = :reference OR reference = :reference)';
            $params['reference'] = $reference;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM payments WHERE invoice_id = :invoice_id AND (' . implode(' OR ', $clauses) . ') '
            . 'ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $webhookData
     */
    private function resolveInvoiceIdFromWebhook(string $provider, array $webhookData): int
    {
        $invoiceId = (int) ($webhookData['invoice_id'] ?? 0);
        if ($invoiceId > 0) {
            return $invoiceId;
        }

        $referenceInvoiceId = $this->extractInvoiceIdFromReference(
            (string) ($webhookData['reference'] ?? '')
        );
        if ($referenceInvoiceId > 0) {
            return $referenceInvoiceId;
        }

        $transactionId = trim((string) ($webhookData['transaction_id'] ?? ''));
        if ($transactionId !== '') {
            $payment = $this->getPaymentByTransaction($transactionId);
            if ($payment !== null) {
                return (int) ($payment['invoice_id'] ?? 0);
            }
        }

        return $this->findInvoiceIdByPaymentSession($provider, [
            $webhookData['payment_id'] ?? null,
            $webhookData['session_id'] ?? null,
            $webhookData['order_id'] ?? null,
        ]);
    }

    private function extractInvoiceIdFromReference(string $reference): int
    {
        $reference = trim($reference);
        if ($reference === '') {
            return 0;
        }

        if (preg_match('/^(?:invoice_|INV-)(\d+)$/i', $reference, $matches) === 1) {
            return (int) ($matches[1] ?? 0);
        }

        return 0;
    }

    /**
     * @param array<int, mixed> $identifiers
     */
    private function findInvoiceIdByPaymentSession(string $provider, array $identifiers): int
    {
        $lookupValues = [];
        foreach ($identifiers as $identifier) {
            $value = trim((string) $identifier);
            if ($value !== '') {
                $lookupValues[$value] = true;
            }
        }

        if ($lookupValues === []) {
            return 0;
        }

        $sessionStmt = $this->connection->pdo()->prepare(
            'SELECT invoice_id FROM payment_sessions WHERE provider = :provider AND session_id = :session_id '
            . 'ORDER BY id DESC LIMIT 1'
        );

        foreach (array_keys($lookupValues) as $value) {
            $sessionStmt->execute([
                'provider' => $provider,
                'session_id' => $value,
            ]);
            $row = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return (int) ($row['invoice_id'] ?? 0);
            }
        }

        $metadataClauseParts = [];
        $params = ['provider' => $provider];
        $index = 0;
        foreach (array_keys($lookupValues) as $value) {
            $param = 'metadata_' . $index;
            $metadataClauseParts[] = 'metadata LIKE :' . $param;
            $params[$param] = '%"' . str_replace(['%', '_'], ['\\%', '\\_'], $value) . '"%';
            $index++;
        }

        if ($metadataClauseParts === []) {
            return 0;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT invoice_id, metadata FROM payment_sessions WHERE provider = :provider AND ('
            . implode(' OR ', $metadataClauseParts)
            . ') ORDER BY id DESC'
        );
        $stmt->execute($params);

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
            if (!is_array($metadata)) {
                continue;
            }

            foreach (['session_id', 'payment_id', 'order_id'] as $key) {
                $value = trim((string) ($metadata[$key] ?? ''));
                if ($value !== '' && isset($lookupValues[$value])) {
                    return (int) ($row['invoice_id'] ?? 0);
                }
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $webhookData
     */
    private function isRefundWebhook(array $webhookData): bool
    {
        $status = strtolower((string) ($webhookData['status'] ?? ''));
        if ($status === 'refunded') {
            return true;
        }

        return trim((string) ($webhookData['refund_id'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $paymentData
     */
    private function syncInvoiceStatus(
        int $invoiceId,
        string $status,
        float $amount,
        string $provider,
        array $paymentData,
        ?array $existingPayment = null
    ): void
    {
        $pdo = $this->connection->pdo();
        $previousStatus = strtolower((string) ($existingPayment['status'] ?? ''));
        $previousAmount = (float) ($existingPayment['amount'] ?? 0);

        if ($status !== 'succeeded') {
            if ($previousStatus === 'succeeded') {
                $this->log('invoice.balance_sync_skipped', $invoiceId, [
                    'provider' => $provider,
                    'status' => $status,
                    'reason' => 'existing_successful_payment',
                    'transaction_id' => $paymentData['transaction_id'] ?? null,
                ]);
                return;
            }

            $pdo->prepare('UPDATE invoices SET status = :status WHERE id = :id')->execute([
                'status' => 'pending',
                'id' => $invoiceId,
            ]);
            $this->log('invoice.balance_synced', $invoiceId, ['status' => $status, 'amount' => $amount]);
            return;
        }

        $deltaAmount = $previousStatus === 'succeeded'
            ? max($amount - $previousAmount, 0)
            : $amount;

        if ($deltaAmount > 0) {
            $pdo->prepare(
                'UPDATE invoices SET amount_paid = COALESCE(amount_paid, 0) + :amount, '
                . 'balance_due = GREATEST(total - (COALESCE(amount_paid, 0) + :amount), 0) '
                . 'WHERE id = :id'
            )->execute([
                'amount' => $deltaAmount,
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

        if ($deltaAmount > 0) {
            $this->recordLedgerEntry($invoiceId, $provider, $paymentData, $deltaAmount);
        }

        $this->log('invoice.balance_synced', $invoiceId, ['status' => $newStatus, 'amount' => $deltaAmount]);
    }

    /**
     * @param array<string, mixed> $refundData
     * @param array<string, mixed>|null $existingRefund
     */
    private function syncInvoiceAfterRefund(
        int $invoiceId,
        string $provider,
        array $refundData,
        ?array $existingRefund = null
    ): void {
        $refundStatus = strtolower((string) ($refundData['status'] ?? 'pending'));
        if ($refundStatus !== 'refunded') {
            return;
        }

        $refundAmount = (float) ($refundData['amount'] ?? 0);
        $previousStatus = strtolower((string) ($existingRefund['status'] ?? ''));
        $previousAmount = (float) ($existingRefund['amount'] ?? 0);
        $deltaAmount = $previousStatus === 'refunded'
            ? max($refundAmount - $previousAmount, 0)
            : $refundAmount;

        if ($deltaAmount <= 0) {
            return;
        }

        $invoice = $this->fetchInvoice($invoiceId);
        if ($invoice === null) {
            return;
        }

        $total = (float) ($invoice['total'] ?? 0);
        $currentAmountPaid = (float) ($invoice['amount_paid'] ?? 0);
        $newAmountPaid = max($currentAmountPaid - $deltaAmount, 0.0);
        $newBalanceDue = max($total - $newAmountPaid, 0.0);
        $newStatus = 'pending';

        if ($newAmountPaid <= 0.0) {
            $newStatus = 'pending';
        } elseif ($newBalanceDue <= 0.0) {
            $newStatus = 'paid';
        } else {
            $newStatus = 'partial';
        }

        $pdo = $this->connection->pdo();
        $pdo->prepare(
            'UPDATE invoices SET amount_paid = :amount_paid, balance_due = :balance_due, status = :status, '
            . 'paid_at = CASE WHEN :status = \'paid\' THEN COALESCE(paid_at, CURRENT_TIMESTAMP) ELSE NULL END '
            . 'WHERE id = :id'
        )->execute([
            'amount_paid' => $newAmountPaid,
            'balance_due' => $newBalanceDue,
            'status' => $newStatus,
            'id' => $invoiceId,
        ]);

        $this->log('invoice.refund_balance_synced', $invoiceId, [
            'provider' => $provider,
            'status' => $newStatus,
            'refund_amount' => $deltaAmount,
            'transaction_id' => $refundData['transaction_id'] ?? null,
            'refund_id' => $refundData['refund_id'] ?? null,
        ]);
    }

    private function recoverUnmatchedWebhookEvents(string $provider, int $invoiceId): void
    {
        if ($this->isRecoveringWebhookEvents) {
            return;
        }
        $unmatchedRows = $this->fetchRecoverableWebhookEvents($provider);

        $this->isRecoveringWebhookEvents = true;

        try {
            foreach ($unmatchedRows as $row) {
                $webhookData = $row['webhook_data'] ?? null;
                if (!is_array($webhookData)) {
                    continue;
                }

                $resolvedInvoiceId = $this->resolveInvoiceIdFromWebhook($provider, $webhookData);
                if ($resolvedInvoiceId !== $invoiceId || !isset($webhookData['status'])) {
                    continue;
                }

                if ($this->isRefundWebhook($webhookData)) {
                    $this->recordRefund($invoiceId, $provider, $webhookData);
                } else {
                    $this->recordPayment($invoiceId, $provider, $webhookData);
                }

                $this->log('payment.webhook_recovered', $invoiceId, [
                    'provider' => $provider,
                    'unmatched_audit_id' => $row['audit_log_id'] ?? null,
                    'webhook_event_id' => $row['webhook_event_id'] ?? null,
                    'event_type' => $webhookData['event_type'] ?? 'unknown',
                    'transaction_id' => $webhookData['transaction_id'] ?? null,
                    'refund_id' => $webhookData['refund_id'] ?? null,
                ]);

                $this->recordWebhookEvent($provider, $webhookData, $invoiceId, 'recovered');
            }
        } finally {
            $this->isRecoveringWebhookEvents = false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecoverableWebhookEvents(string $provider): array
    {
        if ($this->hasWebhookEventStore()) {
            return $this->fetchRecoverableWebhookEventsFromStore($provider);
        }

        return $this->fetchRecoverableWebhookEventsFromAuditLogs($provider);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecoverableWebhookEventsFromStore(string $provider): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, payload FROM payment_webhook_events WHERE provider = :provider '
            . 'AND status = :status ORDER BY id ASC'
        );
        $stmt->execute([
            'provider' => $provider,
            'status' => 'unmatched',
        ]);

        $rows = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }

            $rows[] = [
                'webhook_event_id' => (int) ($row['id'] ?? 0),
                'webhook_data' => $payload,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecoverableWebhookEventsFromAuditLogs(string $provider): array
    {
        if ($this->audit === null) {
            return [];
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, event, context FROM audit_logs WHERE entity_type = :entity_type '
            . 'AND event IN (:unmatched_event, :recovered_event) ORDER BY id ASC'
        );
        $stmt->execute([
            'entity_type' => 'invoice',
            'unmatched_event' => 'payment.webhook_unmatched',
            'recovered_event' => 'payment.webhook_recovered',
        ]);

        $recoveredAuditIds = [];
        $unmatchedRows = [];

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $context = json_decode((string) ($row['context'] ?? ''), true);
            if (!is_array($context)) {
                continue;
            }

            if (($row['event'] ?? '') === 'payment.webhook_recovered') {
                $recoveredAuditId = (int) ($context['unmatched_audit_id'] ?? 0);
                if ($recoveredAuditId > 0) {
                    $recoveredAuditIds[$recoveredAuditId] = true;
                }
                continue;
            }

            if (($context['provider'] ?? '') !== $provider) {
                continue;
            }

            $unmatchedRows[] = [
                'audit_log_id' => (int) ($row['id'] ?? 0),
                'webhook_data' => $context['webhook_data'] ?? null,
            ];
        }

        return array_values(array_filter(
            $unmatchedRows,
            static fn (array $row): bool => !isset($recoveredAuditIds[$row['audit_log_id'] ?? 0])
        ));
    }

    /**
     * @param array<string, mixed> $webhookData
     */
    private function recordWebhookEvent(string $provider, array $webhookData, ?int $invoiceId, string $status): void
    {
        if (!$this->hasWebhookEventStore()) {
            return;
        }

        $dedupeKey = $this->buildWebhookEventDedupeKey($provider, $webhookData);
        $payload = json_encode($webhookData);
        $existing = $this->getWebhookEventByDedupeKey($dedupeKey);
        $normalizedStatus = $this->resolveWebhookEventStatus(
            (string) ($existing['status'] ?? ''),
            $status
        );

        $data = [
            'provider' => $provider,
            'provider_event_id' => $this->normalizeIdentifier($webhookData['provider_event_id'] ?? null),
            'dedupe_key' => $dedupeKey,
            'event_type' => (string) ($webhookData['event_type'] ?? 'unknown'),
            'invoice_id' => $invoiceId ?: null,
            'transaction_id' => $this->normalizeIdentifier($webhookData['transaction_id'] ?? null),
            'refund_id' => $this->normalizeIdentifier($webhookData['refund_id'] ?? null),
            'payment_id' => $this->normalizeIdentifier($webhookData['payment_id'] ?? null),
            'session_id' => $this->normalizeIdentifier($webhookData['session_id'] ?? null),
            'order_id' => $this->normalizeIdentifier($webhookData['order_id'] ?? null),
            'status' => $normalizedStatus,
            'payload' => $payload,
        ];

        // PDO_SQLite has two strictness differences from PDO_MySQL's emulated
        // prepares: (1) it rejects re-use of the same named placeholder in a
        // statement, and (2) it rejects extra named bindings that aren't in
        // the SQL. So we use distinct placeholder names per occurrence and
        // build each statement's bindings from only the keys it references.
        // The SQL is otherwise identical between drivers.
        $invoiceIdMatch = $data['invoice_id'];
        $statusIsProcessed = $data['status'];
        $statusIsRecovered = $data['status'];

        if ($existing !== null) {
            $updateBindings = [
                'provider_event_id' => $data['provider_event_id'],
                'event_type' => $data['event_type'],
                'invoice_id' => $data['invoice_id'],
                'transaction_id' => $data['transaction_id'],
                'refund_id' => $data['refund_id'],
                'payment_id' => $data['payment_id'],
                'session_id' => $data['session_id'],
                'order_id' => $data['order_id'],
                'status' => $data['status'],
                'payload' => $data['payload'],
                'invoice_id_match' => $invoiceIdMatch,
                'status_is_processed' => $statusIsProcessed,
                'status_is_recovered' => $statusIsRecovered,
                'id' => $existing['id'],
            ];

            $stmt = $this->connection->pdo()->prepare(
                'UPDATE payment_webhook_events SET provider_event_id = :provider_event_id, event_type = :event_type, '
                . 'invoice_id = COALESCE(:invoice_id, invoice_id), transaction_id = :transaction_id, refund_id = :refund_id, '
                . 'payment_id = :payment_id, session_id = :session_id, order_id = :order_id, status = :status, payload = :payload, '
                . 'attempts = attempts + 1, matched_at = CASE WHEN COALESCE(:invoice_id_match, 0) > 0 THEN CURRENT_TIMESTAMP ELSE matched_at END, '
                . 'processed_at = CASE WHEN :status_is_processed = \'processed\' THEN CURRENT_TIMESTAMP ELSE processed_at END, '
                . 'recovered_at = CASE WHEN :status_is_recovered = \'recovered\' THEN CURRENT_TIMESTAMP ELSE recovered_at END, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $stmt->execute($updateBindings);
            return;
        }

        $insertBindings = $data + [
            'invoice_id_match' => $invoiceIdMatch,
            'status_is_processed' => $statusIsProcessed,
            'status_is_recovered' => $statusIsRecovered,
        ];

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payment_webhook_events (provider, provider_event_id, dedupe_key, event_type, invoice_id, '
            . 'transaction_id, refund_id, payment_id, session_id, order_id, status, payload, attempts, matched_at, '
            . 'processed_at, recovered_at, created_at, updated_at) VALUES '
            . '(:provider, :provider_event_id, :dedupe_key, :event_type, :invoice_id, :transaction_id, :refund_id, '
            . ':payment_id, :session_id, :order_id, :status, :payload, 1, '
            . 'CASE WHEN COALESCE(:invoice_id_match, 0) > 0 THEN CURRENT_TIMESTAMP ELSE NULL END, '
            . 'CASE WHEN :status_is_processed = \'processed\' THEN CURRENT_TIMESTAMP ELSE NULL END, '
            . 'CASE WHEN :status_is_recovered = \'recovered\' THEN CURRENT_TIMESTAMP ELSE NULL END, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute($insertBindings);
    }

    private function hasWebhookEventStore(): bool
    {
        if ($this->hasWebhookEventStore !== null) {
            return $this->hasWebhookEventStore;
        }

        $pdo = $this->connection->pdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1"
                );
                $stmt->execute(['table' => 'payment_webhook_events']);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() '
                    . 'AND table_name = :table LIMIT 1'
                );
                $stmt->execute(['table' => 'payment_webhook_events']);
            }

            $this->hasWebhookEventStore = $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            $this->hasWebhookEventStore = false;
        }

        return $this->hasWebhookEventStore;
    }

    private function getWebhookEventByDedupeKey(string $dedupeKey): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM payment_webhook_events WHERE dedupe_key = :dedupe_key LIMIT 1'
        );
        $stmt->execute(['dedupe_key' => $dedupeKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $webhookData
     */
    private function buildWebhookEventDedupeKey(string $provider, array $webhookData): string
    {
        $providerEventId = $this->normalizeIdentifier($webhookData['provider_event_id'] ?? null);
        if ($providerEventId !== null) {
            return $provider . ':event:' . $providerEventId;
        }

        $parts = [
            $provider,
            (string) ($webhookData['event_type'] ?? 'unknown'),
            $this->normalizeIdentifier($webhookData['transaction_id'] ?? null) ?? '',
            $this->normalizeIdentifier($webhookData['refund_id'] ?? null) ?? '',
            $this->normalizeIdentifier($webhookData['payment_id'] ?? null) ?? '',
            $this->normalizeIdentifier($webhookData['session_id'] ?? null) ?? '',
            $this->normalizeIdentifier($webhookData['order_id'] ?? null) ?? '',
            $this->normalizeIdentifier($webhookData['status'] ?? null) ?? '',
            (string) ((float) ($webhookData['amount'] ?? 0)),
        ];

        return $provider . ':hash:' . sha1(implode('|', $parts));
    }

    private function resolveWebhookEventStatus(string $existingStatus, string $newStatus): string
    {
        $existingStatus = strtolower(trim($existingStatus));
        $newStatus = strtolower(trim($newStatus));
        $priority = [
            'ignored' => 0,
            'unmatched' => 1,
            'processed' => 2,
            'recovered' => 3,
        ];

        if (!isset($priority[$existingStatus])) {
            return $newStatus;
        }

        if (!isset($priority[$newStatus])) {
            return $existingStatus;
        }

        return $priority[$newStatus] >= $priority[$existingStatus] ? $newStatus : $existingStatus;
    }

    private function normalizeIdentifier(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
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
