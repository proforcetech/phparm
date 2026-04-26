<?php

namespace App\Models;

class User extends BaseModel
{
    public int $id;
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public ?string $password_changed_at = null;
    public bool $must_change_password = false;
    public string $role = '';
    public bool $email_verified = false;
    public ?int $customer_id = null;
    public ?int $branch_id = null;
    public ?string $remember_token = null;
    public bool $active = true;
    public bool $two_factor_enabled = false;
    public string $two_factor_type = 'none';
    public ?string $two_factor_secret = null;
    /**
     * @var array<int, string>|null
     */
    public ?array $two_factor_recovery_codes = null;
    public bool $two_factor_setup_pending = false;
    public ?string $created_at = null;
    public ?string $updated_at = null;
    public ?string $last_activity_at = null;
}
