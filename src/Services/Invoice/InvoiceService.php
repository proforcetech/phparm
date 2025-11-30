<?php

namespace App\Services\Invoice;

use App\Database\Connection;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use Throwable;

class InvoiceService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    public function createFromEstimate(int $estimateId, array $jobIds, int $actorId): ?Invoice
    {
        $estimate = $this->fetchEstimate($estimateId);
        if ($estimate === null) {
            return null;
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $invoiceId = $this->insertInvoice([
                'customer_id' => $estimate->customer_id,
                'vehicle_id' => $estimate->vehicle_id,
                'number' => $this->generateInvoiceNumber(),
                'status' => 'pending',
                'estimate_id' => $estimateId,
            ]);

            $totals = $this->copyEstimateJobs($invoiceId, $jobIds, $estimateId);
            $this->updateTotals($invoiceId, $totals);

            $pdo->commit();
            $invoice = $this->fetchInvoice($invoiceId);
            $this->log('invoice.created_from_estimate', $invoiceId, $actorId, [
                'estimate_id' => $estimateId,
                'jobs' => $jobIds,
                'totals' => $totals,
            ]);

            return $invoice;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createStandalone(array $payload, int $actorId): Invoice
    {
        $required = ['customer_id', 'number', 'items'];
        foreach ($required as $field) {
            if (!isset($payload[$field])) {
                throw new InvalidArgumentException("Missing {$field}");
            }
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $invoiceId = $this->insertInvoice([
                'customer_id' => $payload['customer_id'],
                'vehicle_id' => $payload['vehicle_id'] ?? null,
                'number' => $payload['number'],
                'status' => 'pending',
                'estimate_id' => $payload['estimate_id'] ?? null,
                'due_date' => $payload['due_date'] ?? null,
                'notes' => $payload['notes'] ?? null,
            ]);

            $totals = $this->persistItems($invoiceId, $payload['items'], $payload['tax_rate'] ?? 0.0);
            $this->updateTotals($invoiceId, $totals);

            $pdo->commit();
            $invoice = $this->fetchInvoice($invoiceId);
            $this->log('invoice.created', $invoiceId, $actorId, ['payload' => $payload, 'totals' => $totals]);

            return $invoice ?? new Invoice(['id' => $invoiceId]);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function applyStatus(int $invoiceId, string $status, ?int $actorId = null): bool
    {
        $allowed = ['pending', 'sent', 'partial', 'paid', 'void', 'uncollectible'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid status');
        }

        $stmt = $this->connection->pdo()->prepare('UPDATE invoices SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $invoiceId]);

        $updated = $stmt->rowCount() > 0;
        if ($updated) {
            $this->log('invoice.status_changed', $invoiceId, $actorId, ['status' => $status]);
        }

        return $updated;
    }

    public function recordPayment(int $invoiceId, array $payload, ?int $actorId = null): Payment
    {
        $required = ['amount', 'method'];
        foreach ($required as $field) {
            if (!isset($payload[$field])) {
                throw new InvalidArgumentException("Missing {$field}");
            }
        }

        $paymentId = $this->insertPayment($invoiceId, $payload);
        $this->syncInvoiceBalance($invoiceId);
        $this->log('payment.recorded', $invoiceId, $actorId, ['payment_id' => $paymentId, 'payload' => $payload]);

        return new Payment([
            'id' => $paymentId,
            'invoice_id' => $invoiceId,
            'amount' => $payload['amount'],
            'method' => $payload['method'],
        ]);
    }

    public function getPublicView(int $invoiceId): ?Invoice
    {
        return $this->fetchInvoice($invoiceId);
    }

    public function generatePayUrl(int $invoiceId, string $provider): string
    {
        $token = bin2hex(random_bytes(16));
        return "https://pay.example.com/{$provider}/invoice/{$invoiceId}?token={$token}";
    }

    /**
     * @param array<int, int> $jobIds
     * @return array<string, float>
     */
    private function copyEstimateJobs(int $invoiceId, array $jobIds, int $estimateId): array
    {
        $itemsStmt = $this->connection->pdo()->prepare(
            'SELECT ej.title, ei.description, ei.quantity, ei.unit_price, ei.taxable ' .
            'FROM estimate_jobs ej JOIN estimate_items ei ON ej.id = ei.estimate_job_id ' .
            'WHERE ej.estimate_id = ? AND ej.id IN (' . implode(',', array_fill(0, count($jobIds), '?')) . ')'
        );
        $itemsStmt->execute(array_merge([$estimateId], $jobIds));

        $totals = ['subtotal' => 0.0, 'tax' => 0.0, 'total' => 0.0];
        while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
            $lineTotal = ((float) $row['quantity']) * ((float) $row['unit_price']);
            $tax = $row['taxable'] ? $lineTotal * 0.1 : 0.0;
            $this->insertInvoiceItem($invoiceId, [
                'description' => $row['title'] . ' - ' . $row['description'],
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'taxable' => $row['taxable'],
            ]);
            $totals['subtotal'] += $lineTotal;
            $totals['tax'] += $tax;
        }

        $totals['total'] = $totals['subtotal'] + $totals['tax'];

        return $totals;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, float>
     */
    private function persistItems(int $invoiceId, array $items, float $taxRate): array
    {
        $totals = ['subtotal' => 0.0, 'tax' => 0.0, 'total' => 0.0];
        foreach ($items as $item) {
            $lineTotal = ((float) ($item['quantity'] ?? 0)) * ((float) ($item['unit_price'] ?? 0));
            $taxable = (bool) ($item['taxable'] ?? false);
            $tax = $taxable ? $lineTotal * $taxRate : 0.0;
            $this->insertInvoiceItem($invoiceId, [
                'description' => $item['description'] ?? 'Line Item',
                'quantity' => $item['quantity'] ?? 0,
                'unit_price' => $item['unit_price'] ?? 0.0,
                'taxable' => $taxable,
            ]);
            $totals['subtotal'] += $lineTotal;
            $totals['tax'] += $tax;
        }

        $totals['total'] = $totals['subtotal'] + $totals['tax'];

        return $totals;
    }

    private function insertInvoice(array $payload): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO invoices (customer_id, vehicle_id, number, status, estimate_id, due_date, notes, subtotal, tax, total) '
            . 'VALUES (:customer_id, :vehicle_id, :number, :status, :estimate_id, :due_date, :notes, 0, 0, 0)'
        );
        $stmt->execute([
            'customer_id' => $payload['customer_id'],
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            'number' => $payload['number'],
            'status' => $payload['status'],
            'estimate_id' => $payload['estimate_id'] ?? null,
            'due_date' => $payload['due_date'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertInvoiceItem(int $invoiceId, array $payload): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, taxable) '
            . 'VALUES (:invoice_id, :description, :quantity, :unit_price, :taxable)'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'description' => $payload['description'],
            'quantity' => $payload['quantity'],
            'unit_price' => $payload['unit_price'],
            'taxable' => $payload['taxable'] ? 1 : 0,
        ]);
    }

    private function updateTotals(int $invoiceId, array $totals): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE invoices SET subtotal = :subtotal, tax = :tax, total = :total WHERE id = :id'
        );
        $stmt->execute([
            'subtotal' => $totals['subtotal'],
            'tax' => $totals['tax'],
            'total' => $totals['total'],
            'id' => $invoiceId,
        ]);
    }

    private function syncInvoiceBalance(int $invoiceId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE invoices SET status = CASE WHEN total <= (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :invoice_id) '
            . 'THEN "paid" ELSE "partial" END WHERE id = :invoice_id'
        );
        $stmt->execute(['invoice_id' => $invoiceId]);
    }

    private function insertPayment(int $invoiceId, array $payload): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payments (invoice_id, amount, method, reference, status, metadata) ' .
            'VALUES (:invoice_id, :amount, :method, :reference, :status, :metadata)'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'amount' => $payload['amount'],
            'method' => $payload['method'],
            'reference' => $payload['reference'] ?? null,
            'status' => $payload['status'] ?? 'succeeded',
            'metadata' => json_encode($payload['metadata'] ?? []),
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    private function fetchInvoice(int $id): ?Invoice
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM invoices WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new Invoice($row) : null;
    }

    private function fetchEstimate(int $id): ?Estimate
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM estimates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new Estimate($row) : null;
    }

    private function generateInvoiceNumber(): string
    {
        return 'INV-' . date('Ymd-His') . '-' . random_int(1000, 9999);
    }

    private function log(string $action, int $entityId, ?int $actorId, array $payload = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($action, 'invoice', $entityId, $actorId, $payload));
    }
}
