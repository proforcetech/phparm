<?php

namespace App\Services\ServiceType;

use InvalidArgumentException;

class ServiceTypeValidator
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validate(array $data): array
    {
        if (!isset($data['name']) || trim((string) $data['name']) === '') {
            throw new InvalidArgumentException('Service type name is required.');
        }

        $name = trim((string) $data['name']);
        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Service type name must be 120 characters or fewer.');
        }

        $alias = isset($data['alias']) && $data['alias'] !== '' ? trim((string) $data['alias']) : null;
        if ($alias !== null && mb_strlen($alias) > 120) {
            throw new InvalidArgumentException('Alias must be 120 characters or fewer.');
        }

        $description = isset($data['description']) && $data['description'] !== ''
            ? trim((string) $data['description'])
            : null;

        $displayOrder = isset($data['display_order']) ? (int) $data['display_order'] : 0;
        if ($displayOrder < 0) {
            throw new InvalidArgumentException('Display order cannot be negative.');
        }

        $active = isset($data['active']) ? (bool) $data['active'] : true;

        return [
            'name' => $name,
            'alias' => $alias,
            'description' => $description,
            'active' => $active,
            'display_order' => $displayOrder,
        ];
    }
}
