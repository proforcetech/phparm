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
    public bool $taxable = true;
}
