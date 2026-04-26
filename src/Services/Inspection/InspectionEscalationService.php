<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionEscalation;
use App\Models\InspectionEscalationRule;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Notifications\NotificationDispatcher;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Phase 8.3 of docs/expansion-plan.md — human-in-the-loop failure
 * escalation.
 *
 * Rules declare: "when an inspection report completes with a failed
 * item at severity >= X (and optionally tagged Y), open an escalation
 * record routed to user Z or role R, and best-effort dispatch a
 * notification".
 *
 * One escalation row per (rule, report, report_item) — UNIQUE in the
 * DB guarantees idempotency across repeated evaluations. Lifecycle:
 * pending → acknowledged → resolved. Notification failures are
 * recorded on the escalation itself (notification_status/error) and
 * never block creation of the escalation or completion of the report.
 *
 * Gates: rule CRUD + manual evaluateReport → inspections.manage.
 * acknowledge / resolve / list-for-user → inspections.view (anyone
 * who can see inspections can drive their own queue). The completion
 * hook path (`evaluateReportOnCompletion`) has no User in scope and
 * swallows throwables so a misconfigured rule can't block the report.
 */
class InspectionEscalationService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InspectionEscalationRepository $repo,
        private readonly InspectionEstimateBridgeService $bridge,
        private readonly AccessGate $gate,
        private readonly ?AuditLogger $audit = null,
        private readonly ?NotificationDispatcher $notifications = null,
    ) {
    }

    // ── Rule CRUD ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createRule(User $actor, array $input): array
    {
        $this->gate->assert($actor, 'inspections.manage');
        $fields = $this->validateRuleInput($input);
        $fields['created_by_user_id'] = $actor->id;
        $id = $this->repo->createRule($fields);

        $this->log('inspection.escalation_rule.created', $id, $actor->id, [
            'name' => $fields['name'],
            'trigger_severity' => $fields['trigger_severity'],
            'priority' => $fields['priority'],
            'division_id' => $fields['division_id'],
            'assign_to_user_id' => $fields['assign_to_user_id'],
            'assign_to_role' => $fields['assign_to_role'],
        ]);

        $rule = $this->repo->findRuleById($id);
        if ($rule === null) {
            throw new \RuntimeException("escalation rule {$id} vanished after insert");
        }
        return $this->serializeRule($rule);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateRule(User $actor, int $id, array $input): array
    {
        $this->gate->assert($actor, 'inspections.manage');
        $existing = $this->repo->findRuleById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("escalation rule {$id} not found");
        }

        // Merge-then-validate: partial-PUT callers don't null fields
        // they didn't pass. division_id is immutable (see 8.2 policy
        // repo for rationale).
        $merged = [
            'division_id' => $existing->division_id,
            'name' => $input['name'] ?? $existing->name,
            'trigger_severity' => $input['trigger_severity'] ?? $existing->trigger_severity,
            'compliance_tag_id' => array_key_exists('compliance_tag_id', $input)
                ? $input['compliance_tag_id'] : $existing->compliance_tag_id,
            'assign_to_user_id' => array_key_exists('assign_to_user_id', $input)
                ? $input['assign_to_user_id'] : $existing->assign_to_user_id,
            'assign_to_role' => array_key_exists('assign_to_role', $input)
                ? $input['assign_to_role'] : $existing->assign_to_role,
            'notify_via' => array_key_exists('notify_via', $input)
                ? $input['notify_via'] : $existing->notify_via,
            'notification_template' => array_key_exists('notification_template', $input)
                ? $input['notification_template'] : $existing->notification_template,
            'priority' => $input['priority'] ?? $existing->priority,
            'require_acknowledgment' => array_key_exists('require_acknowledgment', $input)
                ? (bool) $input['require_acknowledgment'] : $existing->require_acknowledgment,
            'is_active' => array_key_exists('is_active', $input)
                ? (bool) $input['is_active'] : $existing->is_active,
            'sort_order' => array_key_exists('sort_order', $input)
                ? (int) $input['sort_order'] : $existing->sort_order,
        ];
        $fields = $this->validateRuleInput($merged);
        unset($fields['division_id'], $fields['created_by_user_id']);

        $this->repo->updateRule($id, $fields);

        $this->log('inspection.escalation_rule.updated', $id, $actor->id, [
            'name' => $fields['name'],
            'trigger_severity' => $fields['trigger_severity'],
            'is_active' => $fields['is_active'],
        ]);

        $fresh = $this->repo->findRuleById($id);
        if ($fresh === null) {
            throw new \RuntimeException("escalation rule {$id} vanished after update");
        }
        return $this->serializeRule($fresh);
    }

    public function deleteRule(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'inspections.manage');
        $existing = $this->repo->findRuleById($id);
        if ($existing === null) {
            return; // idempotent
        }
        $this->repo->deleteRule($id);
        $this->log('inspection.escalation_rule.deleted', $id, $actor->id, [
            'name' => $existing->name,
            'division_id' => $existing->division_id,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRules(User $actor, ?int $divisionId = null, bool $activeOnly = false): array
    {
        $this->gate->assert($actor, 'inspections.view');
        return array_map(
            fn(InspectionEscalationRule $r) => $this->serializeRule($r),
            $this->repo->listRulesForDivision($divisionId, $activeOnly),
        );
    }

    // ── Evaluation ───────────────────────────────────────────────────────

    /**
     * Manual entry point — gated inspections.manage. Returns the list
     * of escalation records created (one per matching (rule, item)).
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluateReport(User $actor, int $reportId): array
    {
        $this->gate->assert($actor, 'inspections.manage');
        return $this->runEvaluation($reportId, $actor->id);
    }

    /**
     * Hook variant — swallows all throwables so a misconfigured rule
     * can't block inspection completion. Records the failure in audit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluateReportOnCompletion(int $reportId, ?int $actorId): array
    {
        try {
            return $this->runEvaluation($reportId, $actorId);
        } catch (Throwable $e) {
            $this->log('inspection.escalation.hook_failure', $reportId, $actorId, [
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEscalationsForReport(User $actor, int $reportId): array
    {
        $this->gate->assert($actor, 'inspections.view');
        return array_map(
            fn(InspectionEscalation $e) => $this->serializeEscalation($e),
            $this->repo->listEscalationsForReport($reportId),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOpenForMe(User $actor): array
    {
        $this->gate->assert($actor, 'inspections.view');
        return array_map(
            fn(InspectionEscalation $e) => $this->serializeEscalation($e),
            $this->repo->listOpenEscalationsForUser($actor->id),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOpenForRole(User $actor, string $role): array
    {
        $this->gate->assert($actor, 'inspections.view');
        $role = strtolower(trim($role));
        if ($role === '') {
            throw new InvalidArgumentException('role is required');
        }
        return array_map(
            fn(InspectionEscalation $e) => $this->serializeEscalation($e),
            $this->repo->listOpenEscalationsForRole($role),
        );
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function acknowledge(User $actor, int $escalationId): array
    {
        $this->gate->assert($actor, 'inspections.view');
        $esc = $this->repo->findEscalationById($escalationId);
        if ($esc === null) {
            throw new InvalidArgumentException("escalation {$escalationId} not found");
        }
        if ($esc->status !== InspectionEscalation::STATUS_PENDING) {
            // Idempotent — already acknowledged or resolved.
            return $this->serializeEscalation($esc);
        }
        $this->repo->markAcknowledged($escalationId, $actor->id);
        $this->log('inspection.escalation.acknowledged', $escalationId, $actor->id, [
            'rule_id' => $esc->rule_id,
            'report_id' => $esc->inspection_report_id,
        ]);
        $fresh = $this->repo->findEscalationById($escalationId);
        if ($fresh === null) {
            throw new \RuntimeException("escalation {$escalationId} vanished after update");
        }
        return $this->serializeEscalation($fresh);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(User $actor, int $escalationId, ?string $note): array
    {
        $this->gate->assert($actor, 'inspections.view');
        $esc = $this->repo->findEscalationById($escalationId);
        if ($esc === null) {
            throw new InvalidArgumentException("escalation {$escalationId} not found");
        }
        if ($esc->status === InspectionEscalation::STATUS_RESOLVED) {
            return $this->serializeEscalation($esc); // idempotent
        }
        $note = $note !== null ? trim($note) : null;
        if ($note !== null && strlen($note) > InspectionEscalation::RESOLUTION_NOTE_MAX_LEN) {
            throw new InvalidArgumentException(
                'resolution note exceeds ' . InspectionEscalation::RESOLUTION_NOTE_MAX_LEN . ' chars'
            );
        }
        $this->repo->markResolved($escalationId, $actor->id, $note !== '' ? $note : null);
        $this->log('inspection.escalation.resolved', $escalationId, $actor->id, [
            'rule_id' => $esc->rule_id,
            'report_id' => $esc->inspection_report_id,
            'had_note' => $note !== null && $note !== '',
        ]);
        $fresh = $this->repo->findEscalationById($escalationId);
        if ($fresh === null) {
            throw new \RuntimeException("escalation {$escalationId} vanished after update");
        }
        return $this->serializeEscalation($fresh);
    }

    // ── Internal evaluation pipeline ─────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runEvaluation(int $reportId, ?int $actorId): array
    {
        $reportContext = $this->fetchReportContext($reportId);
        if ($reportContext === null) {
            throw new InvalidArgumentException("inspection report {$reportId} not found");
        }

        $failedItems = $this->bridge->identifyFailedItems($reportId);
        if ($failedItems === []) {
            return [];
        }

        $itemIds = array_map(static fn($i) => (int) $i['id'], $failedItems);
        $tagByReportItem = $this->fetchComplianceTagByReportItem($itemIds);

        $rules = $this->repo->listRulesForDivision($reportContext['division_id'], true);
        $created = [];
        foreach ($rules as $rule) {
            if (!$rule->is_active) {
                continue;
            }
            $minLevel = InspectionEscalationRule::SEVERITY_ORDER[$rule->trigger_severity] ?? 4;
            foreach ($failedItems as $item) {
                $severity = (string) ($item['severity'] ?? 'low');
                $level = InspectionEscalationRule::SEVERITY_ORDER[$severity] ?? 0;
                if ($level < $minLevel) {
                    continue;
                }
                if ($rule->compliance_tag_id !== null) {
                    $itemTag = $tagByReportItem[(int) $item['id']] ?? null;
                    if ($itemTag !== $rule->compliance_tag_id) {
                        continue;
                    }
                }
                if ($this->repo->hasEscalation($rule->id, $reportId, (int) $item['id'])) {
                    continue; // idempotent
                }
                $record = $this->createEscalationRecord($rule, $reportId, (int) $item['id'], $severity, $actorId);
                $created[] = $record;
            }
        }

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    private function createEscalationRecord(
        InspectionEscalationRule $rule,
        int $reportId,
        int $itemId,
        string $severity,
        ?int $actorId,
    ): array {
        $id = $this->repo->createEscalation([
            'rule_id' => $rule->id,
            'inspection_report_id' => $reportId,
            'inspection_report_item_id' => $itemId,
            'priority' => $rule->priority,
            'severity' => $severity,
            'assigned_to_user_id' => $rule->assign_to_user_id,
            'assigned_to_role' => $rule->assign_to_role,
            'status' => InspectionEscalation::STATUS_PENDING,
            'notification_status' => $rule->notify_via !== null
                ? InspectionEscalation::NOTIFY_STATUS_PENDING
                : InspectionEscalation::NOTIFY_STATUS_SKIPPED,
            'created_by_user_id' => $actorId,
        ]);

        $this->log('inspection.escalation.created', $id, $actorId, [
            'rule_id' => $rule->id,
            'report_id' => $reportId,
            'report_item_id' => $itemId,
            'severity' => $severity,
            'priority' => $rule->priority,
            'assigned_to_user_id' => $rule->assign_to_user_id,
            'assigned_to_role' => $rule->assign_to_role,
        ]);

        $this->dispatchNotification($id, $rule, $reportId, $itemId, $severity);

        $fresh = $this->repo->findEscalationById($id);
        return $fresh !== null
            ? $this->serializeEscalation($fresh)
            : ['id' => $id];
    }

    /**
     * Best-effort notification. Never throws. Records outcome on the
     * escalation row via updateNotificationStatus.
     */
    private function dispatchNotification(
        int $escalationId,
        InspectionEscalationRule $rule,
        int $reportId,
        int $itemId,
        string $severity,
    ): void {
        if ($rule->notify_via === null) {
            return; // nothing to do
        }
        if ($rule->notify_via === InspectionEscalationRule::NOTIFY_VIA_INTERNAL) {
            // Internal = record-only, queue routing via listOpen* methods.
            $this->repo->updateNotificationStatus(
                $escalationId,
                InspectionEscalation::NOTIFY_STATUS_SKIPPED,
                null,
            );
            return;
        }
        if ($this->notifications === null) {
            $this->repo->updateNotificationStatus(
                $escalationId,
                InspectionEscalation::NOTIFY_STATUS_SKIPPED,
                'no notification dispatcher configured',
            );
            return;
        }
        if ($rule->notification_template === null || $rule->notification_template === '') {
            $this->repo->updateNotificationStatus(
                $escalationId,
                InspectionEscalation::NOTIFY_STATUS_SKIPPED,
                'no notification_template on rule',
            );
            return;
        }

        // Only user-routed rules can be directly notified (we have no
        // phone/email for bare roles). Role-routed rules rely on the
        // listOpenForRole queue instead.
        if ($rule->assign_to_user_id === null) {
            $this->repo->updateNotificationStatus(
                $escalationId,
                InspectionEscalation::NOTIFY_STATUS_SKIPPED,
                'rule is role-routed; no user email/phone to notify',
            );
            return;
        }

        $recipient = $this->fetchUserContact($rule->assign_to_user_id);
        if ($recipient === null) {
            $this->repo->updateNotificationStatus(
                $escalationId,
                InspectionEscalation::NOTIFY_STATUS_FAILED,
                'assigned user not found',
            );
            return;
        }

        $data = [
            'escalation_id' => $escalationId,
            'rule_name' => $rule->name,
            'report_id' => $reportId,
            'report_item_id' => $itemId,
            'severity' => $severity,
            'priority' => $rule->priority,
        ];

        try {
            if ($rule->notify_via === InspectionEscalationRule::NOTIFY_VIA_EMAIL) {
                if ($recipient['email'] === null || $recipient['email'] === '') {
                    $this->repo->updateNotificationStatus(
                        $escalationId,
                        InspectionEscalation::NOTIFY_STATUS_FAILED,
                        'user has no email',
                    );
                    return;
                }
                $this->notifications->sendMail(
                    $rule->notification_template,
                    $recipient['email'],
                    $data,
                );
            } elseif ($rule->notify_via === InspectionEscalationRule::NOTIFY_VIA_SMS) {
                // Users table has no phone column in core schema — SMS
                // escalations require an integration extension. Record
                // as failed rather than throwing.
                $this->repo->updateNotificationStatus(
                    $escalationId,
                    InspectionEscalation::NOTIFY_STATUS_FAILED,
                    'SMS not supported for user-routed escalations (no phone on user)',
                );
                return;
            }
            $this->repo->updateNotificationStatus(
                $escalationId,
                InspectionEscalation::NOTIFY_STATUS_SENT,
                null,
            );
        } catch (Throwable $e) {
            $this->repo->updateNotificationStatus(
                $escalationId,
                InspectionEscalation::NOTIFY_STATUS_FAILED,
                substr($e->getMessage(), 0, 490),
            );
        }
    }

    /**
     * @return array{report_id:int, division_id:?int}|null
     */
    private function fetchReportContext(int $reportId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT r.id AS report_id, t.division_id AS division_id
             FROM inspection_reports r
             LEFT JOIN inspection_templates t ON t.id = r.template_id
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'report_id' => (int) $row['report_id'],
            'division_id' => $row['division_id'] !== null ? (int) $row['division_id'] : null,
        ];
    }

    /**
     * @param array<int, int> $reportItemIds
     * @return array<int, ?int> keyed by report_item_id
     */
    private function fetchComplianceTagByReportItem(array $reportItemIds): array
    {
        if ($reportItemIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($reportItemIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ri.id AS report_item_id, ii.compliance_tag_id AS compliance_tag_id
             FROM inspection_report_items ri
             JOIN inspection_items ii ON ii.id = ri.template_item_id
             WHERE ri.id IN (' . $placeholders . ')'
        );
        $stmt->execute($reportItemIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['report_item_id']] = $row['compliance_tag_id'] !== null
                ? (int) $row['compliance_tag_id']
                : null;
        }
        return $out;
    }

    /**
     * @return array{email:?string}|null
     */
    private function fetchUserContact(int $userId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT email FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'email' => isset($row['email']) && $row['email'] !== '' ? (string) $row['email'] : null,
        ];
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   division_id: ?int,
     *   name: string,
     *   trigger_severity: string,
     *   compliance_tag_id: ?int,
     *   assign_to_user_id: ?int,
     *   assign_to_role: ?string,
     *   notify_via: ?string,
     *   notification_template: ?string,
     *   priority: string,
     *   require_acknowledgment: bool,
     *   is_active: bool,
     *   sort_order: int,
     *   created_by_user_id: ?int
     * }
     */
    private function validateRuleInput(array $input): array
    {
        $name = isset($input['name']) && is_string($input['name']) ? trim($input['name']) : '';
        if ($name === '') {
            throw new InvalidArgumentException('name is required');
        }
        if (strlen($name) > InspectionEscalationRule::NAME_MAX_LEN) {
            throw new InvalidArgumentException(
                'name exceeds ' . InspectionEscalationRule::NAME_MAX_LEN . ' chars'
            );
        }

        $severity = isset($input['trigger_severity']) && is_string($input['trigger_severity'])
            ? strtolower(trim($input['trigger_severity']))
            : InspectionEscalationRule::SEVERITY_CRITICAL;
        if (!in_array($severity, InspectionEscalationRule::ALLOWED_SEVERITIES, true)) {
            throw new InvalidArgumentException(
                'trigger_severity must be one of ' . implode(',', InspectionEscalationRule::ALLOWED_SEVERITIES)
            );
        }

        $priority = isset($input['priority']) && is_string($input['priority'])
            ? strtolower(trim($input['priority']))
            : InspectionEscalationRule::PRIORITY_NORMAL;
        if (!in_array($priority, InspectionEscalationRule::ALLOWED_PRIORITIES, true)) {
            throw new InvalidArgumentException(
                'priority must be one of ' . implode(',', InspectionEscalationRule::ALLOWED_PRIORITIES)
            );
        }

        $notifyVia = null;
        if (array_key_exists('notify_via', $input)
            && $input['notify_via'] !== null
            && $input['notify_via'] !== ''
        ) {
            $notifyVia = strtolower(trim((string) $input['notify_via']));
            if (!in_array($notifyVia, InspectionEscalationRule::ALLOWED_NOTIFY_VIA, true)) {
                throw new InvalidArgumentException(
                    'notify_via must be one of ' . implode(',', InspectionEscalationRule::ALLOWED_NOTIFY_VIA)
                );
            }
        }

        $template = null;
        if (array_key_exists('notification_template', $input)
            && $input['notification_template'] !== null
            && $input['notification_template'] !== ''
        ) {
            $template = trim((string) $input['notification_template']);
            if (strlen($template) > InspectionEscalationRule::TEMPLATE_MAX_LEN) {
                throw new InvalidArgumentException(
                    'notification_template exceeds ' . InspectionEscalationRule::TEMPLATE_MAX_LEN . ' chars'
                );
            }
        }

        $divisionId = null;
        if (array_key_exists('division_id', $input)
            && $input['division_id'] !== null
            && $input['division_id'] !== ''
        ) {
            $divisionId = (int) $input['division_id'];
            if ($divisionId <= 0) {
                throw new InvalidArgumentException('division_id must be a positive integer');
            }
        }

        $complianceTagId = null;
        if (array_key_exists('compliance_tag_id', $input)
            && $input['compliance_tag_id'] !== null
            && $input['compliance_tag_id'] !== ''
        ) {
            $complianceTagId = (int) $input['compliance_tag_id'];
            if ($complianceTagId <= 0) {
                throw new InvalidArgumentException('compliance_tag_id must be a positive integer');
            }
            $this->assertComplianceTagExists($complianceTagId);
        }

        $assignUser = null;
        if (array_key_exists('assign_to_user_id', $input)
            && $input['assign_to_user_id'] !== null
            && $input['assign_to_user_id'] !== ''
        ) {
            $assignUser = (int) $input['assign_to_user_id'];
            if ($assignUser <= 0) {
                throw new InvalidArgumentException('assign_to_user_id must be a positive integer');
            }
        }

        $assignRole = null;
        if (array_key_exists('assign_to_role', $input)
            && $input['assign_to_role'] !== null
            && $input['assign_to_role'] !== ''
        ) {
            $assignRole = strtolower(trim((string) $input['assign_to_role']));
            if ($assignRole === '') {
                $assignRole = null;
            } elseif (strlen($assignRole) > InspectionEscalationRule::ROLE_MAX_LEN) {
                throw new InvalidArgumentException(
                    'assign_to_role exceeds ' . InspectionEscalationRule::ROLE_MAX_LEN . ' chars'
                );
            }
        }

        if ($assignUser === null && $assignRole === null) {
            throw new InvalidArgumentException(
                'rule must set at least one of assign_to_user_id or assign_to_role'
            );
        }

        $requireAck = array_key_exists('require_acknowledgment', $input)
            ? (bool) $input['require_acknowledgment']
            : true;
        $isActive = array_key_exists('is_active', $input) ? (bool) $input['is_active'] : true;
        $sortOrder = array_key_exists('sort_order', $input) ? (int) $input['sort_order'] : 0;
        if ($sortOrder < 0) {
            throw new InvalidArgumentException('sort_order must be >= 0');
        }

        return [
            'division_id' => $divisionId,
            'name' => $name,
            'trigger_severity' => $severity,
            'compliance_tag_id' => $complianceTagId,
            'assign_to_user_id' => $assignUser,
            'assign_to_role' => $assignRole,
            'notify_via' => $notifyVia,
            'notification_template' => $template,
            'priority' => $priority,
            'require_acknowledgment' => $requireAck,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
            'created_by_user_id' => null,
        ];
    }

    private function assertComplianceTagExists(int $tagId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT 1 FROM inspection_compliance_tags WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $tagId]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException("compliance tag {$tagId} not found");
        }
    }

    // ── Serialization + logging ──────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function serializeRule(InspectionEscalationRule $r): array
    {
        return [
            'id' => $r->id,
            'division_id' => $r->division_id,
            'name' => $r->name,
            'trigger_severity' => $r->trigger_severity,
            'compliance_tag_id' => $r->compliance_tag_id,
            'assign_to_user_id' => $r->assign_to_user_id,
            'assign_to_role' => $r->assign_to_role,
            'notify_via' => $r->notify_via,
            'notification_template' => $r->notification_template,
            'priority' => $r->priority,
            'require_acknowledgment' => $r->require_acknowledgment,
            'is_active' => $r->is_active,
            'sort_order' => $r->sort_order,
            'created_by_user_id' => $r->created_by_user_id,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEscalation(InspectionEscalation $e): array
    {
        return [
            'id' => $e->id,
            'rule_id' => $e->rule_id,
            'inspection_report_id' => $e->inspection_report_id,
            'inspection_report_item_id' => $e->inspection_report_item_id,
            'priority' => $e->priority,
            'severity' => $e->severity,
            'assigned_to_user_id' => $e->assigned_to_user_id,
            'assigned_to_role' => $e->assigned_to_role,
            'status' => $e->status,
            'notification_status' => $e->notification_status,
            'notification_error' => $e->notification_error,
            'acknowledged_by_user_id' => $e->acknowledged_by_user_id,
            'acknowledged_at' => $e->acknowledged_at,
            'resolved_by_user_id' => $e->resolved_by_user_id,
            'resolved_at' => $e->resolved_at,
            'resolution_note' => $e->resolution_note,
            'created_by_user_id' => $e->created_by_user_id,
            'created_at' => $e->created_at,
            'updated_at' => $e->updated_at,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $event, int $entityId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }
        $this->audit->log(new AuditEntry($event, 'inspection_escalation', (string) $entityId, $actorId, $context));
    }
}
