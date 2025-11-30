<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionItem;
use App\Models\InspectionSection;
use App\Models\InspectionTemplate;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use Throwable;

class InspectionTemplateService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, int $actorId): InspectionTemplate
    {
        $this->assertTemplatePayload($payload);
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $templateId = $this->insertTemplate($payload);
            $this->insertSections($templateId, $payload['sections']);
            $pdo->commit();

            $template = $this->fetchTemplate($templateId);
            $this->log('inspection.template_created', $templateId, $actorId, ['after' => $template?->toArray()]);

            return $template ?? new InspectionTemplate(['id' => $templateId]);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $templateId, array $payload, int $actorId): ?InspectionTemplate
    {
        $existing = $this->fetchTemplate($templateId);
        if ($existing === null) {
            return null;
        }

        $this->assertTemplatePayload($payload, true);
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $this->updateTemplateHeader($templateId, $payload);
            $this->deleteSections($templateId);
            $this->insertSections($templateId, $payload['sections']);
            $pdo->commit();

            $updated = $this->fetchTemplate($templateId);
            $this->log('inspection.template_updated', $templateId, $actorId, [
                'before' => $existing->toArray(),
                'after' => $updated?->toArray(),
            ]);

            return $updated;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @return array<int, InspectionTemplate>
     */
    public function list(?bool $activeOnly = null): array
    {
        $where = '';
        $bindings = [];

        if ($activeOnly !== null) {
            $where = 'WHERE active = :active';
            $bindings['active'] = $activeOnly ? 1 : 0;
        }

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inspection_templates ' . $where . ' ORDER BY name ASC');
        $stmt->execute($bindings);

        $templates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $templates[] = $this->mapTemplate($row);
        }

        return $templates;
    }

    public function activate(int $templateId, bool $active, ?int $actorId = null): bool
    {
        $stmt = $this->connection->pdo()->prepare('UPDATE inspection_templates SET active = :active WHERE id = :id');
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $templateId]);

        if ($stmt->rowCount() > 0) {
            $this->log('inspection.template_activation_changed', $templateId, $actorId, ['active' => $active]);
            return true;
        }

        return false;
    }

    private function assertTemplatePayload(array $payload, bool $isUpdate = false): void
    {
        $required = ['name', 'sections'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new InvalidArgumentException('Missing required template field: ' . $field);
            }
        }

        if (!is_array($payload['sections']) || $payload['sections'] === []) {
            throw new InvalidArgumentException('Inspection template requires at least one section.');
        }

        foreach ($payload['sections'] as $section) {
            if (empty($section['name'])) {
                throw new InvalidArgumentException('Inspection sections require a name.');
            }

            if (!isset($section['items']) || !is_array($section['items']) || $section['items'] === []) {
                throw new InvalidArgumentException('Inspection sections require items.');
            }

            foreach ($section['items'] as $item) {
                if (empty($item['name']) || empty($item['input_type'])) {
                    throw new InvalidArgumentException('Inspection items require name and input_type.');
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertTemplate(array $payload): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO inspection_templates (name, description, active) VALUES (:name, :description, :active)'
        );

        $stmt->execute([
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
            'active' => $payload['active'] ?? true,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateTemplateHeader(int $templateId, array $payload): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE inspection_templates SET name = :name, description = :description, active = :active WHERE id = :id'
        );

        $stmt->execute([
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
            'active' => $payload['active'] ?? true,
            'id' => $templateId,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     */
    private function insertSections(int $templateId, array $sections): void
    {
        foreach ($sections as $sectionOrder => $section) {
            $sectionId = $this->insertSection($templateId, $section, $sectionOrder);
            $this->insertItems($sectionId, $section['items']);
        }
    }

    /**
     * @param array<string, mixed> $section
     */
    private function insertSection(int $templateId, array $section, int $displayOrder): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO inspection_sections (template_id, name, display_order) VALUES (:template_id, :name, :display_order)'
        );

        $stmt->execute([
            'template_id' => $templateId,
            'name' => $section['name'],
            'display_order' => $displayOrder,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function insertItems(int $sectionId, array $items): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO inspection_items (section_id, name, input_type, default_value, display_order) VALUES (:section_id, :name, :input_type, :default_value, :display_order)'
        );

        foreach ($items as $displayOrder => $item) {
            $stmt->execute([
                'section_id' => $sectionId,
                'name' => $item['name'],
                'input_type' => $item['input_type'],
                'default_value' => $item['default_value'] ?? null,
                'display_order' => $displayOrder,
            ]);
        }
    }

    private function deleteSections(int $templateId): void
    {
        $pdo = $this->connection->pdo();
        $pdo->prepare('DELETE FROM inspection_items WHERE section_id IN (SELECT id FROM inspection_sections WHERE template_id = :template_id)')
            ->execute(['template_id' => $templateId]);
        $pdo->prepare('DELETE FROM inspection_sections WHERE template_id = :template_id')
            ->execute(['template_id' => $templateId]);
    }

    private function fetchTemplate(int $templateId): ?InspectionTemplate
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inspection_templates WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $templateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapTemplate($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapTemplate(array $row): InspectionTemplate
    {
        return new InspectionTemplate([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => $row['description'],
            'active' => (bool) $row['active'],
        ]);
    }

    private function log(string $event, int $templateId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'inspection_template', (string) $templateId, $actorId, $context));
    }
}
