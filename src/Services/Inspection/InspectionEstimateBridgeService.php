<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\Estimate;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Service for bridging inspections to estimates.
 * Identifies failed inspection items and facilitates adding them to estimates or workorders.
 */
class InspectionEstimateBridgeService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    /** @var array<string, string> Default failure thresholds by input type */
    private const DEFAULT_FAIL_THRESHOLDS = [
        'boolean' => 'no',
        'boolean_na' => 'no',
        'number_scale' => '3', // Items scoring 3 or below on typical 1-10 scale are failures
        'select_scale' => 'fail,poor,bad,needs_attention,needs_repair,replace',
    ];

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * Analyze an inspection report and identify failed items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function identifyFailedItems(int $reportId): array
    {
        $report = $this->fetchReport($reportId);
        if ($report === null) {
            throw new InvalidArgumentException('Inspection report not found.');
        }

        $items = $this->fetchReportItemsWithTemplateData($reportId);
        $failedItems = [];

        foreach ($items as $item) {
            $failureInfo = $this->evaluateItemFailure($item);
            if ($failureInfo['is_failed']) {
                $failedItems[] = array_merge($item, [
                    'failure_reason' => $failureInfo['reason'],
                    'severity' => $failureInfo['severity'],
                    'already_converted' => $this->isItemAlreadyConverted($reportId, (int) $item['template_item_id']),
                ]);
            }
        }

        return $failedItems;
    }

    /**
     * Get recommendations for a report, generating them if needed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecommendations(int $reportId): array
    {
        $existing = $this->fetchExistingRecommendations($reportId);

        if (empty($existing)) {
            $this->generateRecommendations($reportId);
            $existing = $this->fetchExistingRecommendations($reportId);
        }

        return $existing;
    }

    /**
     * Generate recommendations from failed items.
     */
    public function generateRecommendations(int $reportId): int
    {
        $failedItems = $this->identifyFailedItems($reportId);
        $count = 0;

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO inspection_recommendations
                (inspection_report_id, report_item_id, severity, recommended_action, estimated_cost, status, created_at)
            VALUES
                (:report_id, :item_id, :severity, :action, :cost, 'pending', NOW())
            ON DUPLICATE KEY UPDATE severity = VALUES(severity)
        SQL);

        foreach ($failedItems as $item) {
            if ($item['already_converted']) {
                continue;
            }

            $estimatedCost = $this->calculateEstimatedCost($item);
            $action = $this->generateRecommendedAction($item);

            $stmt->execute([
                'report_id' => $reportId,
                'item_id' => (int) $item['id'],
                'severity' => $item['severity'],
                'action' => $action,
                'cost' => $estimatedCost,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Add selected failed items to an estimate as new jobs.
     *
     * @param array<int> $reportItemIds IDs of inspection_report_items to add
     * @return array<string, mixed> Created estimate job IDs and totals
     */
    public function addToEstimate(
        int $reportId,
        int $estimateId,
        array $reportItemIds,
        ?int $actorId = null,
        float $laborRate = 100.00,
        float $taxRate = 0.0
    ): array {
        $report = $this->fetchReport($reportId);
        if ($report === null) {
            throw new InvalidArgumentException('Inspection report not found.');
        }

        $estimate = $this->fetchEstimate($estimateId);
        if ($estimate === null) {
            throw new InvalidArgumentException('Estimate not found.');
        }

        // Verify estimate is editable
        $editableStatuses = ['draft', 'pending', 'sent'];
        if (!in_array($estimate['status'], $editableStatuses, true)) {
            throw new InvalidArgumentException('Estimate is not in an editable status.');
        }

        $items = $this->fetchReportItemsByIds($reportId, $reportItemIds);
        if (empty($items)) {
            throw new InvalidArgumentException('No valid report items found.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $jobIds = [];
            $totalAdded = 0.0;

            // Get max display order for existing jobs
            $stmt = $pdo->prepare('SELECT MAX(display_order) FROM estimate_jobs WHERE estimate_id = :id');
            $stmt->execute(['id' => $estimateId]);
            $maxOrder = (int) $stmt->fetchColumn();

            foreach ($items as $item) {
                $jobData = $this->createJobFromInspectionItem($item, $laborRate, $taxRate);
                $maxOrder++;

                // Insert job
                $jobId = $this->insertEstimateJob($estimateId, $jobData, $maxOrder);
                $jobIds[] = $jobId;

                // Insert items
                $this->insertEstimateItems($jobId, $jobData['items']);

                // Record conversion
                $this->recordConversion($reportId, (int) $item['template_item_id'], $estimateId, $jobId, $actorId);

                // Update recommendation status
                $this->updateRecommendationStatus($reportId, (int) $item['id'], 'added_to_estimate', $actorId);

                $totalAdded += $jobData['total'];
            }

            // Recalculate estimate totals
            $this->recalculateEstimateTotals($estimateId);

            $pdo->commit();

            $this->log('inspection.items_added_to_estimate', $reportId, $actorId, [
                'estimate_id' => $estimateId,
                'job_ids' => $jobIds,
                'items_count' => count($items),
                'total_added' => $totalAdded,
            ]);

            return [
                'job_ids' => $jobIds,
                'items_added' => count($items),
                'total_added' => $totalAdded,
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Create a new estimate from failed inspection items.
     *
     * @param array<int> $reportItemIds
     * @return array<string, mixed>
     */
    public function createEstimateFromFailedItems(
        int $reportId,
        array $reportItemIds,
        ?int $actorId = null,
        float $laborRate = 100.00,
        float $taxRate = 0.0
    ): array {
        $report = $this->fetchReport($reportId);
        if ($report === null) {
            throw new InvalidArgumentException('Inspection report not found.');
        }

        $items = $this->fetchReportItemsByIds($reportId, $reportItemIds);
        if (empty($items)) {
            throw new InvalidArgumentException('No valid report items found.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            // Generate estimate number
            $estimateNumber = $this->generateEstimateNumber();

            // Create estimate
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO estimates (
                    number, customer_id, vehicle_id, status, internal_notes,
                    subtotal, tax, grand_total, created_at, updated_at
                ) VALUES (
                    :number, :customer_id, :vehicle_id, 'draft', :notes,
                    0, 0, 0, NOW(), NOW()
                )
            SQL);

            $stmt->execute([
                'number' => $estimateNumber,
                'customer_id' => $report['customer_id'],
                'vehicle_id' => $report['vehicle_id'],
                'notes' => 'Generated from inspection report #' . $reportId,
            ]);

            $estimateId = (int) $pdo->lastInsertId();

            // Add items to estimate
            $result = $this->addToEstimate($reportId, $estimateId, $reportItemIds, $actorId, $laborRate, $taxRate);

            // Link inspection report to estimate
            $pdo->prepare('UPDATE inspection_reports SET estimate_id = :estimate_id WHERE id = :id')
                ->execute(['estimate_id' => $estimateId, 'id' => $reportId]);

            $pdo->commit();

            $this->log('inspection.estimate_created', $reportId, $actorId, [
                'estimate_id' => $estimateId,
                'estimate_number' => $estimateNumber,
            ]);

            return array_merge($result, [
                'estimate_id' => $estimateId,
                'estimate_number' => $estimateNumber,
            ]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Add failed items to an active workorder as a sub-estimate.
     *
     * @param array<int> $reportItemIds
     * @return array<string, mixed>
     */
    public function addToWorkorder(
        int $reportId,
        int $workorderId,
        array $reportItemIds,
        ?int $actorId = null,
        float $laborRate = 100.00,
        float $taxRate = 0.0
    ): array {
        $report = $this->fetchReport($reportId);
        if ($report === null) {
            throw new InvalidArgumentException('Inspection report not found.');
        }

        $workorder = $this->fetchWorkorder($workorderId);
        if ($workorder === null) {
            throw new InvalidArgumentException('Workorder not found.');
        }

        // Verify workorder is editable
        $terminalStatuses = ['completed', 'cancelled'];
        if (in_array($workorder['status'], $terminalStatuses, true)) {
            throw new InvalidArgumentException('Workorder is not editable.');
        }

        $items = $this->fetchReportItemsByIds($reportId, $reportItemIds);
        if (empty($items)) {
            throw new InvalidArgumentException('No valid report items found.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            // Get parent estimate number for sub-estimate numbering
            $parentEstimateNumber = $this->getWorkorderEstimateNumber($workorderId);
            $subEstimateNumber = $this->generateSubEstimateNumber($parentEstimateNumber);

            // Create sub-estimate linked to workorder
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO estimates (
                    number, workorder_id, estimate_type, customer_id, vehicle_id,
                    status, internal_notes, subtotal, tax, grand_total, created_at, updated_at
                ) VALUES (
                    :number, :workorder_id, 'sub_estimate', :customer_id, :vehicle_id,
                    'pending', :notes, 0, 0, 0, NOW(), NOW()
                )
            SQL);

            $stmt->execute([
                'number' => $subEstimateNumber,
                'workorder_id' => $workorderId,
                'customer_id' => $workorder['customer_id'],
                'vehicle_id' => $workorder['vehicle_id'],
                'notes' => 'Additional work discovered during inspection #' . $reportId,
            ]);

            $subEstimateId = (int) $pdo->lastInsertId();

            // Add items to sub-estimate
            $result = $this->addToEstimate($reportId, $subEstimateId, $reportItemIds, $actorId, $laborRate, $taxRate);

            $pdo->commit();

            $this->log('inspection.sub_estimate_created', $reportId, $actorId, [
                'workorder_id' => $workorderId,
                'sub_estimate_id' => $subEstimateId,
                'sub_estimate_number' => $subEstimateNumber,
            ]);

            return array_merge($result, [
                'sub_estimate_id' => $subEstimateId,
                'sub_estimate_number' => $subEstimateNumber,
                'workorder_id' => $workorderId,
            ]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Mark a recommendation as declined.
     */
    public function declineRecommendation(int $reportId, int $reportItemId, ?int $actorId = null): bool
    {
        return $this->updateRecommendationStatus($reportId, $reportItemId, 'declined', $actorId);
    }

    /**
     * Mark a recommendation as deferred for later.
     */
    public function deferRecommendation(int $reportId, int $reportItemId, ?int $actorId = null): bool
    {
        return $this->updateRecommendationStatus($reportId, $reportItemId, 'deferred', $actorId);
    }

    // -------------------------------------------------------------------------
    // Private helper methods
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function fetchReport(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inspection_reports WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchEstimate(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM estimates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWorkorder(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM workorders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchReportItemsWithTemplateData(int $reportId): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT
                ri.id,
                ri.report_id,
                ri.template_item_id,
                ri.label,
                ri.response,
                ri.note,
                ii.input_type,
                ii.fail_threshold,
                ii.recommended_service_type_id,
                ii.estimated_labor_hours,
                ii.estimated_parts_cost,
                ii.options,
                ist.name as section_name
            FROM inspection_report_items ri
            JOIN inspection_items ii ON ri.template_item_id = ii.id
            JOIN inspection_sections ist ON ii.section_id = ist.id
            WHERE ri.report_id = :report_id
            ORDER BY ist.display_order ASC, ii.display_order ASC
        SQL);
        $stmt->execute(['report_id' => $reportId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int> $itemIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchReportItemsByIds(int $reportId, array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT
                ri.id,
                ri.report_id,
                ri.template_item_id,
                ri.label,
                ri.response,
                ri.note,
                ii.input_type,
                ii.fail_threshold,
                ii.recommended_service_type_id,
                ii.estimated_labor_hours,
                ii.estimated_parts_cost,
                ii.options,
                ist.name as section_name,
                st.name as service_type_name
            FROM inspection_report_items ri
            JOIN inspection_items ii ON ri.template_item_id = ii.id
            JOIN inspection_sections ist ON ii.section_id = ist.id
            LEFT JOIN service_types st ON ii.recommended_service_type_id = st.id
            WHERE ri.report_id = ? AND ri.id IN ($placeholders)
        SQL);

        $params = array_merge([$reportId], $itemIds);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchExistingRecommendations(int $reportId): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT
                ir.*,
                ri.label,
                ri.response,
                ri.note
            FROM inspection_recommendations ir
            JOIN inspection_report_items ri ON ir.report_item_id = ri.id
            WHERE ir.inspection_report_id = :report_id
            ORDER BY
                FIELD(ir.severity, 'critical', 'high', 'medium', 'low'),
                ir.created_at ASC
        SQL);
        $stmt->execute(['report_id' => $reportId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $item
     * @return array{is_failed: bool, reason: string, severity: string}
     */
    private function evaluateItemFailure(array $item): array
    {
        $inputType = $item['input_type'];
        $response = strtolower(trim((string) $item['response']));
        $threshold = $item['fail_threshold'] ?? (self::DEFAULT_FAIL_THRESHOLDS[$inputType] ?? null);

        if ($threshold === null) {
            return ['is_failed' => false, 'reason' => '', 'severity' => 'low'];
        }

        $isFailed = false;
        $reason = '';
        $severity = 'medium';

        switch ($inputType) {
            case 'boolean':
            case 'boolean_na':
                $isFailed = $response === 'no';
                $reason = $isFailed ? 'Item failed inspection check' : '';
                $severity = $isFailed ? 'high' : 'low';
                break;

            case 'number_scale':
                $numericResponse = (float) $response;
                $failThreshold = (float) $threshold;
                $options = json_decode($item['options'] ?? '{}', true) ?: [];
                $max = $options['max'] ?? 10;

                $isFailed = $numericResponse <= $failThreshold;
                if ($isFailed) {
                    $reason = "Score $response out of $max (threshold: $threshold)";
                    // Severity based on how bad the score is
                    $ratio = $numericResponse / $max;
                    if ($ratio <= 0.2) {
                        $severity = 'critical';
                    } elseif ($ratio <= 0.3) {
                        $severity = 'high';
                    } else {
                        $severity = 'medium';
                    }
                }
                break;

            case 'select_scale':
                $failValues = array_map('trim', explode(',', strtolower($threshold)));
                $isFailed = in_array($response, $failValues, true);
                $reason = $isFailed ? "Status: $response" : '';
                // Determine severity based on common keywords
                if (stripos($response, 'critical') !== false || stripos($response, 'replace') !== false) {
                    $severity = 'critical';
                } elseif (stripos($response, 'fail') !== false || stripos($response, 'poor') !== false) {
                    $severity = 'high';
                }
                break;
        }

        return [
            'is_failed' => $isFailed,
            'reason' => $reason,
            'severity' => $severity,
        ];
    }

    private function isItemAlreadyConverted(int $reportId, int $templateItemId): bool
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT 1 FROM inspection_estimate_conversions
            WHERE inspection_report_id = :report_id AND inspection_item_id = :item_id
            LIMIT 1
        SQL);
        $stmt->execute([
            'report_id' => $reportId,
            'item_id' => $templateItemId,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $item
     */
    private function calculateEstimatedCost(array $item): float
    {
        $laborHours = (float) ($item['estimated_labor_hours'] ?? 1.0);
        $partsCost = (float) ($item['estimated_parts_cost'] ?? 0.0);
        $laborRate = 100.00; // Default labor rate - could be configurable

        return ($laborHours * $laborRate) + $partsCost;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function generateRecommendedAction(array $item): string
    {
        $label = $item['label'];
        $section = $item['section_name'] ?? 'General';
        $response = $item['response'];
        $note = $item['note'] ?? '';

        $action = "Inspect and repair: $label ($section)";

        if (!empty($note)) {
            $action .= ". Technician notes: $note";
        }

        return $action;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function createJobFromInspectionItem(array $item, float $laborRate, float $taxRate): array
    {
        $laborHours = (float) ($item['estimated_labor_hours'] ?? 1.0);
        $partsCost = (float) ($item['estimated_parts_cost'] ?? 0.0);
        $laborCost = $laborHours * $laborRate;

        $items = [];

        // Add labor item
        $items[] = [
            'type' => 'LABOR',
            'description' => 'Labor: ' . $item['label'],
            'quantity' => $laborHours,
            'unit_price' => $laborRate,
            'taxable' => false,
            'line_total' => $laborCost,
        ];

        // Add parts placeholder if estimated
        if ($partsCost > 0) {
            $items[] = [
                'type' => 'PART',
                'description' => 'Parts for: ' . $item['label'] . ' (estimated)',
                'quantity' => 1,
                'unit_price' => $partsCost,
                'taxable' => true,
                'line_total' => $partsCost,
            ];
        }

        $subtotal = $laborCost + $partsCost;
        $taxableAmount = $partsCost; // Only parts are typically taxable
        $tax = $taxableAmount * $taxRate;
        $total = $subtotal + $tax;

        return [
            'service_type_id' => $item['recommended_service_type_id'] ?? null,
            'title' => 'Inspection Finding: ' . $item['label'],
            'notes' => $this->buildJobNotes($item),
            'reference' => 'INSP-' . $item['report_id'] . '-' . $item['id'],
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function buildJobNotes(array $item): string
    {
        $notes = [];
        $notes[] = "Section: " . ($item['section_name'] ?? 'General');
        $notes[] = "Inspection Result: " . $item['response'];

        if (!empty($item['note'])) {
            $notes[] = "Technician Notes: " . $item['note'];
        }

        return implode("\n", $notes);
    }

    /**
     * @param array<string, mixed> $jobData
     */
    private function insertEstimateJob(int $estimateId, array $jobData, int $displayOrder): int
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_jobs
                (estimate_id, service_type_id, title, notes, reference, customer_status, subtotal, tax, total, display_order)
            VALUES
                (:estimate_id, :service_type_id, :title, :notes, :reference, 'pending', :subtotal, :tax, :total, :display_order)
        SQL);

        $stmt->execute([
            'estimate_id' => $estimateId,
            'service_type_id' => $jobData['service_type_id'],
            'title' => $jobData['title'],
            'notes' => $jobData['notes'],
            'reference' => $jobData['reference'],
            'subtotal' => $jobData['subtotal'],
            'tax' => $jobData['tax'],
            'total' => $jobData['total'],
            'display_order' => $displayOrder,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function insertEstimateItems(int $jobId, array $items): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_items
                (estimate_job_id, type, description, quantity, unit_price, taxable, line_total, status)
            VALUES
                (:job_id, :type, :description, :quantity, :unit_price, :taxable, :line_total, 'pending')
        SQL);

        foreach ($items as $item) {
            $stmt->execute([
                'job_id' => $jobId,
                'type' => $item['type'],
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'taxable' => $item['taxable'] ? 1 : 0,
                'line_total' => $item['line_total'],
            ]);
        }
    }

    private function recordConversion(int $reportId, int $templateItemId, int $estimateId, int $jobId, ?int $actorId): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO inspection_estimate_conversions
                (inspection_report_id, inspection_item_id, estimate_id, estimate_job_id, converted_by, created_at)
            VALUES
                (:report_id, :item_id, :estimate_id, :job_id, :actor_id, NOW())
        SQL);

        $stmt->execute([
            'report_id' => $reportId,
            'item_id' => $templateItemId,
            'estimate_id' => $estimateId,
            'job_id' => $jobId,
            'actor_id' => $actorId,
        ]);
    }

    private function updateRecommendationStatus(int $reportId, int $reportItemId, string $status, ?int $actorId): bool
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            UPDATE inspection_recommendations
            SET status = :status, processed_by = :actor_id, processed_at = NOW()
            WHERE inspection_report_id = :report_id AND report_item_id = :item_id
        SQL);

        $stmt->execute([
            'status' => $status,
            'actor_id' => $actorId,
            'report_id' => $reportId,
            'item_id' => $reportItemId,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function recalculateEstimateTotals(int $estimateId): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT
                COALESCE(SUM(subtotal), 0) as subtotal,
                COALESCE(SUM(tax), 0) as tax,
                COALESCE(SUM(total), 0) as grand_total
            FROM estimate_jobs
            WHERE estimate_id = :id
        SQL);
        $stmt->execute(['id' => $estimateId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->connection->pdo()->prepare(<<<SQL
            UPDATE estimates
            SET subtotal = :subtotal, tax = :tax, grand_total = :grand_total, updated_at = NOW()
            WHERE id = :id
        SQL)->execute([
            'subtotal' => $totals['subtotal'],
            'tax' => $totals['tax'],
            'grand_total' => $totals['grand_total'],
            'id' => $estimateId,
        ]);
    }

    private function generateEstimateNumber(): string
    {
        return 'EST-' . date('Ymd-His') . '-' . random_int(100, 999);
    }

    private function getWorkorderEstimateNumber(int $workorderId): string
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT e.number FROM estimates e
            JOIN workorders w ON w.estimate_id = e.id
            WHERE w.id = :id
        SQL);
        $stmt->execute(['id' => $workorderId]);
        return $stmt->fetchColumn() ?: 'EST-' . date('Ymd');
    }

    private function generateSubEstimateNumber(string $parentNumber): string
    {
        $base = $parentNumber . '-INSP';
        $suffix = 1;

        while ($this->estimateNumberExists($base . '-' . $suffix)) {
            $suffix++;
        }

        return $base . '-' . $suffix;
    }

    private function estimateNumberExists(string $number): bool
    {
        $stmt = $this->connection->pdo()->prepare('SELECT 1 FROM estimates WHERE number = :number LIMIT 1');
        $stmt->execute(['number' => $number]);
        return (bool) $stmt->fetchColumn();
    }

    private function log(string $event, int $reportId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'inspection_report', (string) $reportId, $actorId, $context));
    }
}
