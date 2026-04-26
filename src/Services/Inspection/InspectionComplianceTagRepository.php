<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionComplianceTag;
use PDO;

/**
 * Phase 8.1 of docs/expansion-plan.md — inspection compliance tag
 * vocabulary + template <-> tag junction CRUD.
 *
 * Flat CRUD surface; service-layer owns validation + audit.
 */
class InspectionComplianceTagRepository
{
    private const COLUMNS = 'id, code, label, description, regulatory_body, division_id, is_active, sort_order, created_by_user_id, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{
     *   code: string,
     *   label: string,
     *   description?: ?string,
     *   regulatory_body: string,
     *   division_id?: ?int,
     *   is_active?: bool,
     *   sort_order?: int,
     *   created_by_user_id?: ?int
     * } $data
     */
    public function create(array $data): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO inspection_compliance_tags
                (code, label, description, regulatory_body, division_id, is_active, sort_order, created_by_user_id, created_at, updated_at)
             VALUES
                (:code, :label, :description, :regulatory_body, :division_id, :is_active, :sort_order, :created_by_user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'code' => $data['code'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'regulatory_body' => $data['regulatory_body'],
            'division_id' => $data['division_id'] ?? null,
            'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id): ?InspectionComplianceTag
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM inspection_compliance_tags WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function findByCode(string $code, ?int $divisionId): ?InspectionComplianceTag
    {
        if ($divisionId === null) {
            $stmt = $this->connection->pdo()->prepare(
                'SELECT ' . self::COLUMNS . ' FROM inspection_compliance_tags WHERE code = :code AND division_id IS NULL LIMIT 1'
            );
            $stmt->execute(['code' => $code]);
        } else {
            $stmt = $this->connection->pdo()->prepare(
                'SELECT ' . self::COLUMNS . ' FROM inspection_compliance_tags WHERE code = :code AND division_id = :division_id LIMIT 1'
            );
            $stmt->execute(['code' => $code, 'division_id' => $divisionId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * List tags visible to a division. Global tags (division_id NULL)
     * are always included so a division-scoped picker can show "OSHA
     * general industry" alongside its own "state safety" tags.
     *
     * @return array<int, InspectionComplianceTag>
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
            'SELECT ' . self::COLUMNS . ' FROM inspection_compliance_tags
             WHERE ' . $where . '
             ORDER BY sort_order ASC, label ASC'
        );
        $stmt->execute($params);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param array{
     *   label: string,
     *   description?: ?string,
     *   regulatory_body: string,
     *   is_active?: bool,
     *   sort_order?: int
     * } $data
     */
    public function update(int $id, array $data): void
    {
        // Deliberately omit code + division_id from the writable set —
        // those define the tag's identity and should not mutate in place.
        // Rename via delete + recreate to force the caller to think
        // about the downstream impact on already-bound templates.
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inspection_compliance_tags
             SET label = :label,
                 description = :description,
                 regulatory_body = :regulatory_body,
                 is_active = :is_active,
                 sort_order = :sort_order,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'regulatory_body' => $data['regulatory_body'],
            'is_active' => ($data['is_active'] ?? true) ? 1 : 0,
            'sort_order' => $data['sort_order'] ?? 0,
            'id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM inspection_compliance_tags WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // ── Template <-> tag junction ────────────────────────────────────────

    /**
     * Replace-all semantics: wipe existing bindings for the template
     * and insert the supplied tag_ids. Callers must wrap in a
     * transaction if they want atomicity with surrounding writes.
     *
     * @param array<int, int> $tagIds
     */
    public function setTagsForTemplate(int $templateId, array $tagIds): void
    {
        $pdo = $this->connection->pdo();
        $pdo->prepare('DELETE FROM inspection_template_compliance_tags WHERE template_id = :tid')
            ->execute(['tid' => $templateId]);
        if ($tagIds === []) {
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO inspection_template_compliance_tags (template_id, tag_id, created_at)
             VALUES (:tid, :gid, CURRENT_TIMESTAMP)'
        );
        foreach (array_unique($tagIds) as $gid) {
            $stmt->execute(['tid' => $templateId, 'gid' => (int) $gid]);
        }
    }

    /**
     * @return array<int, InspectionComplianceTag>
     */
    public function listTagsForTemplate(int $templateId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT t.id, t.code, t.label, t.description, t.regulatory_body, t.division_id, t.is_active, t.sort_order, t.created_by_user_id, t.created_at, t.updated_at
             FROM inspection_compliance_tags t
             INNER JOIN inspection_template_compliance_tags j ON j.tag_id = t.id
             WHERE j.template_id = :tid
             ORDER BY t.sort_order ASC, t.label ASC'
        );
        $stmt->execute(['tid' => $templateId]);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Return distinct template_ids whose binding set matches ANY of the
     * supplied tag ids. Caller typically then loads templates from
     * InspectionTemplateService.
     *
     * @param array<int, int> $tagIds
     * @return array<int, int>
     */
    public function listTemplateIdsForAnyTag(array $tagIds): array
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
        if ($tagIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT DISTINCT template_id FROM inspection_template_compliance_tags
             WHERE tag_id IN (' . $placeholders . ')'
        );
        $stmt->execute($tagIds);
        return array_map(fn($r) => (int) $r['template_id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Templates in a division that are tagged with any of the given
     * compliance codes. Used by the division picker to surface "show me
     * all templates a DOT inspector needs to run on this unit" without
     * hand-joining.
     *
     * @param array<int, string> $tagCodes
     * @return array<int, array{id: int, name: string, description: ?string, active: bool, division_id: ?int, tag_codes: array<int, string>}>
     */
    public function listTemplatesByCompliance(?int $divisionId, array $tagCodes, bool $activeOnly = false): array
    {
        $tagCodes = array_values(array_unique(array_filter(array_map('strval', $tagCodes))));
        $bindings = [];
        $where = [];

        if ($divisionId !== null) {
            // Template has division_id column (added in 099_divisions);
            // null division templates are considered global and visible
            // to any division filter so we include NULL too.
            $where[] = '(it.division_id = :division_id OR it.division_id IS NULL)';
            $bindings['division_id'] = $divisionId;
        }

        if ($activeOnly) {
            $where[] = 'it.active = 1';
        }

        if ($tagCodes !== []) {
            $placeholders = [];
            foreach ($tagCodes as $i => $code) {
                $key = 'code' . $i;
                $placeholders[] = ':' . $key;
                $bindings[$key] = $code;
            }
            $where[] = 't.code IN (' . implode(',', $placeholders) . ')';
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->connection->pdo()->prepare(
            'SELECT it.id AS id,
                    it.name AS name,
                    it.description AS description,
                    it.active AS active,
                    it.division_id AS division_id,
                    t.code AS tag_code
             FROM inspection_templates it
             INNER JOIN inspection_template_compliance_tags j ON j.template_id = it.id
             INNER JOIN inspection_compliance_tags t ON t.id = j.tag_id
             ' . $whereSql . '
             ORDER BY it.name ASC, t.sort_order ASC'
        );
        $stmt->execute($bindings);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($grouped[$id])) {
                $grouped[$id] = [
                    'id' => $id,
                    'name' => (string) $row['name'],
                    'description' => $row['description'],
                    'active' => (bool) $row['active'],
                    'division_id' => $row['division_id'] !== null ? (int) $row['division_id'] : null,
                    'tag_codes' => [],
                ];
            }
            $grouped[$id]['tag_codes'][] = (string) $row['tag_code'];
        }
        foreach ($grouped as &$row) {
            $row['tag_codes'] = array_values(array_unique($row['tag_codes']));
        }
        return array_values($grouped);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): InspectionComplianceTag
    {
        return new InspectionComplianceTag([
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['label'],
            'description' => $row['description'],
            'regulatory_body' => (string) $row['regulatory_body'],
            'division_id' => $row['division_id'] !== null ? (int) $row['division_id'] : null,
            'is_active' => (bool) $row['is_active'],
            'sort_order' => (int) $row['sort_order'],
            'created_by_user_id' => $row['created_by_user_id'] !== null ? (int) $row['created_by_user_id'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }
}
