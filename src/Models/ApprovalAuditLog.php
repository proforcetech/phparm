<?php

namespace App\Models;

class ApprovalAuditLog extends BaseModel
{
    public const ENTITY_ESTIMATE = 'estimate';
    public const ENTITY_WORKORDER = 'workorder';
    public const ENTITY_SUB_ESTIMATE = 'sub_estimate';

    public const ACTION_VIEWED = 'viewed';
    public const ACTION_JOB_APPROVED = 'job_approved';
    public const ACTION_JOB_REJECTED = 'job_rejected';
    public const ACTION_FULLY_APPROVED = 'fully_approved';
    public const ACTION_FULLY_REJECTED = 'fully_rejected';
    public const ACTION_SIGNATURE_CAPTURED = 'signature_captured';
    public const ACTION_LINK_GENERATED = 'link_generated';
    public const ACTION_DOCUMENT_SENT = 'document_sent';

    public int $id;
    public string $entity_type;
    public int $entity_id;
    public string $action;
    public ?int $job_id = null;
    public ?string $signer_name = null;
    public ?string $signer_email = null;
    public string $ip_address;
    public ?string $user_agent = null;
    public ?string $device_fingerprint = null;
    public ?string $geo_location = null;
    public ?string $signature_hash = null;
    public ?string $document_hash = null;
    public ?string $comment = null;
    public ?array $metadata = null;
    public ?string $created_at = null;
}
