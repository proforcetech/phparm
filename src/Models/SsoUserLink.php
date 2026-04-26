<?php

namespace App\Models;

class SsoUserLink extends BaseModel
{
    public ?int $id = null;
    public int $user_id = 0;
    public int $provider_id = 0;
    public string $subject = '';
    public ?string $email = null;
    public ?string $display_name = null;
    public ?string $last_login_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
