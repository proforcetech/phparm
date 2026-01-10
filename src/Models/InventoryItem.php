<?php

namespace App\Models;

class InventoryItem extends BaseModel
{
    public int $id;
    public string $name;
    public ?string $description = null;
    public ?string $sku = null;
    public ?string $manufacturer_part_number = null;
    public ?string $category = null;
    public int $stock_quantity = 0;
    public int $low_stock_threshold = 0;
    public bool $is_low_stock = false;
    public int $reorder_quantity = 0;
    public ?int $reorder_point_override = null;
    public ?string $reorder_point_override_reason = null;
    public ?string $reorder_point_override_updated_at = null;
    public ?int $reorder_point_override_updated_by = null;
    public float $cost = 0.0;
    public float $sale_price = 0.0;
    public float $list_price = 0.0;
    public ?float $markup = null;
    public ?string $location = null;
    public ?string $bin_location = null;
    public ?string $vendor = null;
    public ?string $notes = null;
    public bool $is_tracked = true;
    public float $usage_rate_30d = 0.0;
    public int $suggested_reorder_point = 0;
    public int $effective_reorder_point = 0;
    public string $reorder_point_source = 'suggested';
}
