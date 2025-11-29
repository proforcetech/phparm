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

        $data['year'] = $year;
        $data['make'] = trim((string) $data['make']);
        $data['model'] = trim((string) $data['model']);
        $data['engine'] = trim((string) $data['engine']);
        $data['transmission'] = trim((string) $data['transmission']);
        $data['drive'] = trim((string) $data['drive']);
        $data['trim'] = isset($data['trim']) && $data['trim'] !== '' ? trim((string) $data['trim']) : null;

        return $data;
    }
}
