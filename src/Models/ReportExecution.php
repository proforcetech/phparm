<?php

namespace App\Models;

class ReportExecution extends BaseModel
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED];

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_API = 'api';

    public const TRIGGERS = [self::TRIGGER_MANUAL, self::TRIGGER_SCHEDULED, self::TRIGGER_API];

    public ?int $id = null;
    public string $report_key = '';
    public ?int $saved_report_id = null;
    public ?int $scheduled_report_id = null;
    public string $triggered_by = self::TRIGGER_MANUAL;
    public ?int $user_id = null;
    public ?array $parameters = null;
    public string $status = self::STATUS_RUNNING;
    public ?int $row_count = null;
    public ?int $duration_ms = null;
    public ?string $error_message = null;
    public ?string $started_at = null;
    public ?string $finished_at = null;
}
