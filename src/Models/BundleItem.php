<?php

namespace App\Models;

class BundleItem extends BaseModel
{
    public int $id;
    public int $bundle_id;
    public string $type;
    public string $description;
    public float $quantity;
    public float $unit_price;
    public float $list_price = 0.0;
    public bool $taxable = true;
    public int $sort_order = 0;
}
