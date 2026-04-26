<?php

namespace App\Models;

class SiteContact extends BaseModel
{
    public int $id = 0;
    public int $site_id = 0;
    public ?int $user_id = null;
    public string $first_name = '';
    public string $last_name = '';
    public ?string $title = null;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $mobile_phone = null;
    public ?string $role = null;
    public bool $is_primary = false;
    public bool $is_active = true;
    /** @var array<string, mixed>|null */
    public ?array $permission_scope = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
