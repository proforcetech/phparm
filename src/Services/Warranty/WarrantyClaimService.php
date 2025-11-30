<?php

namespace App\Services\Warranty;

use App\Database\Connection;
use App\Models\WarrantyClaim;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class WarrantyClaimService
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
    public function submit(int $customerId, array $payload, ?int $actorId = null): WarrantyClaim
    {
        $this->assertPayload($payload);
        $invoiceId = $payload['invoice_id'] ?? null;
        if ($invoiceId !== null && !$this->invoiceBelongsToCustomer((int) $invoiceId, $customerId)) {
            throw new InvalidArgumentException('Invoice does not belong to customer for warranty claim.');
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO warranty_claims (customer_id, invoice_id, vehicle_id, subject, description, status, created_at, updated_at)
            VALUES (:customer_id, :invoice_id, :vehicle_id, :subject, :description, :status, NOW(), NOW())
        SQL);

        $stmt->execute([
            'customer_id' => $customerId,
            'invoice_id' => $invoiceId,
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            'subject' => $payload['subject'],
            'description' => $payload['description'],
            'status' => 'open',
        ]);

        $claimId = (int) $this->connection->pdo()->lastInsertId();
        $claim = $this->find($claimId);
        $this->log('warranty.submitted', $claimId, $actorId, ['after' => $claim?->toArray()]);

        return $claim ?? new WarrantyClaim(['id' => $claimId]);
    }

    /**
     * @return array<int, WarrantyClaim>
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

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $sql = 'SELECT * FROM warranty_claims ' . $where . ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $claims = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $claims[] = $this->map($row);
        }

        return $claims;
    }

    public function updateStatus(int $claimId, string $status, ?int $actorId = null): ?WarrantyClaim
    {
        $allowed = ['open', 'in_review', 'resolved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid status for warranty claim.');
        }

        $before = $this->find($claimId);
        if ($before === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare('UPDATE warranty_claims SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $claimId]);

        $after = $this->find($claimId);
        $this->log('warranty.status_changed', $claimId, $actorId, [
            'before' => $before->toArray(),
            'after' => $after?->toArray(),
        ]);

        return $after;
    }

    public function find(int $claimId): ?WarrantyClaim
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM warranty_claims WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $claimId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->map($row) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertPayload(array $payload): void
    {
        foreach (['subject', 'description'] as $field) {
            if (empty($payload[$field])) {
                throw new InvalidArgumentException('Missing required warranty claim field: ' . $field);
            }
        }
    }

    private function invoiceBelongsToCustomer(int $invoiceId, int $customerId): bool
    {
        $stmt = $this->connection->pdo()->prepare('SELECT 1 FROM invoices WHERE id = :id AND customer_id = :customer_id LIMIT 1');
        $stmt->execute(['id' => $invoiceId, 'customer_id' => $customerId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): WarrantyClaim
    {
        return new WarrantyClaim([
            'id' => (int) $row['id'],
            'customer_id' => (int) $row['customer_id'],
            'invoice_id' => $row['invoice_id'] !== null ? (int) $row['invoice_id'] : null,
            'vehicle_id' => $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
            'subject' => (string) $row['subject'],
            'description' => (string) $row['description'],
            'status' => (string) $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
    }

    private function log(string $event, int $claimId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'warranty_claim', (string) $claimId, $actorId, $context));
    }
}
