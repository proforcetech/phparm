<?php

namespace App\Models;

class ScheduledReport extends BaseModel
{
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RUNNING = 'running';

    public const FORMAT_CSV = 'csv';
    public const FORMAT_JSON = 'json';

    public const FORMATS = [self::FORMAT_CSV, self::FORMAT_JSON];

    public ?int $id = null;
    public int $saved_report_id = 0;
    public string $name = '';
    public string $cron_expression = '';
    public string $timezone = 'UTC';
    public string $output_format = self::FORMAT_CSV;
    public string $recipients = '';
    public bool $is_active = true;
    public ?string $last_run_at = null;
    public ?string $next_run_at = null;
    public ?string $last_status = null;
    public ?string $last_error = null;
    public ?int $created_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
