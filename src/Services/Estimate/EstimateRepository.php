<?php

namespace App\Services\Estimate;

use App\Database\Connection;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use Throwable;

class EstimateRepository
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    public function find(int $id): ?Estimate
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM estimates WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapEstimate($row) : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, Estimate>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = [];
        $bindings = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'status = :status';
            $bindings['status'] = $filters['status'];
        }

        if (!empty($filters['customer_id'])) {
            $clauses[] = 'customer_id = :customer_id';
            $bindings['customer_id'] = (int) $filters['customer_id'];
        }

        if (!empty($filters['vehicle_id'])) {
            $clauses[] = 'vehicle_id = :vehicle_id';
            $bindings['vehicle_id'] = (int) $filters['vehicle_id'];
        }

        if (!empty($filters['term'])) {
            $clauses[] = '(number LIKE :term OR status LIKE :term)';
            $bindings['term'] = '%' . $filters['term'] . '%';
        }

        if (!empty($filters['created_from'])) {
            $clauses[] = 'created_at >= :created_from';
            $bindings['created_from'] = $filters['created_from'];
        }

        if (!empty($filters['created_to'])) {
            $clauses[] = 'created_at <= :created_to';
            $bindings['created_to'] = $filters['created_to'];
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $sql = 'SELECT * FROM estimates ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare($sql);

        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = $this->mapEstimate($row);
        }

        return $results;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null, ?string $reason = null): ?Estimate
    {
        $estimate = $this->find($id);
        if ($estimate === null) {
            return null;
        }

        $allowedStatuses = ['sent', 'approved', 'rejected', 'expired'];
        if (!in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException('Invalid status for estimate lifecycle.');
        }

        $before = $estimate->toArray();
        $stmt = $this->connection->pdo()->prepare('UPDATE estimates SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);

        $estimate->status = $status;
        $this->log('estimate.status_changed', $id, $actorId, [
            'before' => $before,
            'after' => $estimate->toArray(),
            'reason' => $reason,
        ]);

        return $estimate;
    }

    public function markExpiredBefore(string $date, ?int $actorId = null): int
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id FROM estimates WHERE expiration_date < :date AND status NOT IN (\'expired\', \'converted\')');
        $stmt->execute(['date' => $date]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (empty($ids)) {
            return 0;
        }

        $update = $this->connection->pdo()->prepare('UPDATE estimates SET status = :status, updated_at = NOW() WHERE id IN (' . implode(',', $ids) . ')');
        $update->execute(['status' => 'expired']);

        foreach ($ids as $estimateId) {
            $this->log('estimate.status_changed', $estimateId, $actorId, ['before' => ['status' => 'pending'], 'after' => ['status' => 'expired']]);
        }

        return count($ids);
    }

    public function convertToInvoice(int $estimateId, string $issueDate, ?string $dueDate = null, ?int $actorId = null): ?Invoice
    {
        $estimate = $this->find($estimateId);
        if ($estimate === null) {
            return null;
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $invoiceNumber = $this->generateInvoiceNumber($estimate->number);

            $insert = $pdo->prepare(<<<SQL
                INSERT INTO invoices (number, customer_id, vehicle_id, estimate_id, status, issue_date, due_date, subtotal, tax, total, amount_paid, balance_due, created_at, updated_at)
                VALUES (:number, :customer_id, :vehicle_id, :estimate_id, :status, :issue_date, :due_date, :subtotal, :tax, :total, :amount_paid, :balance_due, NOW(), NOW())
            SQL);

            $total = $estimate->grand_total;
            $insert->execute([
                'number' => $invoiceNumber,
                'customer_id' => $estimate->customer_id,
                'vehicle_id' => $estimate->vehicle_id,
                'estimate_id' => $estimate->id,
                'status' => 'pending',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'subtotal' => $estimate->subtotal,
                'tax' => $estimate->tax,
                'total' => $total,
                'amount_paid' => 0,
                'balance_due' => $total,
            ]);

            $invoiceId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE estimates SET status = :status, updated_at = NOW() WHERE id = :id')
                ->execute(['status' => 'converted', 'id' => $estimateId]);

            $invoice = new Invoice([
                'id' => $invoiceId,
                'number' => $invoiceNumber,
                'customer_id' => $estimate->customer_id,
                'vehicle_id' => $estimate->vehicle_id,
                'estimate_id' => $estimate->id,
                'status' => 'pending',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'subtotal' => $estimate->subtotal,
                'tax' => $estimate->tax,
                'total' => $total,
                'amount_paid' => 0,
                'balance_due' => $total,
            ]);

            $pdo->commit();

            $this->log('estimate.converted', $estimateId, $actorId, [
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
            ]);

            return $invoice;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function logLinkDispatch(int $estimateId, string $channel, string $recipient, string $link, ?int $actorId = null): array
    {
        $estimate = $this->find($estimateId);
        if ($estimate === null) {
            throw new InvalidArgumentException('Estimate not found for link dispatch.');
        }

        $payload = [
            'estimate_id' => $estimateId,
            'channel' => $channel,
            'recipient' => $recipient,
            'link' => $link,
        ];

        $this->log('estimate.link_sent', $estimateId, $actorId, $payload);

        return $payload;
    }

    private function mapEstimate(array $row): Estimate
    {
        return new Estimate([
            'id' => (int) $row['id'],
            'number' => (string) $row['number'],
            'customer_id' => (int) $row['customer_id'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'status' => (string) $row['status'],
            'technician_id' => $row['technician_id'] !== null ? (int) $row['technician_id'] : null,
            'expiration_date' => $row['expiration_date'],
            'subtotal' => (float) $row['subtotal'],
            'tax' => (float) $row['tax'],
            'call_out_fee' => (float) $row['call_out_fee'],
            'mileage_total' => (float) $row['mileage_total'],
            'discounts' => (float) $row['discounts'],
            'grand_total' => (float) $row['grand_total'],
            'internal_notes' => $row['internal_notes'],
            'customer_notes' => $row['customer_notes'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
    }

    private function generateInvoiceNumber(string $estimateNumber): string
    {
        $base = 'INV-' . $estimateNumber;
        $candidate = $base;
        $suffix = 1;

        while ($this->invoiceExists($candidate)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function invoiceExists(string $number): bool
    {
        $stmt = $this->connection->pdo()->prepare('SELECT 1 FROM invoices WHERE number = :number LIMIT 1');
        $stmt->execute(['number' => $number]);

        return (bool) $stmt->fetchColumn();
    }

    private function log(string $event, int $estimateId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'estimate', (string) $estimateId, $actorId, $context));
    }
}
