<?php

namespace App\Models;

class InventoryStockOrder extends BaseModel
{
    public const STATUS_BACKORDER = 'backorder';
    public const STATUS_ON_ORDER = 'on_order';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALLOWED_STATUSES = [
        self::STATUS_BACKORDER,
        self::STATUS_ON_ORDER,
        self::STATUS_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    public int $id;
    public ?int $inventory_item_id = null;
    public ?string $sku = null;
    public string $description;
    public int $quantity_ordered = 1;
    public string $status = self::STATUS_ON_ORDER;
    public ?string $expected_arrival_date = null;
    public ?string $notes = null;
    public ?string $vendor = null;
    public ?string $order_reference = null;
    public ?int $created_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public ?string $inventory_item_name = null;
    public ?string $created_by_name = null;
}
