<?php

namespace App\Services\Invoice;

use App\Database\Connection;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class PaymentProcessingService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createCheckoutSession(int $invoiceId, string $provider, array $payload = []): string
    {
        $invoice = $this->fetchInvoice($invoiceId);
        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice not found');
        }

        $token = bin2hex(random_bytes(12));
        $this->log('payment.checkout_created', $invoiceId, ['provider' => $provider, 'payload' => $payload]);

        return "https://checkout.example.com/{$provider}/{$token}?invoice={$invoiceId}";
    }

    /**
     * @param array<string, mixed> $webhook
     */
    public function handleWebhook(string $provider, array $webhook): bool
    {
        if (!isset($webhook['invoice_id'], $webhook['status'])) {
            return false;
        }

        $invoiceId = (int) $webhook['invoice_id'];
        $status = (string) $webhook['status'];
        $amount = (float) ($webhook['amount'] ?? 0);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payments (invoice_id, amount, method, reference, status, metadata) ' .
            'VALUES (:invoice_id, :amount, :method, :reference, :status, :metadata)'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'method' => $provider,
            'reference' => $webhook['transaction_id'] ?? null,
            'status' => $status,
            'metadata' => json_encode($webhook),
        ]);

        $this->syncInvoiceStatus($invoiceId, $status, $amount);
        $this->log('payment.webhook', $invoiceId, ['provider' => $provider, 'status' => $status, 'amount' => $amount]);

        return true;
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
