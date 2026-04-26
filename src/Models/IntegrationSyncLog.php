<?php

namespace App\Models;

class IntegrationSyncLog extends BaseModel
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
    ];

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_WEBHOOK = 'webhook';

    public const TRIGGERS = [
        self::TRIGGER_MANUAL,
        self::TRIGGER_SCHEDULED,
        self::TRIGGER_WEBHOOK,
    ];

    public const DIRECTION_PULL = 'pull';
    public const DIRECTION_PUSH = 'push';
    public const DIRECTION_BOTH = 'both';

    public ?int $id = null;
    public int $integration_id = 0;
    public string $triggered_by = self::TRIGGER_MANUAL;
    public ?int $user_id = null;
    public string $direction = self::DIRECTION_PULL;
    public string $status = self::STATUS_RUNNING;
    public ?int $records_in = null;
    public ?int $records_out = null;
    public ?int $duration_ms = null;
    public ?string $error_message = null;
    public ?array $summary = null;
    public ?string $started_at = null;
    public ?string $finished_at = null;
}
