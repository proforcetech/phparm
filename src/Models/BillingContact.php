<?php

namespace App\Models;

class BillingContact extends BaseModel
{
    public int $id = 0;
    public int $company_id = 0;
    public ?int $user_id = null;
    public string $first_name = '';
    public string $last_name = '';
    public ?string $title = null;
    public ?string $email = null;
    public ?string $phone = null;
    public bool $is_primary = false;
    public bool $is_active = true;
    public ?string $ap_email = null;
    public ?string $ap_phone = null;
    /** @var array<string, mixed>|null */
    public ?array $permission_scope = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
