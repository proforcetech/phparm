<?php

namespace App\Services\CustomFields;

use App\Database\Connection;
use PDO;

class CustomFieldRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitionsFor(string $entityType, ?int $divisionId = null, bool $activeOnly = true): array
    {
        $sql = 'SELECT id, division_id, entity_type, field_key, label, field_type,
                       options_json, is_required, sort_order, is_active, created_at, updated_at
                FROM custom_field_definitions
                WHERE entity_type = :entity_type';
        $params = ['entity_type' => $entityType];

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        if ($divisionId !== null) {
            $sql .= ' AND (division_id = :division_id OR division_id IS NULL)';
            $params['division_id'] = $divisionId;
        }

        $sql .= ' ORDER BY sort_order, id';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(
            [self::class, 'hydrateDefinition'],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDefinition(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, division_id, entity_type, field_key, label, field_type,
                    options_json, is_required, sort_order, is_active, created_at, updated_at
             FROM custom_field_definitions WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::hydrateDefinition($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createDefinition(array $data): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO custom_field_definitions
                (division_id, entity_type, field_key, label, field_type, options_json,
                 is_required, sort_order, is_active)
             VALUES
                (:division_id, :entity_type, :field_key, :label, :field_type, :options_json,
                 :is_required, :sort_order, :is_active)'
        );
        $stmt->execute([
            'division_id' => $data['division_id'] ?? null,
            'entity_type' => $data['entity_type'],
            'field_key' => $data['field_key'],
            'label' => $data['label'],
            'field_type' => $data['field_type'] ?? 'text',
            'options_json' => isset($data['options']) ? json_encode($data['options']) : null,
            'is_required' => (int) ($data['is_required'] ?? 0),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateDefinition(int $id, array $data): void
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (['label', 'field_type', 'sort_order', 'is_required', 'is_active'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = is_bool($data[$key]) ? (int) $data[$key] : $data[$key];
            }
        }
        if (array_key_exists('options', $data)) {
            $fields[] = 'options_json = :options_json';
            $params['options_json'] = $data['options'] === null ? null : json_encode($data['options']);
        }

        if ($fields === []) {
            return;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE custom_field_definitions SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        $stmt->execute($params);
    }

    public function deleteDefinition(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM custom_field_definitions WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>  Keyed by definition id.
     */
    public function valuesFor(string $entityType, int $entityId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT v.id, v.definition_id, v.value_text, v.value_number, v.value_date, v.value_json,
                    d.field_key, d.field_type
             FROM custom_field_values v
             INNER JOIN custom_field_definitions d ON d.id = v.definition_id
             WHERE v.entity_type = :entity_type AND v.entity_id = :entity_id'
        );
        $stmt->execute(['entity_type' => $entityType, 'entity_id' => $entityId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['definition_id']] = [
                'id' => (int) $row['id'],
                'definition_id' => (int) $row['definition_id'],
                'field_key' => $row['field_key'],
                'field_type' => $row['field_type'],
                'value' => self::extractValue($row),
            ];
        }

        return $out;
    }

    /**
     * Upsert a value. Any of value_text/value_number/value_date/value_json may
     * be set depending on the field_type — the caller determines which column
     * is active.
     *
     * @param array{
     *   value_text?: string|null,
     *   value_number?: float|int|null,
     *   value_date?: string|null,
     *   value_json?: array<mixed>|null
     * } $cols
     */
    public function upsertValue(int $definitionId, string $entityType, int $entityId, array $cols): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO custom_field_values
                (definition_id, entity_type, entity_id, value_text, value_number, value_date, value_json)
             VALUES
                (:definition_id, :entity_type, :entity_id, :value_text, :value_number, :value_date, :value_json)
             ON DUPLICATE KEY UPDATE
                value_text = VALUES(value_text),
                value_number = VALUES(value_number),
                value_date = VALUES(value_date),
                value_json = VALUES(value_json)'
        );
        $stmt->execute([
            'definition_id' => $definitionId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'value_text' => $cols['value_text'] ?? null,
            'value_number' => $cols['value_number'] ?? null,
            'value_date' => $cols['value_date'] ?? null,
            'value_json' => isset($cols['value_json']) && $cols['value_json'] !== null
                ? json_encode($cols['value_json'])
                : null,
        ]);
    }

    public function deleteValue(int $definitionId, string $entityType, int $entityId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM custom_field_values
             WHERE definition_id = :definition_id
               AND entity_type = :entity_type
               AND entity_id = :entity_id'
        );
        $stmt->execute([
            'definition_id' => $definitionId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrateDefinition(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'division_id' => $row['division_id'] === null ? null : (int) $row['division_id'],
            'entity_type' => $row['entity_type'],
            'field_key' => $row['field_key'],
            'label' => $row['label'],
            'field_type' => $row['field_type'],
            'options' => $row['options_json'] !== null ? json_decode($row['options_json'], true) : null,
            'is_required' => (bool) $row['is_required'],
            'sort_order' => (int) $row['sort_order'],
            'is_active' => (bool) $row['is_active'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function extractValue(array $row): mixed
    {
        return match ($row['field_type']) {
            'number' => $row['value_number'] === null ? null : (float) $row['value_number'],
            'date' => $row['value_date'],
            'boolean' => $row['value_text'] === null ? null : ($row['value_text'] === '1' || $row['value_text'] === 'true'),
            'select' => $row['value_text'],
            'multiselect', 'asset_ref' => $row['value_json'] !== null ? json_decode($row['value_json'], true) : null,
            default => $row['value_text'],
        };
    }
}
