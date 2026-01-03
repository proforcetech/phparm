<?php

namespace App\Models;

class EstimatePublicLink extends BaseModel
{
    public int $id;
    public int $estimate_id;
    public string $token_hash;
    public string $short_code;
    public ?string $expires_at = null;
    public ?string $last_accessed_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
