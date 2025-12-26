<?php

namespace App\Models;

class InvoiceItem extends BaseModel
{
    public int $id;
    public int $invoice_id;
    public string $type;
    public string $description;
    public float $quantity;
    public float $unit_price;
    public float $list_price = 0.0;
    public bool $taxable = true;
    public float $line_total = 0.0;
}
