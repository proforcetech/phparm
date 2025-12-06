<?php

namespace App\Models;

class Appointment extends BaseModel
{
    public int $id;
    public ?int $customer_id = null;
    public ?int $vehicle_id = null;
    public ?int $estimate_id = null;
    public ?int $technician_id = null;
    public string $status;
    public string $start_time;
    public string $end_time;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
