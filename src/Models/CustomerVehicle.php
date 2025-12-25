<?php

namespace App\Models;

class CustomerVehicle extends BaseModel
{
    public int $id;
    public int $customer_id;
    public ?int $vehicle_master_id = null;
    public int $year;
    public string $make;
    public string $model;
    public string $engine;
    public string $transmission;
    public string $drive;
    public ?string $trim = null;
    public ?string $vin = null;
    public ?string $license_plate = null;
    public ?string $notes = null;
    public ?int $mileage_in = null;
    public ?int $mileage_out = null;
    public int $is_active = 1;
    public ?string $last_service_date = null;
    public ?int $last_service_mileage = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
