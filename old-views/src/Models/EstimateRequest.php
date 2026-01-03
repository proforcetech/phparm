<?php

namespace App\Models;

class EstimateRequest extends BaseModel
{
    public int $id;

    // Contact Information
    public string $name = '';
    public string $email = '';
    public string $phone = '';

    // Customer Address
    public string $address = '';
    public string $city = '';
    public string $state = '';
    public string $zip = '';

    // Service Address
    public bool $service_address_same_as_customer = true;
    public ?string $service_address = null;
    public ?string $service_city = null;
    public ?string $service_state = null;
    public ?string $service_zip = null;

    // Vehicle Information
    public ?int $vehicle_year = null;
    public ?string $vehicle_make = null;
    public ?string $vehicle_model = null;
    public ?string $vin = null;
    public ?string $license_plate = null;

    // Service Request
    public ?int $service_type_id = null;
    public ?string $service_type_name = null;
    public ?string $description = null;

    // Status and Processing
    public string $status = 'pending';
    public ?int $estimate_id = null;
    public ?int $customer_id = null;
    public ?int $vehicle_id = null;

    // Metadata
    public string $source = 'website';
    public ?string $ip_address = null;
    public ?string $user_agent = null;

    // Staff Notes
    public ?string $internal_notes = null;
    public ?string $contacted_at = null;
    public ?int $contacted_by = null;

    public ?string $created_at = null;
    public ?string $updated_at = null;
}
