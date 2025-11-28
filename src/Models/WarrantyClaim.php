<?php

namespace App\Models;

class WarrantyClaim extends BaseModel
{
    public int $id;
    public int $customer_id;
    public ?int $invoice_id = null;
    public ?int $vehicle_id = null;
    public string $subject;
    public string $description;
    public string $status;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
