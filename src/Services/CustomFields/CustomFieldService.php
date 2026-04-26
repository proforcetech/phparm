<?php

namespace App\Services\CustomFields;

use InvalidArgumentException;

/**
 * Orchestrates custom field definitions and values.
 *
 * Phase 0.5 of docs/expansion-plan.md: gives each division the ability to
 * extend core entities (customer, site, workorder, ticket, asset, etc.)
 * without schema churn. Validation here keeps writes consistent across
 * modules; individual controllers should delegate rather than touching the
 * repository directly.
 */
class CustomFieldService
{
    /**
     * Entities that may carry custom fields. Keeping this closed prevents
     * callers from stuffing arbitrary entity_type values — the table is
     * indexed on entity_type and we want predictable scopes.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_ENTITIES = [
        'customer', 'site', 'site_asset', 'workorder', 'ticket',
        'estimate', 'invoice', 'appointment', 'vehicle', 'user',
        'inventory_item', 'contract',
    ];

    /**
     * @var array<int, string>
     */
    public const FIELD_TYPES = [
        'text', 'number', 'date', 'select', 'multiselect', 'boolean', 'asset_ref',
    ];

    public function __construct(private readonly CustomFieldRepository $repository)
    {
    }

    public function assertEntity(string $entityType): void
    {
        if (!in_array($entityType, self::SUPPORTED_ENTITIES, true)) {
            throw new InvalidArgumentException("Unsupported entity_type '{$entityType}'");
        }
    }

    /**
     * Returns definitions merged with the saved value for the given entity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getForEntity(string $entityType, int $entityId, ?int $divisionId = null): array
    {
        $this->assertEntity($entityType);

        $definitions = $this->repository->definitionsFor($entityType, $divisionId, true);
        $values = $this->repository->valuesFor($entityType, $entityId);

        $out = [];
        foreach ($definitions as $def) {
            $defId = (int) $def['id'];
            $out[] = $def + [
                'value' => $values[$defId]['value'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDefinitions(string $entityType, ?int $divisionId = null, bool $activeOnly = true): array
    {
        $this->assertEntity($entityType);

        return $this->repository->definitionsFor($entityType, $divisionId, $activeOnly);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createDefinition(array $data): array
    {
        $entityType = (string) ($data['entity_type'] ?? '');
        $this->assertEntity($entityType);

        $fieldKey = (string) ($data['field_key'] ?? '');
        $label = (string) ($data['label'] ?? '');
        $fieldType = (string) ($data['field_type'] ?? 'text');

        if ($fieldKey === '' || $label === '') {
            throw new InvalidArgumentException('field_key and label are required');
        }
        if (!preg_match('/^[a-z0-9_]+$/', $fieldKey)) {
            throw new InvalidArgumentException('field_key must be lowercase alphanumeric with underscores');
        }
        if (!in_array($fieldType, self::FIELD_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported field_type '{$fieldType}'");
        }

        $options = $this->normalizeOptions($fieldType, $data['options'] ?? null);

        $id = $this->repository->createDefinition([
            'division_id' => isset($data['division_id']) ? (int) $data['division_id'] : null,
            'entity_type' => $entityType,
            'field_key' => $fieldKey,
            'label' => $label,
            'field_type' => $fieldType,
            'options' => $options,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $created = $this->repository->findDefinition($id);
        if ($created === null) {
            throw new \RuntimeException('Definition was created but could not be re-read');
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateDefinition(int $id, array $data): array
    {
        $existing = $this->repository->findDefinition($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Custom field definition {$id} not found");
        }

        $updates = [];
        if (isset($data['label'])) {
            $updates['label'] = (string) $data['label'];
        }
        if (isset($data['sort_order'])) {
            $updates['sort_order'] = (int) $data['sort_order'];
        }
        if (isset($data['is_required'])) {
            $updates['is_required'] = (bool) $data['is_required'];
        }
        if (isset($data['is_active'])) {
            $updates['is_active'] = (bool) $data['is_active'];
        }
        if (isset($data['field_type'])) {
            $newType = (string) $data['field_type'];
            if (!in_array($newType, self::FIELD_TYPES, true)) {
                throw new InvalidArgumentException("Unsupported field_type '{$newType}'");
            }
            // Changing field_type is risky (existing values may land in the
            // wrong column) — allow it but force callers to pass options
            // fresh so select/multiselect constraints aren't stale.
            $updates['field_type'] = $newType;
        }
        if (array_key_exists('options', $data)) {
            $targetType = $updates['field_type'] ?? $existing['field_type'];
            $updates['options'] = $this->normalizeOptions($targetType, $data['options']);
        }

        if ($updates !== []) {
            $this->repository->updateDefinition($id, $updates);
        }

        $fresh = $this->repository->findDefinition($id);
        if ($fresh === null) {
            throw new \RuntimeException('Definition disappeared during update');
        }

        return $fresh;
    }

    public function deleteDefinition(int $id): void
    {
        $existing = $this->repository->findDefinition($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Custom field definition {$id} not found");
        }
        $this->repository->deleteDefinition($id);
    }

    /**
     * Bulk upsert values for an entity keyed by field_key.
     *
     * @param array<string, mixed> $values  [field_key => value]
     * @return array<int, array<string, mixed>>  Merged def+value result after save.
     */
    public function saveValues(string $entityType, int $entityId, array $values, ?int $divisionId = null): array
    {
        $this->assertEntity($entityType);

        $definitions = $this->repository->definitionsFor($entityType, $divisionId, true);
        $defsByKey = [];
        foreach ($definitions as $def) {
            $defsByKey[$def['field_key']] = $def;
        }

        foreach ($values as $key => $raw) {
            if (!isset($defsByKey[$key])) {
                throw new InvalidArgumentException("Unknown custom field '{$key}' for entity '{$entityType}'");
            }
            $def = $defsByKey[$key];
            $cols = $this->valueToColumns($def, $raw);

            if ($cols === null) {
                // null → treat as delete
                $this->repository->deleteValue((int) $def['id'], $entityType, $entityId);
                continue;
            }
            $this->repository->upsertValue((int) $def['id'], $entityType, $entityId, $cols);
        }

        // Required field enforcement — run after writes so callers can send a
        // partial payload that completes the required set.
        $postValues = $this->repository->valuesFor($entityType, $entityId);
        foreach ($definitions as $def) {
            if (!$def['is_required']) {
                continue;
            }
            $val = $postValues[(int) $def['id']]['value'] ?? null;
            if ($val === null || $val === '' || (is_array($val) && $val === [])) {
                throw new InvalidArgumentException(
                    "Custom field '{$def['field_key']}' is required"
                );
            }
        }

        return $this->getForEntity($entityType, $entityId, $divisionId);
    }

    /**
     * Translate a user-supplied value into the column map for the value row.
     * Returns null if the value should clear the stored value.
     *
     * @param array<string, mixed> $def
     * @return array<string, mixed>|null
     */
    private function valueToColumns(array $def, mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $cols = [
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_json' => null,
        ];

        switch ($def['field_type']) {
            case 'number':
                if (!is_numeric($raw)) {
                    throw new InvalidArgumentException("Field '{$def['field_key']}' must be numeric");
                }
                $cols['value_number'] = (float) $raw;
                break;

            case 'date':
                $str = (string) $raw;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
                    throw new InvalidArgumentException("Field '{$def['field_key']}' must be YYYY-MM-DD");
                }
                $cols['value_date'] = $str;
                break;

            case 'boolean':
                $cols['value_text'] = $this->toBool($raw) ? '1' : '0';
                break;

            case 'select':
                $str = (string) $raw;
                $this->assertInOptions($def, $str);
                $cols['value_text'] = $str;
                break;

            case 'multiselect':
                if (!is_array($raw)) {
                    throw new InvalidArgumentException("Field '{$def['field_key']}' must be an array");
                }
                foreach ($raw as $item) {
                    $this->assertInOptions($def, (string) $item);
                }
                $cols['value_json'] = array_values(array_map('strval', $raw));
                break;

            case 'asset_ref':
                if (!is_array($raw)) {
                    throw new InvalidArgumentException("Field '{$def['field_key']}' must be an array of asset ids");
                }
                $ids = [];
                foreach ($raw as $item) {
                    if (!is_numeric($item)) {
                        throw new InvalidArgumentException("Field '{$def['field_key']}' contains a non-numeric asset id");
                    }
                    $ids[] = (int) $item;
                }
                $cols['value_json'] = $ids;
                break;

            case 'text':
            default:
                $cols['value_text'] = (string) $raw;
                break;
        }

        return $cols;
    }

    /**
     * @param array<string, mixed> $def
     */
    private function assertInOptions(array $def, string $value): void
    {
        $options = $def['options'] ?? null;
        if (!is_array($options) || $options === []) {
            return; // no restriction configured
        }
        // options can be either a plain list ["a","b"] or pairs [{"value":"a","label":"A"}]
        $allowed = [];
        foreach ($options as $opt) {
            if (is_array($opt) && isset($opt['value'])) {
                $allowed[] = (string) $opt['value'];
            } else {
                $allowed[] = (string) $opt;
            }
        }
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "Field '{$def['field_key']}' value '{$value}' is not in the allowed option list"
            );
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function normalizeOptions(string $fieldType, mixed $options): ?array
    {
        if ($options === null) {
            return null;
        }
        if (!is_array($options)) {
            throw new InvalidArgumentException('options must be an array');
        }
        if (!in_array($fieldType, ['select', 'multiselect'], true)) {
            // options are only meaningful for select variants; drop silently
            return null;
        }
        return array_values($options);
    }

    private function toBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw !== 0;
        }
        $s = strtolower((string) $raw);
        return $s === 'true' || $s === '1' || $s === 'yes' || $s === 'on';
    }
}
