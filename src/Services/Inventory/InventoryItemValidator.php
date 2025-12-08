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

        $name = trim((string) $data['name']);
        if (strlen($name) > 160) {
            throw new InvalidArgumentException('Name must be 160 characters or fewer.');
        }

        $normalized = [
            'name' => $name,
            'sku' => isset($data['sku']) && $data['sku'] !== '' ? $this->sanitize((string) $data['sku'], 120) : null,
            'category' => isset($data['category']) && $data['category'] !== ''
                ? $this->sanitize((string) $data['category'], 120)
                : null,
            'stock_quantity' => isset($data['stock_quantity']) ? max(0, (int) $data['stock_quantity']) : 0,
            'low_stock_threshold' => isset($data['low_stock_threshold']) ? max(0, (int) $data['low_stock_threshold']) : 0,
            'reorder_quantity' => isset($data['reorder_quantity']) ? max(0, (int) $data['reorder_quantity']) : 0,
            'cost' => isset($data['cost']) ? max(0.0, (float) $data['cost']) : 0.0,
            'sale_price' => isset($data['sale_price']) ? max(0.0, (float) $data['sale_price']) : 0.0,
            'location' => isset($data['location']) && $data['location'] !== ''
                ? $this->sanitize((string) $data['location'], 160)
                : null,
            'vendor' => isset($data['vendor']) && $data['vendor'] !== ''
                ? $this->sanitize((string) $data['vendor'], 160)
                : null,
            'notes' => isset($data['notes']) && $data['notes'] !== '' ? trim((string) $data['notes']) : null,
        ];

        if ($normalized['sale_price'] < $normalized['cost']) {
            throw new InvalidArgumentException('Sale price cannot be lower than cost.');
        }

        $normalized['markup'] = isset($data['markup']) && $data['markup'] !== ''
            ? (float) $data['markup']
            : $this->calculateMarkup($normalized['cost'], $normalized['sale_price']);

        if ($normalized['markup'] !== null && $normalized['markup'] < 0) {
            throw new InvalidArgumentException('Markup cannot be negative.');
        }

        return $normalized;
    }

    private function sanitize(string $value, int $maxLength): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Value cannot be empty.');
        }

        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Value must be {$maxLength} characters or fewer.");
        }

        return $value;
    }

    private function calculateMarkup(float $cost, float $salePrice): ?float
    {
        if ($cost <= 0.0) {
            return null;
        }

        return round((($salePrice - $cost) / $cost) * 100, 2);
    }
}
