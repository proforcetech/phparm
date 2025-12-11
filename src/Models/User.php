<?php

namespace App\Models;

class User extends BaseModel
{
    public int $id;
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $role = '';
    public bool $email_verified = false;
    public ?int $customer_id = null;
    public ?string $remember_token = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
