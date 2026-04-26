<?php

namespace App\Models;

class WorkorderKitInstallItem extends BaseModel
{
    public const TYPE_LABOR = 'LABOR';
    public const TYPE_PART = 'PART';
    public const TYPE_FEE = 'FEE';
    public const TYPE_DISCOUNT = 'DISCOUNT';

    public const TYPES = [
        self::TYPE_LABOR,
        self::TYPE_PART,
        self::TYPE_FEE,
        self::TYPE_DISCOUNT,
    ];

    public ?int $id = null;
    public int $install_id = 0;
    public ?int $workorder_item_id = null;
    public ?int $bundle_item_id = null;
    public ?int $inventory_item_id = null;
    public string $type = self::TYPE_PART;
    public string $description = '';
    public float $quantity = 1.0;
    public float $unit_price = 0.0;
    public float $line_total = 0.0;
    public int $stock_consumed = 0;
    public ?string $stock_consumed_at = null;
    public ?string $created_at = null;
}
