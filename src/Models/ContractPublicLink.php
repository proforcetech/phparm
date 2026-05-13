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
    public ?string $consumed_at = null;
    public ?int $consumed_by_signature_id = null;
    public ?string $signer_email = null;
    public ?int $signer_invitation_id = null;
    public ?string $document_hash_at_issue = null;
    public ?string $document_snapshot_json = null;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
