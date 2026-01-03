<?php

namespace App\Models;

class EstimateSignature extends BaseModel
{
    public int $id;
    public int $estimate_id;
    public string $signer_name;
    public ?string $signer_email = null;
    public string $signature_data;
    public ?string $comment = null;
    public ?string $signed_at = null;
    public ?string $created_at = null;
}
