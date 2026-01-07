<?php

namespace App\Services\QualityControl;

use App\Database\Connection;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Service for managing Quality Control checklists.
 * QC checks must be completed before workorders can transition to invoicing.
 */
class QCChecklistService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PASSED_WITH_NOTES = 'passed_with_notes';

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    // -------------------------------------------------------------------------
    // Template Management
    // -------------------------------------------------------------------------

    /**
     * List all active QC templates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM qc_templates';
        if (!$includeInactive) {
            $sql .= ' WHERE active = 1';
        }
        $sql .= ' ORDER BY is_default DESC, name ASC';

        $stmt = $this->connection->pdo()->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a template with its items.
     *
     * @return array<string, mixed>|null
     */
    public function getTemplate(int $templateId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM qc_templates WHERE id = :id');
        $stmt->execute(['id' => $templateId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            return null;
        }

        $template['items'] = $this->getTemplateItems($templateId);

        return $template;
    }

    /**
     * Get template items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTemplateItems(int $templateId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM qc_template_items WHERE template_id = :id ORDER BY display_order ASC'
        );
        $stmt->execute(['id' => $templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the default QC template.
     *
     * @return array<string, mixed>|null
     */
    public function getDefaultTemplate(): ?array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT * FROM qc_templates WHERE is_default = 1 AND active = 1 LIMIT 1'
        );
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            return null;
        }

        $template['items'] = $this->getTemplateItems((int) $template['id']);

        return $template;
    }

    /**
     * Get the appropriate template for a workorder based on service types.
     *
     * @return array<string, mixed>|null
     */
    public function getTemplateForWorkorder(int $workorderId): ?array
    {
        // First, try to find a template matching the workorder's service types
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT DISTINCT qt.*
            FROM qc_templates qt
            JOIN workorder_jobs wj ON qt.service_type_id = wj.service_type_id
            WHERE wj.workorder_id = :workorder_id AND qt.active = 1
            LIMIT 1
        SQL);
        $stmt->execute(['workorder_id' => $workorderId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fall back to default template
        if (!$template) {
            return $this->getDefaultTemplate();
        }

        $template['items'] = $this->getTemplateItems((int) $template['id']);

        return $template;
    }

    /**
     * Create a new QC template.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createTemplate(array $data, ?int $actorId = null): array
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO qc_templates (name, description, service_type_id, is_default, active, created_by, created_at, updated_at)
                VALUES (:name, :description, :service_type_id, :is_default, 1, :created_by, NOW(), NOW())
            SQL);

            $stmt->execute([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'service_type_id' => $data['service_type_id'] ?? null,
                'is_default' => !empty($data['is_default']) ? 1 : 0,
                'created_by' => $actorId,
            ]);

            $templateId = (int) $pdo->lastInsertId();

            // Add items if provided
            if (!empty($data['items']) && is_array($data['items'])) {
                $this->addTemplateItems($templateId, $data['items']);
            }

            $pdo->commit();

            $this->log('qc.template_created', $templateId, $actorId, ['name' => $data['name']]);

            return $this->getTemplate($templateId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Add items to a template.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function addTemplateItems(int $templateId, array $items): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO qc_template_items (template_id, label, description, category, required, display_order)
            VALUES (:template_id, :label, :description, :category, :required, :display_order)
        SQL);

        $order = 0;
        foreach ($items as $item) {
            $stmt->execute([
                'template_id' => $templateId,
                'label' => $item['label'],
                'description' => $item['description'] ?? null,
                'category' => $item['category'] ?? null,
                'required' => !empty($item['required']) ? 1 : 0,
                'display_order' => $item['display_order'] ?? $order,
            ]);
            $order++;
        }
    }

    // -------------------------------------------------------------------------
    // QC Check Management
    // -------------------------------------------------------------------------

    /**
     * Initialize a QC check for a workorder.
     *
     * @return array<string, mixed>
     */
    public function initializeCheck(int $workorderId, ?int $templateId = null, ?int $actorId = null): array
    {
        // Get existing check
        $existing = $this->getCheckForWorkorder($workorderId);
        if ($existing !== null) {
            return $existing;
        }

        // Get template
        if ($templateId === null) {
            $template = $this->getTemplateForWorkorder($workorderId);
            if ($template === null) {
                throw new InvalidArgumentException('No QC template available for this workorder.');
            }
            $templateId = (int) $template['id'];
        } else {
            $template = $this->getTemplate($templateId);
            if ($template === null) {
                throw new InvalidArgumentException('QC template not found.');
            }
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            // Create QC check
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO qc_checks (workorder_id, template_id, status, created_at, updated_at)
                VALUES (:workorder_id, :template_id, 'pending', NOW(), NOW())
            SQL);

            $stmt->execute([
                'workorder_id' => $workorderId,
                'template_id' => $templateId,
            ]);

            $checkId = (int) $pdo->lastInsertId();

            // Create check items from template
            $stmt = $pdo->prepare(<<<SQL
                INSERT INTO qc_check_items (qc_check_id, template_item_id)
                SELECT :check_id, id FROM qc_template_items WHERE template_id = :template_id
            SQL);

            $stmt->execute([
                'check_id' => $checkId,
                'template_id' => $templateId,
            ]);

            $pdo->commit();

            $this->log('qc.check_initialized', $checkId, $actorId, [
                'workorder_id' => $workorderId,
                'template_id' => $templateId,
            ]);

            return $this->getCheck($checkId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get a QC check by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getCheck(int $checkId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT qc.*, qt.name as template_name, qt.description as template_description
            FROM qc_checks qc
            JOIN qc_templates qt ON qc.template_id = qt.id
            WHERE qc.id = :id
        SQL);
        $stmt->execute(['id' => $checkId]);
        $check = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$check) {
            return null;
        }

        $check['items'] = $this->getCheckItems($checkId);

        return $check;
    }

    /**
     * Get QC check for a workorder.
     *
     * @return array<string, mixed>|null
     */
    public function getCheckForWorkorder(int $workorderId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM qc_checks WHERE workorder_id = :workorder_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['workorder_id' => $workorderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->getCheck((int) $row['id']);
    }

    /**
     * Get check items with template details.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCheckItems(int $checkId): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT qci.*, qti.label, qti.description, qti.category, qti.required, qti.display_order
            FROM qc_check_items qci
            JOIN qc_template_items qti ON qci.template_item_id = qti.id
            WHERE qci.qc_check_id = :check_id
            ORDER BY qti.display_order ASC
        SQL);
        $stmt->execute(['check_id' => $checkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update a single check item.
     *
     * @return array<string, mixed>
     */
    public function updateCheckItem(
        int $checkItemId,
        bool $passed,
        ?string $notes = null,
        ?int $actorId = null
    ): array {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            UPDATE qc_check_items SET
                passed = :passed,
                notes = :notes,
                checked_by = :checked_by,
                checked_at = NOW()
            WHERE id = :id
        SQL);

        $stmt->execute([
            'passed' => $passed ? 1 : 0,
            'notes' => $notes,
            'checked_by' => $actorId,
            'id' => $checkItemId,
        ]);

        // Get the check ID and update overall status
        $stmt = $this->connection->pdo()->prepare(
            'SELECT qc_check_id FROM qc_check_items WHERE id = :id'
        );
        $stmt->execute(['id' => $checkItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->updateCheckStatus((int) $row['qc_check_id']);
        }

        return $this->getCheckItemById($checkItemId);
    }

    /**
     * Update multiple check items at once.
     *
     * @param array<int, array{passed: bool, notes?: string|null}> $items
     * @return array<string, mixed>
     */
    public function updateCheckItems(int $checkId, array $items, ?int $actorId = null): array
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(<<<SQL
                UPDATE qc_check_items SET
                    passed = :passed,
                    notes = :notes,
                    checked_by = :checked_by,
                    checked_at = NOW()
                WHERE qc_check_id = :check_id AND template_item_id = :item_id
            SQL);

            foreach ($items as $itemId => $data) {
                $stmt->execute([
                    'passed' => !empty($data['passed']) ? 1 : 0,
                    'notes' => $data['notes'] ?? null,
                    'checked_by' => $actorId,
                    'check_id' => $checkId,
                    'item_id' => $itemId,
                ]);
            }

            // Mark check as in_progress if still pending
            $this->connection->pdo()->prepare(<<<SQL
                UPDATE qc_checks SET
                    status = 'in_progress',
                    started_at = COALESCE(started_at, NOW()),
                    updated_at = NOW()
                WHERE id = :id AND status = 'pending'
            SQL)->execute(['id' => $checkId]);

            $pdo->commit();

            // Update overall status
            $this->updateCheckStatus($checkId);

            return $this->getCheck($checkId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Complete a QC check.
     *
     * @return array<string, mixed>
     */
    public function completeCheck(int $checkId, ?string $notes = null, ?int $actorId = null): array
    {
        $check = $this->getCheck($checkId);
        if ($check === null) {
            throw new InvalidArgumentException('QC check not found.');
        }

        // Verify all required items are checked
        $uncheckedRequired = $this->getUncheckedRequiredItems($checkId);
        if (!empty($uncheckedRequired)) {
            throw new InvalidArgumentException(
                'Cannot complete QC check. ' . count($uncheckedRequired) . ' required items are not checked.'
            );
        }

        // Determine final status
        $hasFailedItems = $this->hasFailedItems($checkId);
        $hasNotes = !empty($notes) || $this->hasItemNotes($checkId);

        if ($hasFailedItems) {
            $status = self::STATUS_FAILED;
        } elseif ($hasNotes) {
            $status = self::STATUS_PASSED_WITH_NOTES;
        } else {
            $status = self::STATUS_PASSED;
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            UPDATE qc_checks SET
                status = :status,
                completed_at = NOW(),
                completed_by = :completed_by,
                overall_notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        SQL);

        $stmt->execute([
            'status' => $status,
            'completed_by' => $actorId,
            'notes' => $notes,
            'id' => $checkId,
        ]);

        $this->log('qc.check_completed', $checkId, $actorId, [
            'status' => $status,
            'workorder_id' => $check['workorder_id'],
        ]);

        return $this->getCheck($checkId);
    }

    /**
     * Check if a workorder has passed QC.
     */
    public function hasPassedQC(int $workorderId): bool
    {
        $check = $this->getCheckForWorkorder($workorderId);

        if ($check === null) {
            return false;
        }

        return in_array($check['status'], [self::STATUS_PASSED, self::STATUS_PASSED_WITH_NOTES], true);
    }

    /**
     * Check if QC is required before invoicing.
     */
    public function isQCRequiredForInvoicing(): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT `value` FROM settings WHERE `key` = 'qc_required_for_invoice'"
        );
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return $value === 'true' || $value === '1';
    }

    /**
     * Check if QC is enabled globally.
     */
    public function isQCEnabled(): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT `value` FROM settings WHERE `key` = 'qc_enabled'"
        );
        $stmt->execute();
        $value = $stmt->fetchColumn();

        return $value === 'true' || $value === '1';
    }

    /**
     * Validate if a workorder can be converted to invoice.
     * Throws exception if QC is required but not passed.
     */
    public function validateForInvoiceConversion(int $workorderId): void
    {
        if (!$this->isQCEnabled() || !$this->isQCRequiredForInvoicing()) {
            return;
        }

        if (!$this->hasPassedQC($workorderId)) {
            throw new InvalidArgumentException(
                'QC check must be passed before converting workorder to invoice.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function getCheckItemById(int $itemId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT qci.*, qti.label, qti.description, qti.category, qti.required
            FROM qc_check_items qci
            JOIN qc_template_items qti ON qci.template_item_id = qti.id
            WHERE qci.id = :id
        SQL);
        $stmt->execute(['id' => $itemId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getUncheckedRequiredItems(int $checkId): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT qci.*, qti.label
            FROM qc_check_items qci
            JOIN qc_template_items qti ON qci.template_item_id = qti.id
            WHERE qci.qc_check_id = :check_id AND qti.required = 1 AND qci.passed IS NULL
        SQL);
        $stmt->execute(['check_id' => $checkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hasFailedItems(int $checkId): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT 1 FROM qc_check_items WHERE qc_check_id = :id AND passed = 0 LIMIT 1'
        );
        $stmt->execute(['id' => $checkId]);
        return (bool) $stmt->fetchColumn();
    }

    private function hasItemNotes(int $checkId): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT 1 FROM qc_check_items WHERE qc_check_id = :id AND notes IS NOT NULL AND notes != '' LIMIT 1"
        );
        $stmt->execute(['id' => $checkId]);
        return (bool) $stmt->fetchColumn();
    }

    private function updateCheckStatus(int $checkId): void
    {
        $items = $this->getCheckItems($checkId);

        $hasUnchecked = false;
        $hasRequired = false;

        foreach ($items as $item) {
            if ($item['passed'] === null) {
                $hasUnchecked = true;
                if ($item['required']) {
                    $hasRequired = true;
                }
            }
        }

        // Only update to in_progress if there are still items to check
        if ($hasUnchecked) {
            $this->connection->pdo()->prepare(<<<SQL
                UPDATE qc_checks SET
                    status = 'in_progress',
                    started_at = COALESCE(started_at, NOW()),
                    updated_at = NOW()
                WHERE id = :id AND status IN ('pending', 'in_progress')
            SQL)->execute(['id' => $checkId]);
        }
    }

    private function log(string $event, int $checkId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'qc_check', (string) $checkId, $actorId, $context));
    }
}
