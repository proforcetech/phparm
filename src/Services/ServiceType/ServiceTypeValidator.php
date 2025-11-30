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

        $color = isset($data['color']) && $data['color'] !== '' ? trim((string) $data['color']) : null;
        if ($color !== null) {
            if (!preg_match('/^#?[0-9A-Fa-f]{6}$/', $color)) {
                throw new InvalidArgumentException('Color must be a valid 6-digit hex code.');
            }

            $color = str_starts_with($color, '#') ? $color : '#' . $color;
        }

        $icon = isset($data['icon']) && $data['icon'] !== '' ? trim((string) $data['icon']) : null;
        if ($icon !== null && mb_strlen($icon) > 120) {
            throw new InvalidArgumentException('Icon must be 120 characters or fewer.');
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
            'color' => $color,
            'icon' => $icon,
            'description' => $description,
            'active' => $active,
            'display_order' => $displayOrder,
        ];
    }
}
