<?php

namespace App\Models;

class Estimate extends BaseModel
{
    public int $id;
    public ?int $parent_id = null;
    public string $number;
    public int $customer_id;
    public int $vehicle_id;
    public bool $is_mobile = false;
    public string $status;
    public ?int $technician_id = null;
    public ?string $expiration_date = null;
    public float $subtotal = 0.0;
    public float $tax = 0.0;
    public float $call_out_fee = 0.0;
    public float $mileage_total = 0.0;
    public float $discounts = 0.0;
    public float $grand_total = 0.0;
    public ?string $internal_notes = null;
    public ?string $customer_notes = null;
    public ?string $rejection_reason = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
