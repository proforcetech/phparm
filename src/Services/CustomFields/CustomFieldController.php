<?php

namespace App\Services\CustomFields;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Thin HTTP-facing layer over CustomFieldService.
 *
 * Permissions:
 *   - custom_fields.view    — read definitions/values (broadly granted)
 *   - custom_fields.manage  — create/update/delete definitions, write values
 */
class CustomFieldController
{
    public function __construct(
        private readonly CustomFieldService $service,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listDefinitions(User $user, string $entityType, ?int $divisionId, bool $includeInactive): array
    {
        $this->gate->assert($user, 'custom_fields.view');

        return [
            'data' => $this->service->listDefinitions($entityType, $divisionId, !$includeInactive),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createDefinition(User $user, array $body): array
    {
        $this->gate->assert($user, 'custom_fields.manage');

        return ['data' => $this->service->createDefinition($body)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateDefinition(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'custom_fields.manage');

        return ['data' => $this->service->updateDefinition($id, $body)];
    }

    public function deleteDefinition(User $user, int $id): void
    {
        $this->gate->assert($user, 'custom_fields.manage');

        $this->service->deleteDefinition($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function getValues(User $user, string $entityType, int $entityId, ?int $divisionId): array
    {
        $this->gate->assert($user, 'custom_fields.view');

        return [
            'data' => $this->service->getForEntity($entityType, $entityId, $divisionId),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function saveValues(User $user, array $body): array
    {
        $this->gate->assert($user, 'custom_fields.manage');

        $entityType = (string) ($body['entity_type'] ?? '');
        $entityId = (int) ($body['entity_id'] ?? 0);
        $values = $body['values'] ?? null;
        $divisionId = isset($body['division_id']) ? (int) $body['division_id'] : null;

        if ($entityType === '' || $entityId <= 0) {
            throw new InvalidArgumentException('entity_type and entity_id are required');
        }
        if (!is_array($values)) {
            throw new InvalidArgumentException('values must be an object keyed by field_key');
        }

        return [
            'data' => $this->service->saveValues($entityType, $entityId, $values, $divisionId),
        ];
    }
}
