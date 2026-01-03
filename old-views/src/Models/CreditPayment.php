<?php

namespace App\Models;

class CreditPayment extends BaseModel
{
    public int $id;
    public int $credit_account_id;
    public int $customer_id;
    public string $payment_method;
    public float $amount;
    public string $payment_date;
    public ?string $reference_number = null;
    public ?string $notes = null;
    public ?int $processed_by = null;
    public string $status;
    public string $created_at;
    public string $updated_at;
}
