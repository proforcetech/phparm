<?php

namespace App\Services\Workorder;

use App\Database\Connection;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Workorder;
use App\Models\WorkorderJob;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use Throwable;

class WorkorderService
{
    private Connection $connection;
    private WorkorderRepository $repository;
    private ?AuditLogger $audit;

    public function __construct(
        Connection $connection,
        WorkorderRepository $repository,
        ?AuditLogger $audit = null
    ) {
        $this->connection = $connection;
        $this->repository = $repository;
        $this->audit = $audit;
    }

    /**
     * Create a workorder from an approved estimate.
     * Only jobs with customer_status = 'approved' will be included.
     */
    public function createFromEstimate(int $estimateId, ?int $technicianId = null, ?int $actorId = null): Workorder
    {
        $estimate = $this->fetchEstimate($estimateId);
        if ($estimate === null) {
            throw new InvalidArgumentException('Estimate not found.');
        }

        // Verify estimate is in a valid state for conversion
        $allowedStatuses = ['approved', 'sent'];
        if (!in_array($estimate->status, $allowedStatuses, true)) {
            throw new InvalidArgumentException(
                'Estimate must be approved or sent to create a workorder. Current status: ' . $estimate->status
            );
        }

        // Check if a workorder already exists for this estimate
        $existingWorkorder = $this->repository->findByEstimateId($estimateId);
        if ($existingWorkorder !== null) {
            throw new InvalidArgumentException('A workorder already exists for this estimate.');
        }

        // Fetch approved jobs
        $approvedJobs = $this->fetchApprovedJobs($estimateId);
        if (empty($approvedJobs)) {
            throw new InvalidArgumentException('No approved jobs found on this estimate.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            // Generate workorder number
            $workorderNumber = $this->generateWorkorderNumber($estimate->number);

            // Calculate totals from approved jobs only
            $totals = $this->calculateApprovedTotals($estimateId);

            // Create workorder
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO workorders (
                    number, estimate_id, customer_id, vehicle_id, status, priority,
                    assigned_technician_id, subtotal, tax, call_out_fee, mileage_total,
                    discounts, shop_fee, hazmat_disposal_fee, grand_total,
                    internal_notes, customer_notes, created_at, updated_at
                ) VALUES (
                    :number, :estimate_id, :customer_id, :vehicle_id, :status, :priority,
                    :technician_id, :subtotal, :tax, :call_out_fee, :mileage_total,
                    :discounts, :shop_fee, :hazmat_disposal_fee, :grand_total,
                    :internal_notes, :customer_notes, NOW(), NOW()
                )
            SQL);

            $stmt->execute([
                'number' => $workorderNumber,
                'estimate_id' => $estimateId,
                'customer_id' => $estimate->customer_id,
                'vehicle_id' => $estimate->vehicle_id,
                'status' => Workorder::STATUS_PENDING,
                'priority' => Workorder::PRIORITY_NORMAL,
                'technician_id' => $technicianId ?? $estimate->technician_id,
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'call_out_fee' => $estimate->call_out_fee,
                'mileage_total' => $estimate->mileage_total,
                'discounts' => $estimate->discounts,
                'shop_fee' => $estimate->shop_fee,
                'hazmat_disposal_fee' => $estimate->hazmat_disposal_fee,
                'grand_total' => $totals['grand_total'],
                'internal_notes' => $estimate->internal_notes,
                'customer_notes' => $estimate->customer_notes,
            ]);

            $workorderId = (int) $pdo->lastInsertId();

            // Copy approved jobs and their items to workorder
            $this->copyApprovedJobsToWorkorder($workorderId, $approvedJobs, $technicianId);

            // Record initial status history
            $this->recordStatusHistory($workorderId, null, Workorder::STATUS_PENDING, $actorId, 'Workorder created from estimate');

            // Update estimate status to converted
            $this->updateEstimateStatus($estimateId, 'converted');

            $pdo->commit();

            $workorder = $this->repository->find($workorderId);
            $this->log('workorder.created_from_estimate', $workorderId, $actorId, [
                'estimate_id' => $estimateId,
                'estimate_number' => $estimate->number,
                'jobs_count' => count($approvedJobs),
                'totals' => $totals,
            ]);

            return $workorder;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Add jobs from an approved sub-estimate to an existing workorder.
     */
    public function addSubEstimateJobs(int $workorderId, int $subEstimateId, ?int $actorId = null): Workorder
    {
        $workorder = $this->repository->find($workorderId);
        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found.');
        }

        if (!$workorder->isEditable()) {
            throw new InvalidArgumentException('Cannot add jobs to a completed or cancelled workorder.');
        }

        $subEstimate = $this->fetchEstimate($subEstimateId);
        if ($subEstimate === null) {
            throw new InvalidArgumentException('Sub-estimate not found.');
        }

        // Verify sub-estimate is approved
        if ($subEstimate->status !== 'approved') {
            throw new InvalidArgumentException('Sub-estimate must be approved before adding to workorder.');
        }

        // Verify sub-estimate is linked to this workorder
        if ((int) $subEstimate->workorder_id !== $workorderId) {
            throw new InvalidArgumentException('Sub-estimate is not linked to this workorder.');
        }

        $approvedJobs = $this->fetchApprovedJobs($subEstimateId);
        if (empty($approvedJobs)) {
            throw new InvalidArgumentException('No approved jobs found on the sub-estimate.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            // Get current max position
            $stmt = $pdo->prepare('SELECT MAX(position) FROM workorder_jobs WHERE workorder_id = :workorder_id');
            $stmt->execute(['workorder_id' => $workorderId]);
            $maxPosition = (int) $stmt->fetchColumn();

            // Add jobs from sub-estimate
            $this->copyApprovedJobsToWorkorder($workorderId, $approvedJobs, null, $maxPosition + 1);

            // Recalculate totals
            $this->recalculateWorkorderTotals($workorderId);

            // Mark sub-estimate as converted
            $this->updateEstimateStatus($subEstimateId, 'converted');

            $pdo->commit();

            $workorder = $this->repository->find($workorderId);
            $this->log('workorder.sub_estimate_added', $workorderId, $actorId, [
                'sub_estimate_id' => $subEstimateId,
                'jobs_added' => count($approvedJobs),
            ]);

            return $workorder;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Convert a completed workorder to an invoice.
     */
    public function convertToInvoice(int $workorderId, ?string $dueDate = null, ?int $actorId = null): Invoice
    {
        $workorder = $this->repository->find($workorderId);
        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found.');
        }

        if ($workorder->status !== Workorder::STATUS_COMPLETED) {
            throw new InvalidArgumentException('Only completed workorders can be converted to invoices.');
        }

        // Check if invoice already exists
        $existingInvoice = $this->findInvoiceByWorkorderId($workorderId);
        if ($existingInvoice !== null) {
            throw new InvalidArgumentException('An invoice already exists for this workorder.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $invoiceNumber = $this->generateInvoiceNumber($workorder->number);

            // Create invoice
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO invoices (
                    number, customer_id, vehicle_id, estimate_id, workorder_id,
                    status, issue_date, due_date, subtotal, tax, total,
                    shop_fee, hazmat_disposal_fee, amount_paid, balance_due,
                    public_token, public_token_expires_at, created_at, updated_at
                ) VALUES (
                    :number, :customer_id, :vehicle_id, :estimate_id, :workorder_id,
                    :status, :issue_date, :due_date, :subtotal, :tax, :total,
                    :shop_fee, :hazmat_disposal_fee, 0, :balance_due,
                    :public_token, :public_token_expires_at, NOW(), NOW()
                )
            SQL);

            $total = $workorder->grand_total;
            $publicToken = bin2hex(random_bytes(20));
            $publicExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));

            $stmt->execute([
                'number' => $invoiceNumber,
                'customer_id' => $workorder->customer_id,
                'vehicle_id' => $workorder->vehicle_id,
                'estimate_id' => $workorder->estimate_id,
                'workorder_id' => $workorderId,
                'status' => 'pending',
                'issue_date' => date('Y-m-d'),
                'due_date' => $dueDate,
                'subtotal' => $workorder->subtotal,
                'tax' => $workorder->tax,
                'total' => $total,
                'shop_fee' => $workorder->shop_fee,
                'hazmat_disposal_fee' => $workorder->hazmat_disposal_fee,
                'balance_due' => $total,
                'public_token' => $publicToken,
                'public_token_expires_at' => $publicExpiry,
            ]);

            $invoiceId = (int) $pdo->lastInsertId();

            // Copy workorder items to invoice
            $this->copyWorkorderItemsToInvoice($invoiceId, $workorderId);

            // Add extra fees as line items
            $this->addExtraFeesToInvoice($invoiceId, $workorder);

            $pdo->commit();

            $invoice = $this->fetchInvoice($invoiceId);
            $this->log('workorder.converted_to_invoice', $workorderId, $actorId, [
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'total' => $total,
            ]);

            return $invoice;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Create a sub-estimate for additional work discovered during repair.
     */
    public function createSubEstimate(int $workorderId, array $payload, ?int $actorId = null): Estimate
    {
        $workorder = $this->repository->find($workorderId);
        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found.');
        }

        if (!$workorder->isEditable()) {
            throw new InvalidArgumentException('Cannot create sub-estimate for completed or cancelled workorder.');
        }

        $parentEstimate = $this->fetchEstimate($workorder->estimate_id);
        if ($parentEstimate === null) {
            throw new InvalidArgumentException('Parent estimate not found.');
        }

        if (empty($payload['jobs']) || !is_array($payload['jobs'])) {
            throw new InvalidArgumentException('At least one job must be specified for sub-estimate.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            // Generate sub-estimate number
            $subEstimateNumber = $this->generateSubEstimateNumber($parentEstimate->number);

            // Create sub-estimate
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO estimates (
                    number, parent_id, parent_estimate_id, workorder_id, estimate_type,
                    customer_id, vehicle_id, status, technician_id,
                    subtotal, tax, call_out_fee, mileage_total, discounts,
                    shop_fee, hazmat_disposal_fee, grand_total,
                    internal_notes, customer_notes, created_at, updated_at
                ) VALUES (
                    :number, :parent_id, :parent_estimate_id, :workorder_id, 'sub_estimate',
                    :customer_id, :vehicle_id, 'pending', :technician_id,
                    0, 0, 0, 0, 0, 0, 0, 0,
                    :internal_notes, :customer_notes, NOW(), NOW()
                )
            SQL);

            $stmt->execute([
                'number' => $subEstimateNumber,
                'parent_id' => null,
                'parent_estimate_id' => $parentEstimate->id,
                'workorder_id' => $workorderId,
                'customer_id' => $workorder->customer_id,
                'vehicle_id' => $workorder->vehicle_id,
                'technician_id' => $workorder->assigned_technician_id,
                'internal_notes' => $payload['internal_notes'] ?? null,
                'customer_notes' => $payload['customer_notes'] ?? null,
            ]);

            $subEstimateId = (int) $pdo->lastInsertId();

            // Create jobs and items
            $totals = $this->createEstimateJobs($subEstimateId, $payload['jobs'], $payload['tax_rate'] ?? 0.0);

            // Update totals
            $stmt = $pdo->prepare(<<<SQL
                UPDATE estimates SET
                    subtotal = :subtotal, tax = :tax, grand_total = :grand_total,
                    shop_fee = :shop_fee, hazmat_disposal_fee = :hazmat_disposal_fee,
                    updated_at = NOW()
                WHERE id = :id
            SQL);

            $stmt->execute([
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'grand_total' => $totals['grand_total'],
                'shop_fee' => $payload['shop_fee'] ?? 0,
                'hazmat_disposal_fee' => $payload['hazmat_disposal_fee'] ?? 0,
                'id' => $subEstimateId,
            ]);

            $pdo->commit();

            $subEstimate = $this->fetchEstimate($subEstimateId);
            $this->log('workorder.sub_estimate_created', $workorderId, $actorId, [
                'sub_estimate_id' => $subEstimateId,
                'sub_estimate_number' => $subEstimateNumber,
                'jobs_count' => count($payload['jobs']),
            ]);

            return $subEstimate;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Get all sub-estimates for a workorder.
     *
     * @return array<int, Estimate>
     */
    public function getSubEstimates(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT * FROM estimates WHERE workorder_id = :workorder_id AND estimate_type = 'sub_estimate' ORDER BY created_at ASC"
        );
        $stmt->execute(['workorder_id' => $workorderId]);

        return array_map(
            fn($row) => new Estimate($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    private function fetchEstimate(int $id): ?Estimate
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM estimates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new Estimate($row) : null;
    }

    private function fetchInvoice(int $id): ?Invoice
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM invoices WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new Invoice($row) : null;
    }

    private function findInvoiceByWorkorderId(int $workorderId): ?Invoice
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM invoices WHERE workorder_id = :workorder_id LIMIT 1');
        $stmt->execute(['workorder_id' => $workorderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new Invoice($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchApprovedJobs(int $estimateId): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT ej.*, st.name as service_type_name
            FROM estimate_jobs ej
            LEFT JOIN service_types st ON ej.service_type_id = st.id
            WHERE ej.estimate_id = :estimate_id AND ej.customer_status = 'approved'
            ORDER BY ej.id ASC
        SQL);
        $stmt->execute(['estimate_id' => $estimateId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, float>
     */
    private function calculateApprovedTotals(int $estimateId): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT
                COALESCE(SUM(ej.subtotal), 0) as subtotal,
                COALESCE(SUM(ej.tax), 0) as tax,
                COALESCE(SUM(ej.total), 0) as total
            FROM estimate_jobs ej
            WHERE ej.estimate_id = :estimate_id AND ej.customer_status = 'approved'
        SQL);
        $stmt->execute(['estimate_id' => $estimateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get estimate for extra fees
        $estimate = $this->fetchEstimate($estimateId);
        $extraFees = 0.0;
        if ($estimate) {
            $extraFees = (float) $estimate->call_out_fee + (float) $estimate->mileage_total
                       + (float) $estimate->shop_fee + (float) $estimate->hazmat_disposal_fee
                       - (float) $estimate->discounts;
        }

        return [
            'subtotal' => (float) $row['subtotal'],
            'tax' => (float) $row['tax'],
            'grand_total' => (float) $row['total'] + $extraFees,
        ];
    }

    private function copyApprovedJobsToWorkorder(int $workorderId, array $jobs, ?int $technicianId, int $startPosition = 0): void
    {
        $pdo = $this->connection->pdo();
        $position = $startPosition;

        foreach ($jobs as $job) {
            // Insert workorder job
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO workorder_jobs (
                    workorder_id, estimate_job_id, service_type_id, title, notes, reference,
                    status, assigned_technician_id, subtotal, tax, total, position, created_at, updated_at
                ) VALUES (
                    :workorder_id, :estimate_job_id, :service_type_id, :title, :notes, :reference,
                    :status, :technician_id, :subtotal, :tax, :total, :position, NOW(), NOW()
                )
            SQL);

            $stmt->execute([
                'workorder_id' => $workorderId,
                'estimate_job_id' => (int) $job['id'],
                'service_type_id' => $job['service_type_id'],
                'title' => $job['title'],
                'notes' => $job['notes'],
                'reference' => $job['reference'],
                'status' => WorkorderJob::STATUS_PENDING,
                'technician_id' => $technicianId,
                'subtotal' => (float) $job['subtotal'],
                'tax' => (float) $job['tax'],
                'total' => (float) $job['total'],
                'position' => $position,
            ]);

            $workorderJobId = (int) $pdo->lastInsertId();

            // Copy items for this job
            $this->copyJobItems($workorderJobId, (int) $job['id']);

            $position++;
        }
    }

    private function copyJobItems(int $workorderJobId, int $estimateJobId): void
    {
        $pdo = $this->connection->pdo();

        // Fetch estimate items
        $stmt = $pdo->prepare('SELECT * FROM estimate_items WHERE estimate_job_id = :job_id ORDER BY id ASC');
        $stmt->execute(['job_id' => $estimateJobId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $position = 0;
        foreach ($items as $item) {
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO workorder_items (
                    workorder_job_id, estimate_item_id, type, description,
                    quantity, unit_price, list_price, taxable, line_total, position
                ) VALUES (
                    :workorder_job_id, :estimate_item_id, :type, :description,
                    :quantity, :unit_price, :list_price, :taxable, :line_total, :position
                )
            SQL);

            $stmt->execute([
                'workorder_job_id' => $workorderJobId,
                'estimate_item_id' => (int) $item['id'],
                'type' => $item['type'],
                'description' => $item['description'],
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'list_price' => isset($item['list_price']) ? (float) $item['list_price'] : null,
                'taxable' => (int) $item['taxable'],
                'line_total' => (float) $item['line_total'],
                'position' => $position,
            ]);

            $position++;
        }
    }

    private function copyWorkorderItemsToInvoice(int $invoiceId, int $workorderId): void
    {
        $pdo = $this->connection->pdo();

        // Fetch all workorder jobs and items
        $stmt = $pdo->prepare(<<<SQL
            SELECT wj.title as job_title, wi.*
            FROM workorder_items wi
            JOIN workorder_jobs wj ON wi.workorder_job_id = wj.id
            WHERE wj.workorder_id = :workorder_id
            ORDER BY wj.position ASC, wi.position ASC
        SQL);
        $stmt->execute(['workorder_id' => $workorderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO invoice_items (
                    invoice_id, type, description, quantity, unit_price, list_price, taxable, line_total
                ) VALUES (
                    :invoice_id, :type, :description, :quantity, :unit_price, :list_price, :taxable, :line_total
                )
            SQL);

            $description = $item['job_title'] . ' - ' . $item['description'];

            $stmt->execute([
                'invoice_id' => $invoiceId,
                'type' => $item['type'],
                'description' => $description,
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'list_price' => isset($item['list_price']) ? (float) $item['list_price'] : null,
                'taxable' => (int) $item['taxable'],
                'line_total' => (float) $item['line_total'],
            ]);
        }
    }

    private function addExtraFeesToInvoice(int $invoiceId, Workorder $workorder): void
    {
        $pdo = $this->connection->pdo();

        $fees = [
            ['type' => 'fee', 'description' => 'Call-out Fee', 'amount' => $workorder->call_out_fee],
            ['type' => 'mileage', 'description' => 'Mileage', 'amount' => $workorder->mileage_total],
            ['type' => 'fee', 'description' => 'Shop Fee', 'amount' => $workorder->shop_fee],
            ['type' => 'fee', 'description' => 'Hazardous Disposal Fee', 'amount' => $workorder->hazmat_disposal_fee],
        ];

        foreach ($fees as $fee) {
            if ($fee['amount'] > 0) {
                $stmt = $pdo->prepare(<<<SQL
                    INSERT INTO invoice_items (invoice_id, type, description, quantity, unit_price, taxable, line_total)
                    VALUES (:invoice_id, :type, :description, 1, :amount, 0, :amount)
                SQL);

                $stmt->execute([
                    'invoice_id' => $invoiceId,
                    'type' => $fee['type'],
                    'description' => $fee['description'],
                    'amount' => $fee['amount'],
                ]);
            }
        }

        if ($workorder->discounts > 0) {
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO invoice_items (invoice_id, type, description, quantity, unit_price, taxable, line_total)
                VALUES (:invoice_id, 'discount', 'Discount', 1, :amount, 0, :line_total)
            SQL);

            $stmt->execute([
                'invoice_id' => $invoiceId,
                'amount' => -$workorder->discounts,
                'line_total' => -$workorder->discounts,
            ]);
        }
    }

    private function recalculateWorkorderTotals(int $workorderId): void
    {
        $pdo = $this->connection->pdo();

        // Sum all job totals
        $stmt = $pdo->prepare(<<<SQL
            SELECT
                COALESCE(SUM(subtotal), 0) as subtotal,
                COALESCE(SUM(tax), 0) as tax,
                COALESCE(SUM(total), 0) as total
            FROM workorder_jobs
            WHERE workorder_id = :workorder_id
        SQL);
        $stmt->execute(['workorder_id' => $workorderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get current extra fees
        $workorder = $this->repository->find($workorderId);
        $extraFees = (float) $workorder->call_out_fee + (float) $workorder->mileage_total
                   + (float) $workorder->shop_fee + (float) $workorder->hazmat_disposal_fee
                   - (float) $workorder->discounts;

        $grandTotal = (float) $row['total'] + $extraFees;

        $stmt = $pdo->prepare(<<<SQL
            UPDATE workorders SET
                subtotal = :subtotal, tax = :tax, grand_total = :grand_total, updated_at = NOW()
            WHERE id = :id
        SQL);

        $stmt->execute([
            'subtotal' => (float) $row['subtotal'],
            'tax' => (float) $row['tax'],
            'grand_total' => $grandTotal,
            'id' => $workorderId,
        ]);
    }

    /**
     * @return array<string, float>
     */
    private function createEstimateJobs(int $estimateId, array $jobs, float $taxRate): array
    {
        $pdo = $this->connection->pdo();
        $totals = ['subtotal' => 0.0, 'tax' => 0.0, 'grand_total' => 0.0];

        foreach ($jobs as $job) {
            $jobSubtotal = 0.0;
            $jobTax = 0.0;

            // Calculate job totals from items
            if (!empty($job['items']) && is_array($job['items'])) {
                foreach ($job['items'] as $item) {
                    $lineTotal = (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
                    $jobSubtotal += $lineTotal;
                    if (!empty($item['taxable'])) {
                        $jobTax += $lineTotal * $taxRate;
                    }
                }
            }

            $jobTotal = $jobSubtotal + $jobTax;

            // Insert job
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO estimate_jobs (
                    estimate_id, service_type_id, title, notes, reference,
                    customer_status, subtotal, tax, total
                ) VALUES (
                    :estimate_id, :service_type_id, :title, :notes, :reference,
                    'pending', :subtotal, :tax, :total
                )
            SQL);

            $stmt->execute([
                'estimate_id' => $estimateId,
                'service_type_id' => $job['service_type_id'] ?? null,
                'title' => $job['title'],
                'notes' => $job['notes'] ?? null,
                'reference' => $job['reference'] ?? null,
                'subtotal' => $jobSubtotal,
                'tax' => $jobTax,
                'total' => $jobTotal,
            ]);

            $jobId = (int) $pdo->lastInsertId();

            // Insert items
            if (!empty($job['items']) && is_array($job['items'])) {
                foreach ($job['items'] as $item) {
                    $lineTotal = (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);

                    $stmt = $pdo->prepare(<<<SQL
                        INSERT INTO estimate_items (
                            estimate_job_id, type, description, quantity, unit_price, list_price, taxable, line_total
                        ) VALUES (
                            :job_id, :type, :description, :quantity, :unit_price, :list_price, :taxable, :line_total
                        )
                    SQL);

                    $stmt->execute([
                        'job_id' => $jobId,
                        'type' => $item['type'] ?? 'LABOR',
                        'description' => $item['description'],
                        'quantity' => (float) ($item['quantity'] ?? 0),
                        'unit_price' => (float) ($item['unit_price'] ?? 0),
                        'list_price' => isset($item['list_price']) ? (float) $item['list_price'] : null,
                        'taxable' => !empty($item['taxable']) ? 1 : 0,
                        'line_total' => $lineTotal,
                    ]);
                }
            }

            $totals['subtotal'] += $jobSubtotal;
            $totals['tax'] += $jobTax;
        }

        $totals['grand_total'] = $totals['subtotal'] + $totals['tax'];

        return $totals;
    }

    private function updateEstimateStatus(int $estimateId, string $status): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE estimates SET status = :status, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'id' => $estimateId]);
    }

    private function recordStatusHistory(int $workorderId, ?string $fromStatus, string $toStatus, ?int $changedBy, ?string $notes): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO workorder_status_history (workorder_id, from_status, to_status, changed_by, notes, created_at)
            VALUES (:workorder_id, :from_status, :to_status, :changed_by, :notes, NOW())
        SQL);

        $stmt->execute([
            'workorder_id' => $workorderId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $changedBy,
            'notes' => $notes,
        ]);
    }

    private function generateWorkorderNumber(string $estimateNumber): string
    {
        $base = 'WO-' . preg_replace('/^EST-/', '', $estimateNumber);
        $candidate = $base;
        $suffix = 1;

        while ($this->workorderNumberExists($candidate)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function generateSubEstimateNumber(string $parentNumber): string
    {
        $base = $parentNumber . '-SUB';
        $candidate = $base . '-1';
        $suffix = 1;

        while ($this->estimateNumberExists($candidate)) {
            $suffix++;
            $candidate = $base . '-' . $suffix;
        }

        return $candidate;
    }

    private function generateInvoiceNumber(string $workorderNumber): string
    {
        $base = 'INV-' . preg_replace('/^WO-/', '', $workorderNumber);
        $candidate = $base;
        $suffix = 1;

        while ($this->invoiceNumberExists($candidate)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function workorderNumberExists(string $number): bool
    {
        $stmt = $this->connection->pdo()->prepare('SELECT 1 FROM workorders WHERE number = :number LIMIT 1');
        $stmt->execute(['number' => $number]);

        return (bool) $stmt->fetchColumn();
    }

    private function estimateNumberExists(string $number): bool
    {
        $stmt = $this->connection->pdo()->prepare('SELECT 1 FROM estimates WHERE number = :number LIMIT 1');
        $stmt->execute(['number' => $number]);

        return (bool) $stmt->fetchColumn();
    }

    private function invoiceNumberExists(string $number): bool
    {
        $stmt = $this->connection->pdo()->prepare('SELECT 1 FROM invoices WHERE number = :number LIMIT 1');
        $stmt->execute(['number' => $number]);

        return (bool) $stmt->fetchColumn();
    }

    private function log(string $event, int $workorderId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'workorder', (string) $workorderId, $actorId, $context));
    }
}
