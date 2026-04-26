<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionComplianceTag;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * Phase 8.1 of docs/expansion-plan.md — compliance-tagged inspection
 * templates per-division.
 *
 * Orchestrates the tag vocabulary (creating, updating, deactivating
 * compliance tags like "DOT annual" or "OSHA general industry") and the
 * template <-> tag junction (attaching one or more tags to a template
 * so field techs can filter the template library by regulatory
 * framework when starting an inspection).
 *
 * Gates: reads → inspections.view, writes → inspections.manage. Both
 * are already covered by the `inspections.*` wildcard for managers and
 * technicians in config/auth.php.
 */
class InspectionComplianceService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InspectionComplianceTagRepository $tags,
        private readonly AccessGate $gate,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    // ── Tag vocabulary ───────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createTag(User $actor, array $input): array
    {
        $this->gate->assert($actor, 'inspections.manage');
        $fields = $this->validateTagInput($input, isUpdate: false);

        try {
            $id = $this->tags->create([
                'code' => $fields['code'],
                'label' => $fields['label'],
                'description' => $fields['description'],
                'regulatory_body' => $fields['regulatory_body'],
                'division_id' => $fields['division_id'],
                'is_active' => $fields['is_active'],
                'sort_order' => $fields['sort_order'],
                'created_by_user_id' => $actor->id,
            ]);
        } catch (PDOException $e) {
            // UNIQUE (code, division_id) collision — surface as 400.
            $sqlstate = $e->errorInfo[0] ?? '';
            $msg = $e->getMessage();
            if ($sqlstate === '23000' || stripos($msg, 'duplicate') !== false || stripos($msg, 'UNIQUE') !== false) {
                throw new InvalidArgumentException(
                    'compliance tag code is already in use for this division'
                );
            }
            throw $e;
        }

        $this->log('inspection.compliance_tag.created', $id, $actor->id, [
            'code' => $fields['code'],
            'division_id' => $fields['division_id'],
            'regulatory_body' => $fields['regulatory_body'],
        ]);

        $created = $this->tags->findById($id);
        if ($created === null) {
            throw new RuntimeException("compliance tag {$id} vanished after insert");
        }
        return $this->serializeTag($created);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateTag(User $actor, int $id, array $input): array
    {
        $this->gate->assert($actor, 'inspections.manage');
        $existing = $this->tags->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("compliance tag {$id} not found");
        }

        // Merge-then-validate so partial-PUT callers don't null fields
        // they didn't pass. Code + division_id are identity fields and
        // are never merged from input.
        $merged = [
            'code' => $existing->code,
            'label' => $input['label'] ?? $existing->label,
            'description' => array_key_exists('description', $input)
                ? $input['description'] : $existing->description,
            'regulatory_body' => $input['regulatory_body'] ?? $existing->regulatory_body,
            'division_id' => $existing->division_id,
            'is_active' => array_key_exists('is_active', $input)
                ? (bool) $input['is_active'] : $existing->is_active,
            'sort_order' => array_key_exists('sort_order', $input)
                ? (int) $input['sort_order'] : $existing->sort_order,
        ];
        $fields = $this->validateTagInput($merged, isUpdate: true);

        $this->tags->update($id, [
            'label' => $fields['label'],
            'description' => $fields['description'],
            'regulatory_body' => $fields['regulatory_body'],
            'is_active' => $fields['is_active'],
            'sort_order' => $fields['sort_order'],
        ]);

        $this->log('inspection.compliance_tag.updated', $id, $actor->id, [
            'code' => $fields['code'],
            'regulatory_body' => $fields['regulatory_body'],
            'is_active' => $fields['is_active'],
        ]);

        $fresh = $this->tags->findById($id);
        if ($fresh === null) {
            throw new RuntimeException("compliance tag {$id} vanished after update");
        }
        return $this->serializeTag($fresh);
    }

    public function deleteTag(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'inspections.manage');
        $existing = $this->tags->findById($id);
        if ($existing === null) {
            // Idempotent — already-absent is a no-op so double-click /
            // retry storms don't surface 404s to the UI.
            return;
        }
        $this->tags->delete($id);

        $this->log('inspection.compliance_tag.deleted', $id, $actor->id, [
            'code' => $existing->code,
            'division_id' => $existing->division_id,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTags(User $actor, ?int $divisionId = null, bool $activeOnly = false): array
    {
        $this->gate->assert($actor, 'inspections.view');
        return array_map(
            fn(InspectionComplianceTag $t) => $this->serializeTag($t),
            $this->tags->listForDivision($divisionId, $activeOnly),
        );
    }

    // ── Template bindings ────────────────────────────────────────────────

    /**
     * Replace the tag set attached to a template. Passing [] clears the
     * template's compliance bindings.
     *
     * @param array<int, int> $tagIds
     * @return array<int, array<string, mixed>>
     */
    public function setTagsForTemplate(User $actor, int $templateId, array $tagIds): array
    {
        $this->gate->assert($actor, 'inspections.manage');
        if ($templateId <= 0) {
            throw new InvalidArgumentException('template_id is required');
        }
        $this->assertTemplateExists($templateId);

        $clean = $this->validateTagIdList($tagIds);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $this->tags->setTagsForTemplate($templateId, $clean);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->log('inspection.compliance_tag.template_bound', $templateId, $actor->id, [
            'tag_ids' => $clean,
        ]);

        return $this->listTagsForTemplate($actor, $templateId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTagsForTemplate(User $actor, int $templateId): array
    {
        $this->gate->assert($actor, 'inspections.view');
        if ($templateId <= 0) {
            throw new InvalidArgumentException('template_id is required');
        }
        return array_map(
            fn(InspectionComplianceTag $t) => $this->serializeTag($t),
            $this->tags->listTagsForTemplate($templateId),
        );
    }

    /**
     * List templates tagged with any of the given compliance codes,
     * optionally scoped to a division. When both divisionId and
     * tagCodes are provided the filter is AND-combined.
     *
     * @param array<int, string> $tagCodes
     * @return array<int, array<string, mixed>>
     */
    public function listTemplatesByCompliance(
        User $actor,
        ?int $divisionId,
        array $tagCodes,
        bool $activeOnly = false,
    ): array {
        $this->gate->assert($actor, 'inspections.view');
        return $this->tags->listTemplatesByCompliance($divisionId, $tagCodes, $activeOnly);
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   code: string,
     *   label: string,
     *   description: ?string,
     *   regulatory_body: string,
     *   division_id: ?int,
     *   is_active: bool,
     *   sort_order: int
     * }
     */
    private function validateTagInput(array $input, bool $isUpdate): array
    {
        $code = $this->trimmed($input, 'code');
        if ($code === null || $code === '') {
            throw new InvalidArgumentException('code is required');
        }
        if (strlen($code) > InspectionComplianceTag::CODE_MAX_LEN) {
            throw new InvalidArgumentException(
                'code exceeds ' . InspectionComplianceTag::CODE_MAX_LEN . ' chars'
            );
        }
        // Codes live in URLs and audit grep — normalize to lowercase +
        // enforce a tight character class so a sloppy "DOT Annual" and
        // a typo "dot_annual " don't end up as distinct tags.
        if (!preg_match('/^[a-z0-9][a-z0-9_.\\-]*$/', strtolower($code))) {
            throw new InvalidArgumentException(
                'code must be lowercase alphanumeric with . _ - only'
            );
        }
        $code = strtolower($code);

        $label = $this->trimmed($input, 'label');
        if ($label === null || $label === '') {
            throw new InvalidArgumentException('label is required');
        }
        if (strlen($label) > InspectionComplianceTag::LABEL_MAX_LEN) {
            throw new InvalidArgumentException(
                'label exceeds ' . InspectionComplianceTag::LABEL_MAX_LEN . ' chars'
            );
        }

        $description = $this->trimmed($input, 'description');
        if ($description !== null && strlen($description) > InspectionComplianceTag::DESCRIPTION_MAX_LEN) {
            throw new InvalidArgumentException(
                'description exceeds ' . InspectionComplianceTag::DESCRIPTION_MAX_LEN . ' chars'
            );
        }

        $regBody = $this->trimmed($input, 'regulatory_body') ?? InspectionComplianceTag::REG_BODY_OTHER;
        $regBody = strtolower($regBody);
        if (!in_array($regBody, InspectionComplianceTag::ALLOWED_REGULATORY_BODIES, true)) {
            throw new InvalidArgumentException(
                'regulatory_body must be one of ' . implode(',', InspectionComplianceTag::ALLOWED_REGULATORY_BODIES)
            );
        }

        $divisionId = null;
        if (array_key_exists('division_id', $input) && $input['division_id'] !== null && $input['division_id'] !== '') {
            $divisionId = (int) $input['division_id'];
            if ($divisionId <= 0) {
                throw new InvalidArgumentException('division_id must be a positive integer');
            }
        }

        $isActive = array_key_exists('is_active', $input) ? (bool) $input['is_active'] : true;
        $sortOrder = array_key_exists('sort_order', $input) ? (int) $input['sort_order'] : 0;
        if ($sortOrder < 0) {
            throw new InvalidArgumentException('sort_order must be >= 0');
        }

        return [
            'code' => $code,
            'label' => $label,
            'description' => $description,
            'regulatory_body' => $regBody,
            'division_id' => $divisionId,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * @param array<int, int> $tagIds
     * @return array<int, int>
     */
    private function validateTagIdList(array $tagIds): array
    {
        $clean = [];
        foreach ($tagIds as $raw) {
            $id = (int) $raw;
            if ($id <= 0) {
                throw new InvalidArgumentException('tag_ids must be positive integers');
            }
            if ($this->tags->findById($id) === null) {
                throw new InvalidArgumentException("compliance tag {$id} not found");
            }
            $clean[] = $id;
        }
        return array_values(array_unique($clean));
    }

    private function assertTemplateExists(int $templateId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT 1 FROM inspection_templates WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $templateId]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException("inspection template {$templateId} not found");
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function trimmed(array $input, string $key): ?string
    {
        if (!isset($input[$key])) {
            return null;
        }
        if (!is_string($input[$key])) {
            return (string) $input[$key];
        }
        $v = trim($input[$key]);
        return $v === '' ? null : $v;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTag(InspectionComplianceTag $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'label' => $t->label,
            'description' => $t->description,
            'regulatory_body' => $t->regulatory_body,
            'division_id' => $t->division_id,
            'is_active' => $t->is_active,
            'sort_order' => $t->sort_order,
            'created_by_user_id' => $t->created_by_user_id,
            'created_at' => $t->created_at,
            'updated_at' => $t->updated_at,
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
        $this->audit->log(new AuditEntry($event, 'inspection_compliance_tag', (string) $entityId, $actorId, $context));
    }
}
