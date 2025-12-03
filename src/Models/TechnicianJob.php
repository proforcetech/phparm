<?php

namespace App\Models;

class TechnicianJob extends BaseModel
{
    public int $id;
    public string $title;
    public string $estimate_number;
    public string $customer_name;
    public ?string $vehicle_vin = null;
    public string $customer_status;
}
