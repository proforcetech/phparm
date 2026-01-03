<?php

namespace App\Models;

class EmailVerificationToken extends BaseModel
{
    public int $id;
    public int $user_id;
    public string $token;
    public string $expires_at;
    public ?string $used_at = null;
    public ?string $created_at = null;
}
