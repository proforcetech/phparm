<?php

namespace App\Models;

class Appointment extends BaseModel
{
    public int $id;
    public int $customer_id;
    public int $vehicle_id;
    public ?int $estimate_id = null;
    public ?int $technician_id = null;
    public string $status;
    public string $start_time;
    public string $end_time;
    public ?string $notes = null;
}
