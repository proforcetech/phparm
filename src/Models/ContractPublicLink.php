<?php

namespace App\Models;

class ContractPublicLink extends BaseModel
{
    public int $id;
    public int $contract_id;
    public string $token_hash;
    public string $short_code;
    public ?string $expires_at = null;
    public ?string $last_accessed_at = null;
    public ?string $revoked_at = null;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
