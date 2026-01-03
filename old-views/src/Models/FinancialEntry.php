<?php

namespace App\Models;

class FinancialEntry extends BaseModel
{
    public int $id;
    public string $type;
    public string $category;
    public string $reference;
    public string $purchase_order;
    public float $amount;
    public string $entry_date;
    public ?string $vendor = null;
    public ?string $description = null;
    public ?string $attachment_path = null;
}
