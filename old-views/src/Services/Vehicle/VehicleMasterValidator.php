<?php

namespace App\Services\Vehicle;

use InvalidArgumentException;

class VehicleMasterValidator
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validate(array $data): array
    {
        $required = ['year', 'make', 'model', 'engine', 'transmission', 'drive'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new InvalidArgumentException("{$field} is required for vehicle master records.");
            }
        }

        $year = (int) $data['year'];
        $currentYear = (int) date('Y');
        if ($year < 1950 || $year > $currentYear + 1) {
            throw new InvalidArgumentException('Year must be between 1950 and next calendar year.');
        }

        $normalized = [
            'year' => $year,
            'make' => $this->sanitize((string) $data['make'], 'make'),
            'model' => $this->sanitize((string) $data['model'], 'model'),
            'engine' => $this->sanitize((string) $data['engine'], 'engine'),
            'transmission' => $this->sanitize((string) $data['transmission'], 'transmission'),
            'drive' => $this->sanitize((string) $data['drive'], 'drive'),
            'trim' => isset($data['trim']) && $data['trim'] !== ''
                ? $this->sanitize((string) $data['trim'], 'trim', true)
                : null,
        ];

        return $normalized;
    }

    private function sanitize(string $value, string $field, bool $allowNull = false): string
    {
        $value = trim($value);

        if ($value === '' && !$allowNull) {
            throw new InvalidArgumentException("{$field} is required for vehicle master records.");
        }

        if (strlen($value) > 120) {
            throw new InvalidArgumentException("{$field} must be 120 characters or fewer.");
        }

        if (!preg_match('/^[A-Za-z0-9 .\\-]+$/', $value)) {
            throw new InvalidArgumentException("{$field} contains invalid characters. Use letters, numbers, spaces, dots, or hyphens.");
        }

        return $value;
    }
}
