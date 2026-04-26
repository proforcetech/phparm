<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionEscalation;
use App\Models\InspectionEscalationRule;
use PDO;

/**
 * Phase 8.3 of docs/expansion-plan.md — CRUD for
 * inspection_escalation_rules and inspection_escalations.
 *
 * Rule surface: create/findById/update/delete/listForDivision.
 * Escalation surface: create/findById/listForReport/acknowledge/
 * resolve/listOpenForUser/listOpenForRole + the UNIQUE-backed
 * `hasEscalation(rule, report, item)` used for per-item idempotency.
 *
 * Flat CRUD; service layer owns validation + audit + notification.
 */
class InspectionEscalationRepository
{
    private const RULE_COLUMNS = 'id, division_id, name, trigger_severity, compliance_tag_id, assign_to_user_id, assign_to_role, notify_via, notification_template, priority, require_acknowledgment, is_active, sort_order, created_by_user_id, created_at, updated_at';

    private const ESC_COLUMNS = 'id, rule_id, inspection_report_id, inspection_report_item_id, priority, severity, assigned_to_user_id, assigned_to_role, status, notification_status, notification_error, acknowledged_by_user_id, acknowledged_at, resolved_by_user_id, resolved_at, resolution_note, created_by_user_id, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    // ── Rules ────────────────────────────────────────────────────────────

    /**
     * @param array{
     *   division_id?: ?int,
     *   name: string,
     *   trigger_severity?: string,
     *   compliance_tag_id?: ?int,
     *   assign_to_user_id?: ?int,
     *   assign_to_role?: ?string,
     *   notify_via?: ?string,
     *   notification_template?: ?string,
     *   priority?: string,
     *   require_acknowledgment?: bool,
     *   is_active?: bool,
     *   sort_order?: int,
     *   created_by_user_id?: ?int
     * } $data
     */
    public function createRule(array $data): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO inspection_escalation_rules
                (division_id, name, trigger_severity, compliance_tag_id, assign_to_user_id,
                 assign_to_role, notify_via, notification_template, priority,
                 require_acknowledgment, is_active, sort_order, created_by_user_id,
                 created_at, updated_at)
             VALUES
                (:division_id, :name, :trigger_severity, :compliance_tag_id, :assign_to_user_id,
                 :assign_to_role, :notify_via, :notification_template, :priority,
                 :require_acknowledgment, :is_active, :sort_order, :created_by_user_id,
                 CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'division_id' => $data['division_id'] ?? null,
            'name' => $data['name'],
            'trigger_severity' => $data['trigger_severity'] ?? InspectionEscalationRule::SEVERITY_CRITICAL,
            'compliance_tag_id' => $data['compliance_tag_id'] ?? null,
            'assign_to_user_id' => $data['assign_to_user_id'] ?? null,
            'assign_to_role' => $data['assign_to_role'] ?? null,
            'notify_via' => $data['notify_via'] ?? null,
            'notification_template' => $data['notification_template'] ?? null,
            'priority' => $data['priority'] ?? InspectionEscalationRule::PRIORITY_NORMAL,
            'require_acknowledgment' => ($data['require_acknowledgment'] ?? true) ? 1 : 0,
            'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findRuleById(int $id): ?InspectionEscalationRule
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::RULE_COLUMNS . ' FROM inspection_escalation_rules WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRule($row) : null;
    }

    /**
     * @param array{
     *   name: string,
     *   trigger_severity?: string,
     *   compliance_tag_id?: ?int,
     *   assign_to_user_id?: ?int,
     *   assign_to_role?: ?string,
     *   notify_via?: ?string,
     *   notification_template?: ?string,
     *   priority?: string,
     *   require_acknowledgment?: bool,
     *   is_active?: bool,
     *   sort_order?: int
     * } $data
     */
    public function updateRule(int $id, array $data): void
    {
        // division_id deliberately omitted — see 8.2 policy repo for rationale.
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inspection_escalation_rules
             SET name = :name,
                 trigger_severity = :trigger_severity,
                 compliance_tag_id = :compliance_tag_id,
                 assign_to_user_id = :assign_to_user_id,
                 assign_to_role = :assign_to_role,
                 notify_via = :notify_via,
                 notification_template = :notification_template,
                 priority = :priority,
                 require_acknowledgment = :require_acknowledgment,
                 is_active = :is_active,
                 sort_order = :sort_order,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $data['name'],
            'trigger_severity' => $data['trigger_severity'] ?? InspectionEscalationRule::SEVERITY_CRITICAL,
            'compliance_tag_id' => $data['compliance_tag_id'] ?? null,
            'assign_to_user_id' => $data['assign_to_user_id'] ?? null,
            'assign_to_role' => $data['assign_to_role'] ?? null,
            'notify_via' => $data['notify_via'] ?? null,
            'notification_template' => $data['notification_template'] ?? null,
            'priority' => $data['priority'] ?? InspectionEscalationRule::PRIORITY_NORMAL,
            'require_acknowledgment' => ($data['require_acknowledgment'] ?? true) ? 1 : 0,
            'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
            'sort_order' => $data['sort_order'] ?? 0,
            'id' => $id,
        ]);
    }

    public function deleteRule(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM inspection_escalation_rules WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * List rules visible to a division. Globals (division_id NULL) always
     * included.
     *
     * @return array<int, InspectionEscalationRule>
     */
    public function listRulesForDivision(?int $divisionId, bool $activeOnly = false): array
    {
        $where = '(division_id IS NULL';
        $params = [];
        if ($divisionId !== null) {
            $where .= ' OR division_id = :division_id';
            $params['division_id'] = $divisionId;
        }
        $where .= ')';
        if ($activeOnly) {
            $where .= ' AND is_active = 1';
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::RULE_COLUMNS . ' FROM inspection_escalation_rules
             WHERE ' . $where . '
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute($params);
        return array_map(
            fn(array $r) => $this->hydrateRule($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    // ── Escalation records ───────────────────────────────────────────────

    /**
     * UNIQUE (rule_id, report_id, item_id) backs this idempotency check.
     */
    public function hasEscalation(int $ruleId, int $reportId, int $itemId): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT 1 FROM inspection_escalations
             WHERE rule_id = :rule_id
               AND inspection_report_id = :report_id
               AND inspection_report_item_id = :item_id
             LIMIT 1'
        );
        $stmt->execute([
            'rule_id' => $ruleId,
            'report_id' => $reportId,
            'item_id' => $itemId,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array{
     *   rule_id: int,
     *   inspection_report_id: int,
     *   inspection_report_item_id: int,
     *   priority?: string,
     *   severity?: string,
     *   assigned_to_user_id?: ?int,
     *   assigned_to_role?: ?string,
     *   status?: string,
     *   notification_status?: ?string,
     *   notification_error?: ?string,
     *   created_by_user_id?: ?int
     * } $data
     */
    public function createEscalation(array $data): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO inspection_escalations
                (rule_id, inspection_report_id, inspection_report_item_id, priority, severity,
                 assigned_to_user_id, assigned_to_role, status, notification_status,
                 notification_error, created_by_user_id, created_at, updated_at)
             VALUES
                (:rule_id, :report_id, :item_id, :priority, :severity,
                 :assigned_user, :assigned_role, :status, :notification_status,
                 :notification_error, :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'rule_id' => $data['rule_id'],
            'report_id' => $data['inspection_report_id'],
            'item_id' => $data['inspection_report_item_id'],
            'priority' => $data['priority'] ?? InspectionEscalationRule::PRIORITY_NORMAL,
            'severity' => $data['severity'] ?? InspectionEscalationRule::SEVERITY_HIGH,
            'assigned_user' => $data['assigned_to_user_id'] ?? null,
            'assigned_role' => $data['assigned_to_role'] ?? null,
            'status' => $data['status'] ?? InspectionEscalation::STATUS_PENDING,
            'notification_status' => $data['notification_status'] ?? null,
            'notification_error' => $data['notification_error'] ?? null,
            'created_by' => $data['created_by_user_id'] ?? null,
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findEscalationById(int $id): ?InspectionEscalation
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::ESC_COLUMNS . ' FROM inspection_escalations WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrateEscalation($row) : null;
    }

    /**
     * @return array<int, InspectionEscalation>
     */
    public function listEscalationsForReport(int $reportId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::ESC_COLUMNS . ' FROM inspection_escalations
             WHERE inspection_report_id = :report_id
             ORDER BY id ASC'
        );
        $stmt->execute(['report_id' => $reportId]);
        return array_map(
            fn(array $r) => $this->hydrateEscalation($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Open escalations (pending + acknowledged) assigned to a user.
     *
     * @return array<int, InspectionEscalation>
     */
    public function listOpenEscalationsForUser(int $userId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::ESC_COLUMNS . ' FROM inspection_escalations
             WHERE assigned_to_user_id = :user_id
               AND status IN (:pending, :ack)
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([
            'user_id' => $userId,
            'pending' => InspectionEscalation::STATUS_PENDING,
            'ack' => InspectionEscalation::STATUS_ACKNOWLEDGED,
        ]);
        return array_map(
            fn(array $r) => $this->hydrateEscalation($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Open escalations (pending + acknowledged) routed to a role queue.
     *
     * @return array<int, InspectionEscalation>
     */
    public function listOpenEscalationsForRole(string $role): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::ESC_COLUMNS . ' FROM inspection_escalations
             WHERE assigned_to_role = :role
               AND status IN (:pending, :ack)
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([
            'role' => $role,
            'pending' => InspectionEscalation::STATUS_PENDING,
            'ack' => InspectionEscalation::STATUS_ACKNOWLEDGED,
        ]);
        return array_map(
            fn(array $r) => $this->hydrateEscalation($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function markAcknowledged(int $id, int $userId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inspection_escalations
             SET status = :status,
                 acknowledged_by_user_id = :user_id,
                 acknowledged_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => InspectionEscalation::STATUS_ACKNOWLEDGED,
            'user_id' => $userId,
            'id' => $id,
        ]);
    }

    public function markResolved(int $id, int $userId, ?string $note): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inspection_escalations
             SET status = :status,
                 resolved_by_user_id = :user_id,
                 resolved_at = CURRENT_TIMESTAMP,
                 resolution_note = :note,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => InspectionEscalation::STATUS_RESOLVED,
            'user_id' => $userId,
            'note' => $note,
            'id' => $id,
        ]);
    }

    public function updateNotificationStatus(int $id, ?string $status, ?string $error): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inspection_escalations
             SET notification_status = :status,
                 notification_error = :error,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'error' => $error,
            'id' => $id,
        ]);
    }

    // ── Hydration ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRule(array $row): InspectionEscalationRule
    {
        return new InspectionEscalationRule([
            'id' => (int) $row['id'],
            'division_id' => $row['division_id'] !== null ? (int) $row['division_id'] : null,
            'name' => (string) $row['name'],
            'trigger_severity' => (string) $row['trigger_severity'],
            'compliance_tag_id' => $row['compliance_tag_id'] !== null ? (int) $row['compliance_tag_id'] : null,
            'assign_to_user_id' => $row['assign_to_user_id'] !== null ? (int) $row['assign_to_user_id'] : null,
            'assign_to_role' => $row['assign_to_role'],
            'notify_via' => $row['notify_via'],
            'notification_template' => $row['notification_template'],
            'priority' => (string) $row['priority'],
            'require_acknowledgment' => (bool) $row['require_acknowledgment'],
            'is_active' => (bool) $row['is_active'],
            'sort_order' => (int) $row['sort_order'],
            'created_by_user_id' => $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateEscalation(array $row): InspectionEscalation
    {
        return new InspectionEscalation([
            'id' => (int) $row['id'],
            'rule_id' => (int) $row['rule_id'],
            'inspection_report_id' => (int) $row['inspection_report_id'],
            'inspection_report_item_id' => (int) $row['inspection_report_item_id'],
            'priority' => (string) $row['priority'],
            'severity' => (string) $row['severity'],
            'assigned_to_user_id' => $row['assigned_to_user_id'] !== null ? (int) $row['assigned_to_user_id'] : null,
            'assigned_to_role' => $row['assigned_to_role'],
            'status' => (string) $row['status'],
            'notification_status' => $row['notification_status'],
            'notification_error' => $row['notification_error'],
            'acknowledged_by_user_id' => $row['acknowledged_by_user_id'] !== null ? (int) $row['acknowledged_by_user_id'] : null,
            'acknowledged_at' => $row['acknowledged_at'] ?? null,
            'resolved_by_user_id' => $row['resolved_by_user_id'] !== null ? (int) $row['resolved_by_user_id'] : null,
            'resolved_at' => $row['resolved_at'] ?? null,
            'resolution_note' => $row['resolution_note'],
            'created_by_user_id' => $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }
}
