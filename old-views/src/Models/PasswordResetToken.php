<?php

namespace App\Models;

class PasswordResetToken extends BaseModel
{
    public int $id;
    public string $email;
    public string $token;
    public string $expires_at;
    public ?string $used_at = null;
    public ?string $created_at = null;
}
