<?php

namespace App\Models;

class WorkorderItem extends BaseModel
{
    public int $id;
    public int $workorder_job_id;
    public ?int $branch_id = null;
    public ?int $estimate_item_id = null;
    public string $type;
    public ?string $sku = null;
    public ?int $inventory_item_id = null;
    public string $description;
    public float $quantity;
    public float $unit_price;
    public ?float $list_price = null;
    public ?float $core_price = null;
    public ?int $core_return_id = null;
    public bool $taxable = true;
    public float $line_total = 0.0;
    public int $position = 0;
}
