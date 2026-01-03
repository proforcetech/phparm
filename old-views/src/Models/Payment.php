<?php

namespace App\Models;

class Payment extends BaseModel
{
    public int $id;
    public int $invoice_id;
    public string $gateway;
    public string $transaction_id;
    public float $amount;
    public string $status;
    public string $paid_at;
    public ?string $created_at = null;
}
