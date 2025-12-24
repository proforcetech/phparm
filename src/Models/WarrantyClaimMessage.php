<?php

namespace App\Models;

class WarrantyClaimMessage extends BaseModel
{
    public int $id;
    public int $claim_id;
    public string $actor_type;
    public int $actor_id;
    public string $message;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
