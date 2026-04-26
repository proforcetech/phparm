<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionAutoGenerationPolicy;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Phase 8.2 of docs/expansion-plan.md — deficiency → auto-WO/quote
 * generation.
 *
 * Orchestrates auto-generation policies + their execution against a
 * completed inspection report. Delegates the actual estimate / sub-
 * estimate creation to InspectionEstimateBridgeService (the existing
 * manual flow), so both manual-button and auto paths share one code
 * path.
 *
 * Gates: policy CRUD + manual evaluateReport → inspections.manage.
 * The completion-hook path calls `evaluateReport` directly from
 * InspectionCompletionService (no User in scope) and is wrapped in
 * try/catch Throwable so a misconfigured policy cannot block
 * inspection completion.
 */
class InspectionDeficiencyAutoService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InspectionAutoGenerationPolicyRepository $policies,
        private readonly InspectionEstimateBridgeService $bridge,
        private readonly AccessGate $gate,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    // ── Policy CRUD ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createPolicy(User $actor, array $input): array
    {
        $this->gate->assert($actor, 'inspections.manage');
        $fields = $this->validatePolicyInput($input);
        $fields['created_by_user_id'] = $actor->id;
        $id = $this->policies->create($fields);

        $this->log('inspection.auto_policy.created', $id, $actor->id, [
            'name' => $fields['name'],
            'trigger_severity' => $fields['trigger_severity'],
            'target_kind' => $fields['target_kind'],
            'division_id' => $fields['division_id'],
        ]);

        $policy = $this->policies->findById($id);
        if ($policy === null) {
            throw new \RuntimeException("auto policy {$id} vanished after insert");
        }
        return $this->serializePolicy($policy);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updatePolicy(User $actor, int $id, array $input): array
    {
        $this->gate->assert($actor, 'inspections.manage');
        $existing = $this->policies->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("auto policy {$id} not found");
        }

        // Merge-then-validate so partial-PUT callers don't null fields
        // they didn't pass. division_id is immutable — see repo comment.
        $merged = [
            'division_id' => $existing->division_id,
            'name' => $input['name'] ?? $existing->name,
            'trigger_severity' => $input['trigger_severity'] ?? $existing->trigger_severity,
            'compliance_tag_id' => array_key_exists('compliance_tag_id', $input)
                ? $input['compliance_tag_id'] : $existing->compliance_tag_id,
            'target_kind' => $input['target_kind'] ?? $existing->target_kind,
            'min_failed_items' => array_key_exists('min_failed_items', $input)
                ? $input['min_failed_items'] : $existing->min_failed_items,
            'require_customer_approval' => array_key_exists('require_customer_approval', $input)
                ? (bool) $input['require_customer_approval'] : $existing->require_customer_approval,
            'auto_assign_to_technician_id' => array_key_exists('auto_assign_to_technician_id', $input)
                ? $input['auto_assign_to_technician_id'] : $existing->auto_assign_to_technician_id,
            'is_active' => array_key_exists('is_active', $input)
                ? (bool) $input['is_active'] : $existing->is_active,
            'sort_order' => array_key_exists('sort_order', $input)
                ? (int) $input['sort_order'] : $existing->sort_order,
        ];
        $fields = $this->validatePolicyInput($merged);
        // Strip division_id before update — repo update() doesn't
        // accept it and silently ignoring it here documents the intent.
        unset($fields['division_id'], $fields['created_by_user_id']);

        $this->policies->update($id, $fields);

        $this->log('inspection.auto_policy.updated', $id, $actor->id, [
            'name' => $fields['name'],
            'trigger_severity' => $fields['trigger_severity'],
            'is_active' => $fields['is_active'],
        ]);

        $fresh = $this->policies->findById($id);
        if ($fresh === null) {
            throw new \RuntimeException("auto policy {$id} vanished after update");
        }
        return $this->serializePolicy($fresh);
    }

    public function deletePolicy(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'inspections.manage');
        $existing = $this->policies->findById($id);
        if ($existing === null) {
            return; // idempotent no-op
        }
        $this->policies->delete($id);
        $this->log('inspection.auto_policy.deleted', $id, $actor->id, [
            'name' => $existing->name,
            'division_id' => $existing->division_id,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPolicies(User $actor, ?int $divisionId = null, bool $activeOnly = false): array
    {
        $this->gate->assert($actor, 'inspections.view');
        return array_map(
            fn(InspectionAutoGenerationPolicy $p) => $this->serializePolicy($p),
            $this->policies->listForDivision($divisionId, $activeOnly),
        );
    }

    // ── Evaluation ───────────────────────────────────────────────────────

    /**
     * Manual entry point — gated inspections.manage. Returns the list
     * of run results (one per policy that matched).
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluateReport(User $actor, int $reportId): array
    {
        $this->gate->assert($actor, 'inspections.manage');
        return $this->runEvaluation($reportId, $actor->id);
    }

    /**
     * Best-effort entry point called from the inspection completion
     * hook. Swallows all throwables so a misconfigured policy never
     * blocks the report from being marked complete — a failed run is
     * still recorded in inspection_auto_generation_runs for audit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function evaluateReportOnCompletion(int $reportId, ?int $actorId): array
    {
        try {
            return $this->runEvaluation($reportId, $actorId);
        } catch (Throwable $e) {
            $this->log('inspection.auto_policy.hook_failure', $reportId, $actorId, [
                'message' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRunsForReport(User $actor, int $reportId): array
    {
        $this->gate->assert($actor, 'inspections.view');
        return $this->policies->listRunsForReport($reportId);
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

        $tagByReportItem = $this->fetchComplianceTagByReportItem(
            array_map(static fn($i) => (int) $i['id'], $failedItems)
        );

        $policies = $this->policies->listForDivision($reportContext['division_id'], true);
        $results = [];
        foreach ($policies as $policy) {
            if (!$policy->is_active) {
                continue;
            }
            if ($this->policies->hasRun($policy->id, $reportId)) {
                // Idempotent — already fired for this report.
                continue;
            }

            $matching = $this->filterItemsForPolicy($failedItems, $tagByReportItem, $policy);
            if (count($matching) < max(1, $policy->min_failed_items)) {
                continue;
            }

            $results[] = $this->executePolicy($policy, $reportContext, $matching, $actorId);
        }

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $failedItems
     * @param array<int, ?int> $tagByReportItem report_item_id => compliance_tag_id|null
     * @return array<int, array<string, mixed>>
     */
    private function filterItemsForPolicy(
        array $failedItems,
        array $tagByReportItem,
        InspectionAutoGenerationPolicy $policy,
    ): array {
        $minLevel = InspectionAutoGenerationPolicy::SEVERITY_ORDER[$policy->trigger_severity] ?? 3;
        $out = [];
        foreach ($failedItems as $item) {
            if (!empty($item['already_converted'])) {
                continue;
            }
            $severity = (string) ($item['severity'] ?? 'low');
            $level = InspectionAutoGenerationPolicy::SEVERITY_ORDER[$severity] ?? 0;
            if ($level < $minLevel) {
                continue;
            }
            if ($policy->compliance_tag_id !== null) {
                $itemTag = $tagByReportItem[(int) $item['id']] ?? null;
                if ($itemTag !== $policy->compliance_tag_id) {
                    continue;
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $reportContext
     * @param array<int, array<string, mixed>> $matching
     * @return array<string, mixed>
     */
    private function executePolicy(
        InspectionAutoGenerationPolicy $policy,
        array $reportContext,
        array $matching,
        ?int $actorId,
    ): array {
        $reportItemIds = array_map(static fn($i) => (int) $i['id'], $matching);
        $workorderId = $reportContext['workorder_id'];
        $kind = $this->resolveTargetKind($policy->target_kind, $workorderId !== null);

        $runData = [
            'policy_id' => $policy->id,
            'inspection_report_id' => $reportContext['report_id'],
            'items_generated' => count($reportItemIds),
            'triggered_by_user_id' => $actorId,
        ];

        try {
            if ($kind === InspectionAutoGenerationPolicy::TARGET_SUBESTIMATE_ON_WORKORDER) {
                if ($workorderId === null) {
                    // Policy asked for a sub-estimate but the report has
                    // no workorder — record skipped and move on so the
                    // next completion on a workorder-linked report
                    // still fires the policy.
                    return $this->recordAndReturn(array_merge($runData, [
                        'status' => 'skipped',
                        'note' => 'target_kind=subestimate_on_workorder but report has no workorder',
                        'items_generated' => 0,
                    ]));
                }
                $result = $this->bridge->addToWorkorder(
                    $reportContext['report_id'],
                    $workorderId,
                    $reportItemIds,
                    $actorId,
                );
                return $this->recordAndReturn(array_merge($runData, [
                    'status' => 'triggered',
                    'sub_estimate_id' => $result['sub_estimate_id'] ?? null,
                    'workorder_id' => $workorderId,
                    'note' => 'auto-generated sub-estimate ' . ($result['sub_estimate_number'] ?? ''),
                ]));
            }

            $result = $this->bridge->createEstimateFromFailedItems(
                $reportContext['report_id'],
                $reportItemIds,
                $actorId,
            );
            return $this->recordAndReturn(array_merge($runData, [
                'status' => 'triggered',
                'estimate_id' => $result['estimate_id'] ?? null,
                'note' => 'auto-generated estimate ' . ($result['estimate_number'] ?? ''),
            ]));
        } catch (Throwable $e) {
            return $this->recordAndReturn(array_merge($runData, [
                'status' => 'failed',
                'note' => substr($e->getMessage(), 0, 500),
                'items_generated' => 0,
            ]));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function recordAndReturn(array $data): array
    {
        $runId = $this->policies->recordRun($data);
        $this->log('inspection.auto_policy.run', $runId, $data['triggered_by_user_id'] ?? null, [
            'policy_id' => $data['policy_id'],
            'report_id' => $data['inspection_report_id'],
            'status' => $data['status'] ?? 'triggered',
            'items_generated' => $data['items_generated'] ?? 0,
        ]);
        return array_merge(['run_id' => $runId], $data);
    }

    private function resolveTargetKind(string $configured, bool $hasWorkorder): string
    {
        if ($configured === InspectionAutoGenerationPolicy::TARGET_AUTO) {
            return $hasWorkorder
                ? InspectionAutoGenerationPolicy::TARGET_SUBESTIMATE_ON_WORKORDER
                : InspectionAutoGenerationPolicy::TARGET_ESTIMATE;
        }
        return $configured;
    }

    /**
     * @return array{report_id:int, division_id:?int, workorder_id:?int}|null
     */
    private function fetchReportContext(int $reportId): ?array
    {
        // Reports don't carry division_id directly — we pull it from
        // the template. workorder_id comes via estimate linkage when
        // present (inspections are often spawned from an in-flight
        // workorder's customer visit).
        $stmt = $this->connection->pdo()->prepare(
            'SELECT r.id AS report_id,
                    t.division_id AS division_id,
                    w.id AS workorder_id
             FROM inspection_reports r
             LEFT JOIN inspection_templates t ON t.id = r.template_id
             LEFT JOIN workorders w ON w.estimate_id = r.estimate_id AND r.estimate_id IS NOT NULL
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
            'workorder_id' => $row['workorder_id'] !== null ? (int) $row['workorder_id'] : null,
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

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   division_id: ?int,
     *   name: string,
     *   trigger_severity: string,
     *   compliance_tag_id: ?int,
     *   target_kind: string,
     *   min_failed_items: int,
     *   require_customer_approval: bool,
     *   auto_assign_to_technician_id: ?int,
     *   is_active: bool,
     *   sort_order: int,
     *   created_by_user_id: ?int
     * }
     */
    private function validatePolicyInput(array $input): array
    {
        $name = isset($input['name']) && is_string($input['name']) ? trim($input['name']) : '';
        if ($name === '') {
            throw new InvalidArgumentException('name is required');
        }
        if (strlen($name) > InspectionAutoGenerationPolicy::NAME_MAX_LEN) {
            throw new InvalidArgumentException(
                'name exceeds ' . InspectionAutoGenerationPolicy::NAME_MAX_LEN . ' chars'
            );
        }

        $severity = isset($input['trigger_severity']) && is_string($input['trigger_severity'])
            ? strtolower(trim($input['trigger_severity']))
            : InspectionAutoGenerationPolicy::SEVERITY_HIGH;
        if (!in_array($severity, InspectionAutoGenerationPolicy::ALLOWED_SEVERITIES, true)) {
            throw new InvalidArgumentException(
                'trigger_severity must be one of ' . implode(',', InspectionAutoGenerationPolicy::ALLOWED_SEVERITIES)
            );
        }

        $targetKind = isset($input['target_kind']) && is_string($input['target_kind'])
            ? strtolower(trim($input['target_kind']))
            : InspectionAutoGenerationPolicy::TARGET_AUTO;
        if (!in_array($targetKind, InspectionAutoGenerationPolicy::ALLOWED_TARGET_KINDS, true)) {
            throw new InvalidArgumentException(
                'target_kind must be one of ' . implode(',', InspectionAutoGenerationPolicy::ALLOWED_TARGET_KINDS)
            );
        }

        $divisionId = null;
        if (array_key_exists('division_id', $input) && $input['division_id'] !== null && $input['division_id'] !== '') {
            $divisionId = (int) $input['division_id'];
            if ($divisionId <= 0) {
                throw new InvalidArgumentException('division_id must be a positive integer');
            }
        }

        $complianceTagId = null;
        if (array_key_exists('compliance_tag_id', $input) && $input['compliance_tag_id'] !== null && $input['compliance_tag_id'] !== '') {
            $complianceTagId = (int) $input['compliance_tag_id'];
            if ($complianceTagId <= 0) {
                throw new InvalidArgumentException('compliance_tag_id must be a positive integer');
            }
            $this->assertComplianceTagExists($complianceTagId);
        }

        $minFailed = array_key_exists('min_failed_items', $input)
            ? (int) $input['min_failed_items']
            : 1;
        if ($minFailed < 1) {
            throw new InvalidArgumentException('min_failed_items must be >= 1');
        }

        $techId = null;
        if (array_key_exists('auto_assign_to_technician_id', $input)
            && $input['auto_assign_to_technician_id'] !== null
            && $input['auto_assign_to_technician_id'] !== ''
        ) {
            $techId = (int) $input['auto_assign_to_technician_id'];
            if ($techId <= 0) {
                throw new InvalidArgumentException('auto_assign_to_technician_id must be a positive integer');
            }
        }

        $requireApproval = array_key_exists('require_customer_approval', $input)
            ? (bool) $input['require_customer_approval']
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
            'target_kind' => $targetKind,
            'min_failed_items' => $minFailed,
            'require_customer_approval' => $requireApproval,
            'auto_assign_to_technician_id' => $techId,
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
    private function serializePolicy(InspectionAutoGenerationPolicy $p): array
    {
        return [
            'id' => $p->id,
            'division_id' => $p->division_id,
            'name' => $p->name,
            'trigger_severity' => $p->trigger_severity,
            'compliance_tag_id' => $p->compliance_tag_id,
            'target_kind' => $p->target_kind,
            'min_failed_items' => $p->min_failed_items,
            'require_customer_approval' => $p->require_customer_approval,
            'auto_assign_to_technician_id' => $p->auto_assign_to_technician_id,
            'is_active' => $p->is_active,
            'sort_order' => $p->sort_order,
            'created_by_user_id' => $p->created_by_user_id,
            'created_at' => $p->created_at,
            'updated_at' => $p->updated_at,
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
        $this->audit->log(new AuditEntry($event, 'inspection_auto_policy', (string) $entityId, $actorId, $context));
    }
}
