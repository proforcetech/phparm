<?php

namespace App\Services\Inventory;

use InvalidArgumentException;

class InventoryItemValidator
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validate(array $data): array
    {
        if (!isset($data['name']) || trim((string) $data['name']) === '') {
            throw new InvalidArgumentException('Inventory item name is required.');
        }

        $name = $this->sanitizeText((string) $data['name'], 160, 'Name');
        if ($name === '') {
            throw new InvalidArgumentException('Inventory item name is required.');
        }
        if (strlen($name) > 160) {
            throw new InvalidArgumentException('Name must be 160 characters or fewer.');
        }

        $description = isset($data['description']) && $data['description'] !== ''
            ? $this->sanitizeText((string) $data['description'], 2000, 'Description')
            : null;
        if ($description === '') {
            $description = null;
        }

        $notes = isset($data['notes']) && $data['notes'] !== ''
            ? $this->sanitizeText((string) $data['notes'], 2000, 'Notes')
            : null;
        if ($notes === '') {
            $notes = null;
        }

        $sku = isset($data['sku']) && $data['sku'] !== '' ? $this->sanitize((string) $data['sku'], 120, 'SKU') : null;
        if ($sku !== null && !preg_match('/^[A-Za-z0-9]+$/', $sku)) {
            throw new InvalidArgumentException('SKU must be alphanumeric.');
        }

        $normalized = [
            'name' => $name,
            'description' => $description,
            'sku' => $sku,
            'manufacturer_part_number' => isset($data['manufacturer_part_number']) && $data['manufacturer_part_number'] !== ''
                ? $this->sanitize((string) $data['manufacturer_part_number'], 120, 'Manufacturer part number')
                : null,
            'category' => isset($data['category']) && $data['category'] !== ''
                ? $this->sanitize((string) $data['category'], 120, 'Category')
                : null,
            'stock_quantity' => $this->parseIntField($data['stock_quantity'] ?? null, 'Stock quantity'),
            'low_stock_threshold' => $this->parseIntField($data['low_stock_threshold'] ?? null, 'Low stock threshold'),
            'reorder_quantity' => $this->parseIntField($data['reorder_quantity'] ?? null, 'Reorder quantity'),
            'cost' => $this->parseFloatField($data['cost'] ?? null, 'Cost'),
            'sale_price' => $this->parseFloatField($data['sale_price'] ?? null, 'Sale price'),
            'list_price' => $this->parseFloatField($data['list_price'] ?? null, 'List price'),
            'location' => isset($data['location']) && $data['location'] !== ''
                ? $this->sanitize((string) $data['location'], 160, 'Location')
                : null,
            'vendor' => isset($data['vendor']) && $data['vendor'] !== ''
                ? $this->sanitize((string) $data['vendor'], 160, 'Vendor')
                : null,
            'notes' => $notes,
            'is_tracked' => isset($data['is_tracked']) ? (bool) $data['is_tracked'] : true,
        ];

        if ($normalized['sale_price'] < $normalized['cost']) {
            throw new InvalidArgumentException('Sale price cannot be lower than cost.');
        }

        $normalized['markup'] = isset($data['markup']) && $data['markup'] !== ''
            ? $this->parseFloatField($data['markup'], 'Markup')
            : $this->calculateMarkup($normalized['cost'], $normalized['sale_price']);

        if ($normalized['markup'] !== null && $normalized['markup'] < 0) {
            throw new InvalidArgumentException('Markup cannot be negative.');
        }

        return $normalized;
    }

    private function sanitize(string $value, int $maxLength, string $label): string
    {
        $value = $this->sanitizeText($value, $maxLength, $label);
        if ($value === '') {
            throw new InvalidArgumentException('Value cannot be empty.');
        }

        return $value;
    }

    private function sanitizeText(string $value, int $maxLength, string $label): string
    {
        $value = trim(strip_tags($value));
        $value = str_replace(["\r", "\n"], ' ', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        if ($value === '') {
            return $value;
        }

        if (preg_match('/^[=+\-@\t]/', $value)) {
            throw new InvalidArgumentException("{$label} contains an unsafe CSV formula prefix.");
        }

        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException("{$label} must be {$maxLength} characters or fewer.");
        }

        return $value;
    }

    private function parseIntField($value, string $label): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_INT);
        if ($filtered === false) {
            throw new InvalidArgumentException("{$label} must be a whole number.");
        }

        if ($filtered < 0) {
            throw new InvalidArgumentException("{$label} cannot be negative.");
        }

        return $filtered;
    }

    private function parseFloatField($value, string $label): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($filtered === false) {
            throw new InvalidArgumentException("{$label} must be a number.");
        }

        if ($filtered < 0) {
            throw new InvalidArgumentException("{$label} cannot be negative.");
        }

        return (float) $filtered;
    }

    private function calculateMarkup(float $cost, float $salePrice): ?float
    {
        if ($cost <= 0.0) {
            return null;
        }

        return round((($salePrice - $cost) / $cost) * 100, 2);
    }
}
