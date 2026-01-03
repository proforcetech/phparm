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

    /**
     * @var string[]
     */
    private array $allowedStatuses = ['pending', 'sent', 'partial', 'paid', 'void', 'uncollectible'];
    private int $publicTtlDays = 30;

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

        if (strtolower($estimate->status) !== 'approved') {
            throw new InvalidArgumentException('Estimate must be approved before conversion');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $invoiceId = $this->insertInvoice([
                'customer_id' => $estimate->customer_id,
                'vehicle_id' => $estimate->vehicle_id,
                'is_mobile' => $estimate->is_mobile,
                'number' => $this->generateInvoiceNumber(),
                'status' => 'pending',
                'estimate_id' => $estimateId,
                'issue_date' => date('Y-m-d'),
                'public_token' => $this->generatePublicToken(),
                'public_token_expires_at' => $this->calculatePublicExpiry(),
            ]);

            $totals = $this->copyEstimateJobs($invoiceId, $jobIds, $estimateId);
            $totals = $this->appendEstimateExtras($invoiceId, $estimate, $totals);
            $this->updateTotals($invoiceId, $totals);
            $this->syncInvoiceBalance($invoiceId);

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
                'is_mobile' => !empty($payload['is_mobile']),
                'number' => $payload['number'],
                'status' => 'pending',
                'estimate_id' => $payload['estimate_id'] ?? null,
                'due_date' => $payload['due_date'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'public_token' => $payload['public_token'] ?? $this->generatePublicToken(),
                'public_token_expires_at' => $payload['public_token_expires_at'] ?? $this->calculatePublicExpiry(),
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
        if (!in_array($status, $this->allowedStatuses, true)) {
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

    public function updateStatus(int $invoiceId, string $status, ?int $actorId = null): ?Invoice
    {
        $updated = $this->applyStatus($invoiceId, $status, $actorId);

        if (!$updated) {
            return null;
        }

        $this->syncInvoiceBalance($invoiceId);

        return $this->findById($invoiceId);
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

    public function getPublicView(string $token): ?Invoice
    {
        return $this->findByPublicToken($token);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, Invoice>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = [];
        $bindings = [];
        $joins = '';

        if (!empty($filters['status']) && in_array($filters['status'], $this->allowedStatuses, true)) {
            $clauses[] = 'invoices.status = :status';
            $bindings['status'] = $filters['status'];
        }

        if (!empty($filters['customer_id'])) {
            $clauses[] = 'invoices.customer_id = :customer_id';
            $bindings['customer_id'] = (int) $filters['customer_id'];
        }

        if (!empty($filters['technician_id'])) {
            $joins = ' LEFT JOIN estimates e ON e.id = invoices.estimate_id';
            $clauses[] = 'e.technician_id = :technician_id';
            $bindings['technician_id'] = (int) $filters['technician_id'];
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $sql = 'SELECT invoices.* FROM invoices' . $joins . ' ' . $where . ' ORDER BY invoices.issue_date DESC, invoices.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function ($row) {
            $row['is_mobile'] = isset($row['is_mobile']) ? (bool) $row['is_mobile'] : false;

            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        return array_map(static fn ($row) => new Invoice($row), $rows);
    }

    public function findById(int $id): ?Invoice
    {
        return $this->fetchInvoice($id);
    }

    public function findByPublicToken(string $token): ?Invoice
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM invoices WHERE public_token = :token'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['is_mobile'] = isset($row['is_mobile']) ? (bool) $row['is_mobile'] : false;

        $invoice = new Invoice($row);
        if ($this->isPublicTokenExpired($invoice)) {
            return null;
        }

        return $invoice;
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
        if (empty($jobIds)) {
            throw new InvalidArgumentException('At least one job must be selected for invoicing');
        }

        $itemsStmt = $this->connection->pdo()->prepare(
            'SELECT ej.title, ei.description, ei.quantity, ei.unit_price, ei.taxable, ei.type ' .
            'FROM estimate_jobs ej JOIN estimate_items ei ON ej.id = ei.estimate_job_id ' .
            'WHERE ej.estimate_id = ? AND ej.id IN (' . implode(',', array_fill(0, count($jobIds), '?')) . ')'
        );
        $itemsStmt->execute(array_merge([$estimateId], $jobIds));

        $totals = ['subtotal' => 0.0, 'tax' => 0.0, 'total' => 0.0];
        while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
            $lineTotal = ((float) $row['quantity']) * ((float) $row['unit_price']);
            $tax = $row['taxable'] ? $lineTotal * 0.1 : 0.0;
            $this->insertInvoiceItem($invoiceId, [
                'type' => $row['type'] ?? 'service',
                'description' => $row['title'] . ' - ' . $row['description'],
                'quantity' => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'taxable' => $row['taxable'],
                'line_total' => $lineTotal,
            ]);
            $totals['subtotal'] += $lineTotal;
            $totals['tax'] += $tax;
        }

        $totals['total'] = $totals['subtotal'] + $totals['tax'];

        return $totals;
    }

    private function appendEstimateExtras(int $invoiceId, Estimate $estimate, array $totals): array
    {
        if (!empty($estimate->call_out_fee)) {
            $totals['subtotal'] += (float) $estimate->call_out_fee;
            $this->insertInvoiceItem($invoiceId, [
                'type' => 'fee',
                'description' => 'Call-out Fee',
                'quantity' => 1,
                'unit_price' => $estimate->call_out_fee,
                'taxable' => false,
                'line_total' => (float) $estimate->call_out_fee,
            ]);
        }

        if (!empty($estimate->mileage_total)) {
            $totals['subtotal'] += (float) $estimate->mileage_total;
            $this->insertInvoiceItem($invoiceId, [
                'type' => 'mileage',
                'description' => 'Mileage',
                'quantity' => 1,
                'unit_price' => $estimate->mileage_total,
                'taxable' => false,
                'line_total' => (float) $estimate->mileage_total,
            ]);
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
                'type' => $item['type'] ?? 'line_item',
                'description' => $item['description'] ?? 'Line Item',
                'quantity' => $item['quantity'] ?? 0,
                'unit_price' => $item['unit_price'] ?? 0.0,
                'taxable' => $taxable,
                'line_total' => $lineTotal,
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
            'INSERT INTO invoices (customer_id, vehicle_id, is_mobile, number, status, estimate_id, issue_date, due_date, notes, subtotal, tax, total, amount_paid, balance_due, public_token, public_token_expires_at) '
            . 'VALUES (:customer_id, :vehicle_id, :is_mobile, :number, :status, :estimate_id, :issue_date, :due_date, :notes, 0, 0, 0, 0, 0, :public_token, :public_token_expires_at)'
        );
        $stmt->execute([
            'customer_id' => $payload['customer_id'],
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            'is_mobile' => !empty($payload['is_mobile']) ? 1 : 0,
            'number' => $payload['number'],
            'status' => $payload['status'],
            'estimate_id' => $payload['estimate_id'] ?? null,
            'issue_date' => $payload['issue_date'] ?? date('Y-m-d'),
            'due_date' => $payload['due_date'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'public_token' => $payload['public_token'] ?? $this->generatePublicToken(),
            'public_token_expires_at' => $payload['public_token_expires_at'] ?? $this->calculatePublicExpiry(),
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertInvoiceItem(int $invoiceId, array $payload): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO invoice_items (invoice_id, type, description, quantity, unit_price, taxable, line_total) '
            . 'VALUES (:invoice_id, :type, :description, :quantity, :unit_price, :taxable, :line_total)'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'type' => $payload['type'],
            'description' => $payload['description'],
            'quantity' => $payload['quantity'],
            'unit_price' => $payload['unit_price'],
            'taxable' => $payload['taxable'] ? 1 : 0,
            'line_total' => $payload['line_total'],
        ]);
    }

    private function updateTotals(int $invoiceId, array $totals): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE invoices SET subtotal = :subtotal, tax = :tax, total = :total, balance_due = :balance_due WHERE id = :id'
        );
        $stmt->execute([
            'subtotal' => $totals['subtotal'],
            'tax' => $totals['tax'],
            'total' => $totals['total'],
            'balance_due' => $totals['total'],
            'id' => $invoiceId,
        ]);
    }

    private function syncInvoiceBalance(int $invoiceId): void
    {
        $paymentsStmt = $this->connection->pdo()->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE invoice_id = :invoice_id'
        );
        $paymentsStmt->execute(['invoice_id' => $invoiceId]);
        $paid = (float) ($paymentsStmt->fetch(PDO::FETCH_ASSOC)['paid'] ?? 0.0);

        $invoice = $this->fetchInvoice($invoiceId);
        if ($invoice === null) {
            return;
        }

        $balanceDue = max(0.0, ((float) $invoice->total) - $paid);

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE invoices SET amount_paid = :amount_paid, balance_due = :balance_due WHERE id = :invoice_id'
        );
        $stmt->execute([
            'amount_paid' => $paid,
            'balance_due' => $balanceDue,
            'invoice_id' => $invoiceId,
        ]);

        if ($balanceDue <= 0.0 && $invoice->status !== 'paid') {
            $this->applyStatus($invoiceId, 'paid');
        } elseif ($paid > 0 && !in_array($invoice->status, ['paid', 'partial'], true)) {
            $this->applyStatus($invoiceId, 'partial');
        }
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

        if (!$row) {
            return null;
        }

        $row['is_mobile'] = isset($row['is_mobile']) ? (bool) $row['is_mobile'] : false;

        return new Invoice($row);
    }

    private function fetchEstimate(int $id): ?Estimate
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM estimates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['is_mobile'] = isset($row['is_mobile']) ? (bool) $row['is_mobile'] : false;

        return new Estimate($row);
    }

    private function generateInvoiceNumber(): string
    {
        return 'INV-' . date('Ymd-His') . '-' . random_int(1000, 9999);
    }

    private function generatePublicToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    private function calculatePublicExpiry(): string
    {
        return date('Y-m-d H:i:s', strtotime("+{$this->publicTtlDays} days"));
    }

    private function isPublicTokenExpired(Invoice $invoice): bool
    {
        if ($invoice->public_token_expires_at === null) {
            return false;
        }

        return strtotime($invoice->public_token_expires_at) < time();
    }

    private function log(string $action, int $entityId, ?int $actorId, array $payload = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($action, 'invoice', $entityId, $actorId, $payload));
    }
}
