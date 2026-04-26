<?php

namespace App\Models;

class Site extends BaseModel
{
    public int $id = 0;
    public int $company_id = 0;
    public ?int $legacy_customer_id = null;
    public ?int $division_id = null;
    public string $name = '';
    public ?string $code = null;
    public bool $is_primary = false;
    public string $status = 'active';
    public ?string $street = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $postal_code = null;
    public ?string $country = null;
    public ?float $latitude = null;
    public ?float $longitude = null;
    public ?string $timezone = null;
    public ?string $phone = null;
    public ?string $access_instructions = null;
    /** @var array<string, mixed>|null */
    public ?array $hours_json = null;
    // Ciphertext stays opaque on the model; only the service decrypts it when
    // the caller has the crm.sites.codes.view permission.
    public ?string $alarm_code_encrypted = null;
    public ?string $gate_code_encrypted = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
