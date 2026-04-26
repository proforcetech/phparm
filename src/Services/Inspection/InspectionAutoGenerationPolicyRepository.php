<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionAutoGenerationPolicy;
use PDO;

/**
 * Phase 8.2 of docs/expansion-plan.md — CRUD for
 * inspection_auto_generation_policies + run-marker reads/writes for
 * idempotency.
 *
 * Flat CRUD surface; service layer owns validation + audit.
 */
class InspectionAutoGenerationPolicyRepository
{
    private const COLUMNS = 'id, division_id, name, trigger_severity, compliance_tag_id, target_kind, min_failed_items, require_customer_approval, auto_assign_to_technician_id, is_active, sort_order, created_by_user_id, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{
     *   division_id?: ?int,
     *   name: string,
     *   trigger_severity?: string,
     *   compliance_tag_id?: ?int,
     *   target_kind?: string,
     *   min_failed_items?: int,
     *   require_customer_approval?: bool,
     *   auto_assign_to_technician_id?: ?int,
     *   is_active?: bool,
     *   sort_order?: int,
     *   created_by_user_id?: ?int
     * } $data
     */
    public function create(array $data): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO inspection_auto_generation_policies
                (division_id, name, trigger_severity, compliance_tag_id, target_kind,
                 min_failed_items, require_customer_approval, auto_assign_to_technician_id,
                 is_active, sort_order, created_by_user_id, created_at, updated_at)
             VALUES
                (:division_id, :name, :trigger_severity, :compliance_tag_id, :target_kind,
                 :min_failed_items, :require_customer_approval, :auto_assign_to_technician_id,
                 :is_active, :sort_order, :created_by_user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'division_id' => $data['division_id'] ?? null,
            'name' => $data['name'],
            'trigger_severity' => $data['trigger_severity'] ?? InspectionAutoGenerationPolicy::SEVERITY_HIGH,
            'compliance_tag_id' => $data['compliance_tag_id'] ?? null,
            'target_kind' => $data['target_kind'] ?? InspectionAutoGenerationPolicy::TARGET_AUTO,
            'min_failed_items' => $data['min_failed_items'] ?? 1,
            'require_customer_approval' => ($data['require_customer_approval'] ?? true) ? 1 : 0,
            'auto_assign_to_technician_id' => $data['auto_assign_to_technician_id'] ?? null,
            'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id): ?InspectionAutoGenerationPolicy
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM inspection_auto_generation_policies WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @param array{
     *   name: string,
     *   trigger_severity?: string,
     *   compliance_tag_id?: ?int,
     *   target_kind?: string,
     *   min_failed_items?: int,
     *   require_customer_approval?: bool,
     *   auto_assign_to_technician_id?: ?int,
     *   is_active?: bool,
     *   sort_order?: int
     * } $data
     */
    public function update(int $id, array $data): void
    {
        // Deliberately omit division_id from the writable set — it is
        // an organizational/filter field baked into existing run markers
        // and changing it mid-life risks orphaning markers. Forcing
        // delete+recreate keeps audit trails honest.
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inspection_auto_generation_policies
             SET name = :name,
                 trigger_severity = :trigger_severity,
                 compliance_tag_id = :compliance_tag_id,
                 target_kind = :target_kind,
                 min_failed_items = :min_failed_items,
                 require_customer_approval = :require_customer_approval,
                 auto_assign_to_technician_id = :auto_assign_to_technician_id,
                 is_active = :is_active,
                 sort_order = :sort_order,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $data['name'],
            'trigger_severity' => $data['trigger_severity'] ?? InspectionAutoGenerationPolicy::SEVERITY_HIGH,
            'compliance_tag_id' => $data['compliance_tag_id'] ?? null,
            'target_kind' => $data['target_kind'] ?? InspectionAutoGenerationPolicy::TARGET_AUTO,
            'min_failed_items' => $data['min_failed_items'] ?? 1,
            'require_customer_approval' => ($data['require_customer_approval'] ?? true) ? 1 : 0,
            'auto_assign_to_technician_id' => $data['auto_assign_to_technician_id'] ?? null,
            'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
            'sort_order' => $data['sort_order'] ?? 0,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM inspection_auto_generation_policies WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * List policies visible to a division. Global policies (division_id
     * NULL) are always included.
     *
     * @return array<int, InspectionAutoGenerationPolicy>
     */
    public function listForDivision(?int $divisionId, bool $activeOnly = false): array
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
            'SELECT ' . self::COLUMNS . ' FROM inspection_auto_generation_policies
             WHERE ' . $where . '
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute($params);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    // ── Run markers (idempotency) ─────────────────────────────────────────

    public function hasRun(int $policyId, int $reportId): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT 1 FROM inspection_auto_generation_runs
             WHERE policy_id = :policy_id AND inspection_report_id = :report_id
             LIMIT 1'
        );
        $stmt->execute(['policy_id' => $policyId, 'report_id' => $reportId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array{
     *   policy_id: int,
     *   inspection_report_id: int,
     *   status?: string,
     *   estimate_id?: ?int,
     *   sub_estimate_id?: ?int,
     *   workorder_id?: ?int,
     *   items_generated?: int,
     *   note?: ?string,
     *   triggered_by_user_id?: ?int
     * } $data
     */
    public function recordRun(array $data): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO inspection_auto_generation_runs
                (policy_id, inspection_report_id, status, estimate_id, sub_estimate_id,
                 workorder_id, items_generated, note, triggered_by_user_id, created_at)
             VALUES
                (:policy_id, :report_id, :status, :estimate_id, :sub_estimate_id,
                 :workorder_id, :items_generated, :note, :triggered_by, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'policy_id' => $data['policy_id'],
            'report_id' => $data['inspection_report_id'],
            'status' => $data['status'] ?? 'triggered',
            'estimate_id' => $data['estimate_id'] ?? null,
            'sub_estimate_id' => $data['sub_estimate_id'] ?? null,
            'workorder_id' => $data['workorder_id'] ?? null,
            'items_generated' => $data['items_generated'] ?? 0,
            'note' => $data['note'] ?? null,
            'triggered_by' => $data['triggered_by_user_id'] ?? null,
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRunsForReport(int $reportId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, policy_id, inspection_report_id, status, estimate_id, sub_estimate_id,
                    workorder_id, items_generated, note, triggered_by_user_id, created_at
             FROM inspection_auto_generation_runs
             WHERE inspection_report_id = :report_id
             ORDER BY id ASC'
        );
        $stmt->execute(['report_id' => $reportId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'id' => (int) $r['id'],
                'policy_id' => (int) $r['policy_id'],
                'inspection_report_id' => (int) $r['inspection_report_id'],
                'status' => (string) $r['status'],
                'estimate_id' => $r['estimate_id'] !== null ? (int) $r['estimate_id'] : null,
                'sub_estimate_id' => $r['sub_estimate_id'] !== null ? (int) $r['sub_estimate_id'] : null,
                'workorder_id' => $r['workorder_id'] !== null ? (int) $r['workorder_id'] : null,
                'items_generated' => (int) $r['items_generated'],
                'note' => $r['note'],
                'triggered_by_user_id' => $r['triggered_by_user_id'] !== null ? (int) $r['triggered_by_user_id'] : null,
                'created_at' => $r['created_at'] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): InspectionAutoGenerationPolicy
    {
        return new InspectionAutoGenerationPolicy([
            'id' => (int) $row['id'],
            'division_id' => $row['division_id'] !== null ? (int) $row['division_id'] : null,
            'name' => (string) $row['name'],
            'trigger_severity' => (string) $row['trigger_severity'],
            'compliance_tag_id' => $row['compliance_tag_id'] !== null ? (int) $row['compliance_tag_id'] : null,
            'target_kind' => (string) $row['target_kind'],
            'min_failed_items' => (int) $row['min_failed_items'],
            'require_customer_approval' => (bool) $row['require_customer_approval'],
            'auto_assign_to_technician_id' => $row['auto_assign_to_technician_id'] !== null ? (int) $row['auto_assign_to_technician_id'] : null,
            'is_active' => (bool) $row['is_active'],
            'sort_order' => (int) $row['sort_order'],
            'created_by_user_id' => $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }
}
