<?php

namespace App\Models;

class ContractSigner extends BaseModel
{
    public int $id;
    public int $contract_id;
    public string $email;
    public string $name;
    public ?string $title = null;
    public int $display_order = 0;
    public ?string $invited_at = null;
    public ?int $invited_by_user_id = null;
    public ?string $revoked_at = null;
    public ?int $signed_signature_id = null;
    public ?string $signed_at = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * R-02c — derived state for UI/API consumers.
     */
    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }
        if ($this->signed_at !== null) {
            return 'signed';
        }
        return 'invited';
    }

    public function toArray(): array
    {
        $out = parent::toArray();
        $out['status'] = $this->status();
        return $out;
    }
}
