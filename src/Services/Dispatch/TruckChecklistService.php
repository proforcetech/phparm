<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use InvalidArgumentException;
use PDO;

class TruckChecklistService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTemplates(?string $type = null, bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM truck_checklist_templates WHERE 1=1';
        $params = [];

        if ($type !== null) {
            $sql .= ' AND checklist_type = :type';
            $params['type'] = $type;
        }

        if (!$includeInactive) {
            $sql .= ' AND active = 1';
        }

        $sql .= ' ORDER BY checklist_type ASC, is_default DESC, name ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($templates as &$template) {
            $template['items'] = $this->getTemplateItems((int) $template['id']);
        }

        return $templates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTemplate(int $templateId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM truck_checklist_templates WHERE id = :id');
        $stmt->execute(['id' => $templateId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            return null;
        }

        $template['items'] = $this->getTemplateItems($templateId);

        return $template;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDefaultTemplate(string $type): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM truck_checklist_templates WHERE checklist_type = :type AND is_default = 1 AND active = 1 LIMIT 1'
        );
        $stmt->execute(['type' => $type]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            return null;
        }

        $template['items'] = $this->getTemplateItems((int) $template['id']);

        return $template;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTemplateItems(int $templateId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM truck_checklist_template_items WHERE template_id = :id ORDER BY display_order ASC, id ASC'
        );
        $stmt->execute(['id' => $templateId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createTemplate(array $data, int $actorId): array
    {
        $this->assertChecklistType($data['checklist_type'] ?? null);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            if (!empty($data['is_default'])) {
                $this->unsetDefaultTemplates((string) $data['checklist_type']);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO truck_checklist_templates (name, description, checklist_type, is_default, active, created_by, created_at, updated_at)
                 VALUES (:name, :description, :checklist_type, :is_default, :active, :created_by, NOW(), NOW())'
            );
            $stmt->execute([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'checklist_type' => $data['checklist_type'],
                'is_default' => !empty($data['is_default']) ? 1 : 0,
                'active' => isset($data['active']) ? (int) (bool) $data['active'] : 1,
                'created_by' => $actorId,
            ]);

            $templateId = (int) $pdo->lastInsertId();
            $this->replaceTemplateItems($templateId, $data['items'] ?? []);

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->getTemplate($templateId) ?? [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateTemplate(int $templateId, array $data, int $actorId): array
    {
        $template = $this->getTemplate($templateId);
        if ($template === null) {
            throw new InvalidArgumentException('Checklist template not found.');
        }

        $checklistType = $data['checklist_type'] ?? $template['checklist_type'];
        $this->assertChecklistType($checklistType);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            if (!empty($data['is_default'])) {
                $this->unsetDefaultTemplates((string) $checklistType);
            }

            $stmt = $pdo->prepare(
                'UPDATE truck_checklist_templates
                 SET name = :name,
                     description = :description,
                     checklist_type = :checklist_type,
                     is_default = :is_default,
                     active = :active,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $templateId,
                'name' => $data['name'] ?? $template['name'],
                'description' => $data['description'] ?? $template['description'],
                'checklist_type' => $checklistType,
                'is_default' => isset($data['is_default']) ? (int) (bool) $data['is_default'] : (int) $template['is_default'],
                'active' => isset($data['active']) ? (int) (bool) $data['active'] : (int) $template['active'],
            ]);

            if (array_key_exists('items', $data)) {
                $this->replaceTemplateItems($templateId, $data['items'] ?? []);
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->getTemplate($templateId) ?? [];
    }

    public function deleteTemplate(int $templateId): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM truck_checklist_templates WHERE id = :id');
        $stmt->execute(['id' => $templateId]);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function replaceTemplateItems(int $templateId, array $items): void
    {
        $pdo = $this->connection->pdo();
        $pdo->prepare('DELETE FROM truck_checklist_template_items WHERE template_id = :id')->execute(['id' => $templateId]);

        if (count($items) === 0) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO truck_checklist_template_items (template_id, label, description, required, display_order)
             VALUES (:template_id, :label, :description, :required, :display_order)'
        );

        $order = 1;
        foreach ($items as $item) {
            if (empty($item['label'])) {
                continue;
            }

            $insert->execute([
                'template_id' => $templateId,
                'label' => $item['label'],
                'description' => $item['description'] ?? null,
                'required' => isset($item['required']) ? (int) (bool) $item['required'] : 1,
                'display_order' => isset($item['display_order']) ? (int) $item['display_order'] : $order,
            ]);
            $order++;
        }
    }

    private function unsetDefaultTemplates(string $type): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE truck_checklist_templates SET is_default = 0 WHERE checklist_type = :type'
        );
        $stmt->execute(['type' => $type]);
    }

    private function assertChecklistType(?string $type): void
    {
        if (!in_array($type, ['pre_trip', 'post_trip'], true)) {
            throw new InvalidArgumentException('Checklist type must be pre_trip or post_trip.');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listEntries(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $sql = 'FROM truck_checklist_entries tce '
            . 'LEFT JOIN truck_checklist_templates tct ON tct.id = tce.template_id '
            . 'LEFT JOIN driver_profiles dp ON dp.id = tce.driver_profile_id '
            . 'LEFT JOIN users u ON u.id = dp.user_id '
            . 'LEFT JOIN driver_shifts ds ON ds.id = tce.driver_shift_id '
            . 'WHERE 1=1';
        $params = [];

        if (!empty($filters['checklist_type'])) {
            $sql .= ' AND tce.checklist_type = :checklist_type';
            $params['checklist_type'] = $filters['checklist_type'];
        }

        if (!empty($filters['driver_profile_id'])) {
            $sql .= ' AND tce.driver_profile_id = :driver_profile_id';
            $params['driver_profile_id'] = (int) $filters['driver_profile_id'];
        }

        if (!empty($filters['start_date'])) {
            $sql .= ' AND tce.completed_at >= :start_date';
            $params['start_date'] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $sql .= ' AND tce.completed_at <= :end_date';
            $params['end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        $countStmt = $this->connection->pdo()->prepare('SELECT COUNT(*) ' . $sql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $select = 'SELECT tce.*, tct.name AS template_name, tct.checklist_type AS template_type, u.name AS driver_name, '
            . 'ds.shift_start, ds.shift_end '
            . $sql
            . ' ORDER BY tce.completed_at DESC, tce.id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($select);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $type);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEntry(int $entryId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT tce.*, tct.name AS template_name, tct.description AS template_description, u.name AS driver_name
             FROM truck_checklist_entries tce
             JOIN truck_checklist_templates tct ON tct.id = tce.template_id
             LEFT JOIN driver_profiles dp ON dp.id = tce.driver_profile_id
             LEFT JOIN users u ON u.id = dp.user_id
             WHERE tce.id = :id'
        );
        $stmt->execute(['id' => $entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entry) {
            return null;
        }

        $entry['items'] = $this->getEntryItems($entryId);

        return $entry;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getEntryItems(int $entryId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT tcei.*, tcti.label, tcti.description, tcti.required
             FROM truck_checklist_entry_items tcei
             JOIN truck_checklist_template_items tcti ON tcti.id = tcei.template_item_id
             WHERE tcei.entry_id = :id
             ORDER BY tcti.display_order ASC, tcti.id ASC'
        );
        $stmt->execute(['id' => $entryId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function createEntry(
        int $driverProfileId,
        int $templateId,
        string $checklistType,
        array $items,
        ?string $notes,
        ?int $driverShiftId,
        int $actorId
    ): array {
        $template = $this->getTemplate($templateId);
        if ($template === null) {
            throw new InvalidArgumentException('Checklist template not found.');
        }

        if ($template['checklist_type'] !== $checklistType) {
            throw new InvalidArgumentException('Checklist type does not match template.');
        }

        $templateItems = $template['items'] ?? [];
        $itemsByTemplate = [];
        foreach ($items as $item) {
            if (!isset($item['template_item_id'])) {
                continue;
            }
            $itemsByTemplate[(int) $item['template_item_id']] = $item;
        }

        foreach ($templateItems as $templateItem) {
            $templateItemId = (int) $templateItem['id'];
            $required = (bool) ($templateItem['required'] ?? false);
            $response = $itemsByTemplate[$templateItemId]['response'] ?? null;

            if ($required && $this->normalizeResponse($response) === null) {
                throw new InvalidArgumentException('Required checklist items must be completed.');
            }
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO truck_checklist_entries
                    (driver_profile_id, driver_shift_id, template_id, checklist_type, status, completed_at, completed_by, notes, created_at, updated_at)
                 VALUES
                    (:driver_profile_id, :driver_shift_id, :template_id, :checklist_type, :status, NOW(), :completed_by, :notes, NOW(), NOW())'
            );
            $stmt->execute([
                'driver_profile_id' => $driverProfileId,
                'driver_shift_id' => $driverShiftId,
                'template_id' => $templateId,
                'checklist_type' => $checklistType,
                'status' => 'completed',
                'completed_by' => $actorId,
                'notes' => $notes,
            ]);

            $entryId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO truck_checklist_entry_items (entry_id, template_item_id, response, notes, checked_by, checked_at)
                 VALUES (:entry_id, :template_item_id, :response, :notes, :checked_by, NOW())'
            );

            foreach ($templateItems as $templateItem) {
                $templateItemId = (int) $templateItem['id'];
                $payload = $itemsByTemplate[$templateItemId] ?? [];
                $response = $this->normalizeResponse($payload['response'] ?? null);

                $insertItem->execute([
                    'entry_id' => $entryId,
                    'template_item_id' => $templateItemId,
                    'response' => $response,
                    'notes' => $payload['notes'] ?? null,
                    'checked_by' => $actorId,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->getEntry($entryId) ?? [];
    }

    private function normalizeResponse(?string $response): ?string
    {
        if ($response === null || $response === '') {
            return null;
        }

        $normalized = strtolower(trim($response));
        if (!in_array($normalized, ['pass', 'fail', 'na'], true)) {
            throw new InvalidArgumentException('Checklist response must be pass, fail, or na.');
        }

        return $normalized;
    }
}
