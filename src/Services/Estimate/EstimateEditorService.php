<?php

namespace App\Services\Estimate;

use App\Database\Connection;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateJob;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use Throwable;

class EstimateEditorService
{
    private Connection $connection;
    private ?AuditLogger $audit;
    private const ALLOWED_ITEM_STATUSES = ['pending', 'approved', 'rejected'];

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, int $actorId): Estimate
    {
        $this->assertValidPayload($payload);
        $status = $this->determineStatusForCreate($payload);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $estimateId = $this->insertEstimate($payload, $status);
            $totals = $this->persistJobsAndItems($estimateId, $payload['jobs'], $payload['tax_rate'] ?? 0.0);
            $this->applyTotals($estimateId, $payload, $totals);

            $pdo->commit();
            $estimate = $this->fetchEstimate($estimateId);
            $this->log('estimate.created', $estimateId, $actorId, ['after' => $estimate?->toArray()]);

            return $estimate ?? new Estimate(['id' => $estimateId]);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $estimateId, array $payload, int $actorId): ?Estimate
    {
        $existing = $this->fetchEstimate($estimateId);
        if ($existing === null) {
            return null;
        }

        $this->assertValidPayload($payload, true);
        $status = $this->determineStatusForUpdate($existing, $payload);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $this->updateEstimateHeader($estimateId, $payload, $status);
            $this->deleteExistingJobs($estimateId);
            $totals = $this->persistJobsAndItems($estimateId, $payload['jobs'], $payload['tax_rate'] ?? 0.0);
            $this->applyTotals($estimateId, $payload, $totals);

            $pdo->commit();
            $updated = $this->fetchEstimate($estimateId);
            $this->log('estimate.updated', $estimateId, $actorId, [
                'before' => $existing->toArray(),
                'after' => $updated?->toArray(),
            ]);

            return $updated;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function setJobCustomerStatus(int $estimateId, int $jobId, string $status, ?int $actorId = null): bool
    {
        $allowed = ['pending', 'approved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid job status value.');
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE estimate_jobs SET customer_status = :status WHERE id = :job_id AND estimate_id = :estimate_id'
        );
        $stmt->execute([
            'status' => $status,
            'job_id' => $jobId,
            'estimate_id' => $estimateId,
        ]);

        $updated = $stmt->rowCount() > 0;
        if ($updated) {
            $this->log('estimate.job_status_changed', $estimateId, $actorId, [
                'job_id' => $jobId,
                'status' => $status,
            ]);
        }

        return $updated;
    }

    public function reject(int $estimateId, string $reason, ?int $actorId = null): ?Estimate
    {
        $estimate = $this->fetchEstimate($estimateId);
        if ($estimate === null) {
            return null;
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE estimates SET status = :status, rejection_reason = :reason, updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([
                'status' => 'rejected',
                'reason' => $reason,
                'id' => $estimateId,
            ]);

            $pdo->prepare(
                'UPDATE estimate_items ei JOIN estimate_jobs ej ON ej.id = ei.estimate_job_id ' .
                'SET ei.status = :status WHERE ej.estimate_id = :estimate_id'
            )->execute([
                'status' => 'rejected',
                'estimate_id' => $estimateId,
            ]);

            $pdo->commit();
            $updated = $this->fetchEstimate($estimateId);
            $this->log('estimate.rejected', $estimateId, $actorId, [
                'reason' => $reason,
                'after' => $updated?->toArray(),
            ]);

            return $updated;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function updateItemStatuses(int $estimateId, array $items, ?int $actorId = null): ?Estimate
    {
        if ($items === []) {
            throw new InvalidArgumentException('At least one item status update is required.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE estimate_items ei JOIN estimate_jobs ej ON ej.id = ei.estimate_job_id ' .
                'SET ei.status = :status WHERE ei.id = :id AND ej.estimate_id = :estimate_id'
            );

            foreach ($items as $item) {
                $status = $item['status'] ?? null;
                $itemId = $item['id'] ?? null;

                if ($itemId === null || $status === null) {
                    throw new InvalidArgumentException('Each item update requires id and status.');
                }

                if (!in_array($status, self::ALLOWED_ITEM_STATUSES, true)) {
                    throw new InvalidArgumentException('Invalid estimate item status value.');
                }

                $stmt->execute([
                    'status' => $status,
                    'id' => (int) $itemId,
                    'estimate_id' => $estimateId,
                ]);
            }

            $this->syncEstimateStatusFromItems($estimateId);

            $pdo->commit();
            $updated = $this->fetchEstimate($estimateId);
            $this->log('estimate.items_status_updated', $estimateId, $actorId, [
                'items' => $items,
                'after' => $updated?->toArray(),
            ]);

            return $updated;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertValidPayload(array $payload, bool $isUpdate = false): void
    {
        $required = ['customer_id', 'vehicle_id', 'number', 'jobs'];
        foreach ($required as $field) {
            if ($isUpdate && $field === 'number') {
                continue;
            }

            if (!array_key_exists($field, $payload)) {
                throw new InvalidArgumentException('Missing required estimate field: ' . $field);
            }
        }

        $expiration = $payload['expiration_date'] ?? null;
        if ($expiration !== null && $expiration !== '') {
            $timestamp = strtotime((string) $expiration);
            $startOfDay = strtotime('today');
            if ($timestamp === false || $timestamp < $startOfDay) {
                throw new InvalidArgumentException('Expiration date cannot be in the past.');
            }
        }

        if (!is_array($payload['jobs']) || $payload['jobs'] === []) {
            throw new InvalidArgumentException('Estimate must include at least one job.');
        }

        if (isset($payload['is_mobile']) && !in_array($payload['is_mobile'], [0, 1, true, false], true)) {
            throw new InvalidArgumentException('Invalid value for mobile flag.');
        }

        foreach ($payload['jobs'] as $job) {
            if (empty($job['title'])) {
                throw new InvalidArgumentException('Estimate jobs require a title.');
            }

            if (!isset($job['items']) || !is_array($job['items']) || $job['items'] === []) {
                throw new InvalidArgumentException('Estimate jobs must include line items.');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertEstimate(array $payload, string $status): int
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimates (parent_id, number, customer_id, vehicle_id, is_mobile, technician_id, expiration_date, status, internal_notes, customer_notes, call_out_fee, mileage_total, discounts, subtotal, tax, grand_total, created_at, updated_at)
            VALUES (:parent_id, :number, :customer_id, :vehicle_id, :is_mobile, :technician_id, :expiration_date, :status, :internal_notes, :customer_notes, :call_out_fee, :mileage_total, :discounts, 0, 0, 0, NOW(), NOW())
        SQL);

        $expirationDate = $payload['expiration_date'] ?? date('Y-m-d', strtotime('+14 days'));

        $stmt->execute([
            'parent_id' => $payload['parent_id'] ?? null,
            'number' => $payload['number'] ?? $this->generateNumber(),
            'customer_id' => (int) $payload['customer_id'],
            'vehicle_id' => (int) $payload['vehicle_id'],
            'is_mobile' => (int) (!empty($payload['is_mobile'])),
            'technician_id' => $payload['technician_id'] ?? null,
            'expiration_date' => $expirationDate,
            'status' => $status,
            'internal_notes' => $payload['internal_notes'] ?? null,
            'customer_notes' => $payload['customer_notes'] ?? null,
            'call_out_fee' => (float) ($payload['call_out_fee'] ?? 0),
            'mileage_total' => (float) ($payload['mileage_total'] ?? 0),
            'discounts' => (float) ($payload['discounts'] ?? 0),
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateEstimateHeader(int $estimateId, array $payload, ?string $status): void
    {
        $sql = <<<SQL
            UPDATE estimates SET
                parent_id = :parent_id,
                customer_id = :customer_id,
                vehicle_id = :vehicle_id,
                is_mobile = :is_mobile,
                technician_id = :technician_id,
                expiration_date = :expiration_date,
                status = COALESCE(:status, status),
                internal_notes = :internal_notes,
                customer_notes = :customer_notes,
                call_out_fee = :call_out_fee,
                mileage_total = :mileage_total,
                discounts = :discounts,
                updated_at = NOW()
            WHERE id = :id
        SQL;

        $expirationDate = $payload['expiration_date'] ?? date('Y-m-d', strtotime('+14 days'));

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'parent_id' => $payload['parent_id'] ?? null,
            'customer_id' => (int) $payload['customer_id'],
            'vehicle_id' => (int) $payload['vehicle_id'],
            'is_mobile' => (int) (!empty($payload['is_mobile'])),
            'technician_id' => $payload['technician_id'] ?? null,
            'expiration_date' => $expirationDate,
            'status' => $status,
            'internal_notes' => $payload['internal_notes'] ?? null,
            'customer_notes' => $payload['customer_notes'] ?? null,
            'call_out_fee' => (float) ($payload['call_out_fee'] ?? 0),
            'mileage_total' => (float) ($payload['mileage_total'] ?? 0),
            'discounts' => (float) ($payload['discounts'] ?? 0),
            'id' => $estimateId,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     * @return array{subtotal: float, tax: float, grand_total: float}
     */
    private function persistJobsAndItems(int $estimateId, array $jobs, float $taxRate): array
    {
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($jobs as $displayOrder => $job) {
            $jobTotals = $this->calculateJobTotals($job['items'], $taxRate);
            $subtotal += $jobTotals['subtotal'];
            $tax += $jobTotals['tax'];

            $jobId = $this->insertJob($estimateId, $job, $jobTotals, $displayOrder);
            $this->insertItems($jobId, $job['items']);
        }

        $grandTotal = $subtotal + $tax;

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{subtotal: float, tax: float}
     */
    private function calculateJobTotals(array $items, float $taxRate): array
    {
        $subtotal = 0.0;
        $taxable = 0.0;

        foreach ($items as $item) {
            $lineTotal = (float) $item['quantity'] * (float) $item['unit_price'];
            $subtotal += $lineTotal;

            $isTaxable = array_key_exists('taxable', $item) ? (bool) $item['taxable'] : true;
            if ($isTaxable) {
                $taxable += $lineTotal;
            }
        }

        $tax = $taxable * $taxRate;

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @param array{subtotal: float, tax: float} $totals
     */
    private function insertJob(int $estimateId, array $job, array $totals, int $displayOrder): int
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_jobs (estimate_id, service_type_id, title, notes, reference, customer_status, subtotal, tax, total, display_order)
            VALUES (:estimate_id, :service_type_id, :title, :notes, :reference, :customer_status, :subtotal, :tax, :total, :display_order)
        SQL);

        $total = $totals['subtotal'] + $totals['tax'];
        $stmt->execute([
            'estimate_id' => $estimateId,
            'service_type_id' => $job['service_type_id'] ?? null,
            'title' => $job['title'],
            'notes' => $job['notes'] ?? null,
            'reference' => $job['reference'] ?? null,
            'customer_status' => $job['customer_status'] ?? 'pending',
            'subtotal' => $totals['subtotal'],
            'tax' => $totals['tax'],
            'total' => $total,
            'display_order' => $displayOrder,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function insertItems(int $jobId, array $items): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_items (estimate_job_id, type, description, quantity, unit_price, list_price, taxable, line_total, status)
            VALUES (:estimate_job_id, :type, :description, :quantity, :unit_price, :list_price, :taxable, :line_total, :status)
        SQL);

        foreach ($items as $displayOrder => $item) {
            $lineTotal = (float) $item['quantity'] * (float) $item['unit_price'];
            $stmt->execute([
                'estimate_job_id' => $jobId,
                'type' => $item['type'],
                'description' => $item['description'],
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'list_price' => (float) ($item['list_price'] ?? 0),
                'taxable' => array_key_exists('taxable', $item) ? (bool) $item['taxable'] : true,
                'line_total' => $lineTotal,
                'status' => $item['status'] ?? 'pending',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{subtotal: float, tax: float, grand_total: float} $totals
     */
    private function applyTotals(int $estimateId, array $payload, array $totals): void
    {
        $grandTotal = $totals['grand_total']
            + (float) ($payload['call_out_fee'] ?? 0)
            + (float) ($payload['mileage_total'] ?? 0)
            - (float) ($payload['discounts'] ?? 0);

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE estimates SET subtotal = :subtotal, tax = :tax, grand_total = :grand_total, updated_at = NOW() WHERE id = :id'
        );

        $stmt->execute([
            'subtotal' => $totals['subtotal'],
            'tax' => $totals['tax'],
            'grand_total' => $grandTotal,
            'id' => $estimateId,
        ]);
    }

    private function deleteExistingJobs(int $estimateId): void
    {
        $pdo = $this->connection->pdo();
        $pdo->prepare('DELETE FROM estimate_items WHERE estimate_job_id IN (SELECT id FROM estimate_jobs WHERE estimate_id = :estimate_id)')
            ->execute(['estimate_id' => $estimateId]);
        $pdo->prepare('DELETE FROM estimate_jobs WHERE estimate_id = :estimate_id')
            ->execute(['estimate_id' => $estimateId]);
    }

    private function fetchEstimate(int $estimateId): ?Estimate
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM estimates WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $estimateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new Estimate([
            'id' => (int) $row['id'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'number' => (string) $row['number'],
            'customer_id' => (int) $row['customer_id'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'is_mobile' => (bool) $row['is_mobile'],
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
            'rejection_reason' => $row['rejection_reason'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]) : null;
    }

    private function generateNumber(): string
    {
        return 'EST-' . date('Ymd-His') . '-' . random_int(100, 999);
    }

    private function log(string $event, int $estimateId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'estimate', (string) $estimateId, $actorId, $context));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function determineStatusForCreate(array $payload): string
    {
        $candidate = $payload['status'] ?? 'pending';
        $normalized = EstimateRepository::normalizeStatus($candidate);
        if (!in_array($normalized, EstimateRepository::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid estimate status value.');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function determineStatusForUpdate(Estimate $existing, array $payload): ?string
    {
        if (!array_key_exists('status', $payload)) {
            return null;
        }

        if ($payload['status'] === null) {
            return null;
        }

        $normalized = EstimateRepository::normalizeStatus((string) $payload['status']);
        if (!in_array($normalized, EstimateRepository::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid estimate status value.');
        }

        return $normalized;
    }

    private function syncEstimateStatusFromItems(int $estimateId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT status, COUNT(*) AS total ' .
            'FROM estimate_items ei JOIN estimate_jobs ej ON ej.id = ei.estimate_job_id ' .
            'WHERE ej.estimate_id = :estimate_id GROUP BY status'
        );
        $stmt->execute(['estimate_id' => $estimateId]);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if (empty($counts)) {
            return;
        }

        $total = array_sum(array_map('intval', $counts));
        $approved = (int) ($counts['approved'] ?? 0);
        $rejected = (int) ($counts['rejected'] ?? 0);

        if ($total > 0 && $approved === $total) {
            $this->connection->pdo()->prepare('UPDATE estimates SET status = :status, updated_at = NOW() WHERE id = :id')
                ->execute(['status' => 'approved', 'id' => $estimateId]);
            return;
        }

        if ($total > 0 && $rejected === $total) {
            $this->connection->pdo()->prepare('UPDATE estimates SET status = :status, updated_at = NOW() WHERE id = :id')
                ->execute(['status' => 'rejected', 'id' => $estimateId]);
        }
    }
}
