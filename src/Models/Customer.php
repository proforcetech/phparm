<?php

namespace App\Models;

class Customer extends BaseModel
{
    public int $id;
    public string $first_name;
    public string $last_name;
    public ?string $business_name = null;
    public string $email;
    public string $phone;
    public ?string $street = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $postal_code = null;
    public ?string $country = null;
    public ?string $billing_street = null;
    public ?string $billing_city = null;
    public ?string $billing_state = null;
    public ?string $billing_postal_code = null;
    public ?string $billing_country = null;
    public bool $is_commercial = false;
    public bool $tax_exempt = false;
    public ?string $notes = null;
    public ?string $external_reference = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
