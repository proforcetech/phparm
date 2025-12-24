<?php

namespace App\Models;

class CreditTransaction extends BaseModel
{
    public int $id;
    public int $credit_account_id;
    public int $customer_id;
    public string $transaction_type;
    public float $amount;
    public float $balance_after;
    public ?string $reference_type = null;
    public ?int $reference_id = null;
    public ?string $description = null;
    public ?int $created_by = null;
    public string $occurred_at;
    public string $created_at;
}
