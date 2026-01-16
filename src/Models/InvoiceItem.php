<?php

namespace App\Models;

class InvoiceItem extends BaseModel
{
    public int $id;
    public int $invoice_id;
    public ?int $branch_id = null;
    public string $type;
    public ?string $sku = null;
    public ?int $inventory_item_id = null;
    public string $description;
    public float $quantity;
    public float $unit_price;
    public float $list_price = 0.0;
    public ?float $core_price = null;
    public ?int $core_return_id = null;
    public bool $taxable = true;
    public float $line_total = 0.0;
}
