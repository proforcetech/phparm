<?php

namespace App\Models;

class IntegrationWebhookEvent extends BaseModel
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_PROCESSED,
        self::STATUS_FAILED,
        self::STATUS_DUPLICATE,
    ];

    public ?int $id = null;
    public ?int $integration_id = null;
    public string $provider_key = '';
    public ?string $event_type = null;
    public ?string $external_id = null;
    public string $payload_hash = '';
    public ?string $raw_payload = null;
    public ?array $headers = null;
    public string $status = self::STATUS_RECEIVED;
    public ?string $error_message = null;
    public ?string $received_at = null;
    public ?string $processed_at = null;
}
