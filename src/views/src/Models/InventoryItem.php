<?php

namespace App\Models;

class InventoryItem extends BaseModel
{
    public int $id;
    public string $name;
    public ?string $sku = null;
    public ?string $category = null;
    public int $stock_quantity = 0;
    public int $low_stock_threshold = 0;
    public int $reorder_quantity = 0;
    public float $cost = 0.0;
    public float $sale_price = 0.0;
    public ?float $markup = null;
    public ?string $location = null;
    public ?string $vendor = null;
    public ?string $notes = null;
}
