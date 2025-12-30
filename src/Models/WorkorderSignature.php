<?php

namespace App\Models;

class WorkorderSignature extends BaseModel
{
    public const TYPE_COMPLETION = 'completion';
    public const TYPE_AUTHORIZATION = 'authorization';
    public const TYPE_ADDITIONAL_WORK = 'additional_work';

    public int $id;
    public int $workorder_id;
    public string $signature_type = self::TYPE_COMPLETION;
    public string $signer_name;
    public ?string $signer_email = null;
    public string $signature_data;
    public ?string $ip_address = null;
    public ?string $user_agent = null;
    public ?string $device_fingerprint = null;
    public ?string $document_hash = null;
    public bool $legal_consent = false;
    public ?string $consent_text = null;
    public ?string $comment = null;
    public ?string $signed_at = null;
    public ?string $created_at = null;
}
