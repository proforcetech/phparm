<?php

namespace App\Models;

class CreditAccount extends BaseModel
{
    public int $id;
    public int $customer_id;
    public string $type;
    public float $credit_limit = 0.0;
    public float $balance = 0.0;
    public float $available_credit = 0.0;
    public int $net_days = 0;
    public float $apr = 0.0;
    public float $late_fee = 0.0;
    public string $status;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
