<?php

namespace App\Models;

class EstimateJob extends BaseModel
{
    public int $id;
    public int $estimate_id;
    public ?int $service_type_id = null;
    public string $title;
    public ?string $notes = null;
    public ?string $reference = null;
    public string $customer_status = 'pending';
    public float $subtotal = 0.0;
    public float $tax = 0.0;
    public float $total = 0.0;
}
