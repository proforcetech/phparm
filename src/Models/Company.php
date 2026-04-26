<?php

namespace App\Models;

class Company extends BaseModel
{
    public int $id = 0;
    public ?int $division_id = null;
    public string $name = '';
    public ?string $legal_name = null;
    public ?string $external_reference = null;
    public string $company_type = 'commercial';
    public ?string $tax_id = null;
    public bool $tax_exempt = false;
    public string $status = 'active';
    public ?string $primary_phone = null;
    public ?string $primary_email = null;
    public ?string $website = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
