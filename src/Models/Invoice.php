<?php

namespace App\Models;

class Invoice extends BaseModel
{
    public int $id;
    public string $number;
    public int $customer_id;
    public ?int $service_type_id = null;
    public ?int $vehicle_id = null;
    public ?int $estimate_id = null;
    public bool $is_mobile = false;
    public string $status;
    public string $issue_date;
    public ?string $due_date = null;
    public float $subtotal = 0.0;
    public float $tax = 0.0;
    public float $total = 0.0;
    public float $amount_paid = 0.0;
    public float $balance_due = 0.0;
    public float $shop_fee = 0.0;
    public float $hazmat_disposal_fee = 0.0;
    public ?string $public_token = null;
    public ?string $public_token_expires_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
