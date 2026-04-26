<?php

namespace App\Models;

class DataRetentionRun extends BaseModel
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_DRY_RUN = 'dry_run';

    public const STATUSES = [
        self::STATUS_RUNNING,
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
        self::STATUS_DRY_RUN,
    ];

    public ?int $id = null;
    public int $policy_id = 0;
    public string $started_at = '';
    public ?string $completed_at = null;
    public string $status = self::STATUS_RUNNING;
    public ?int $records_examined = null;
    public ?int $records_affected = null;
    public bool $dry_run = false;
    public ?string $error_message = null;
    public ?int $triggered_by_user_id = null;
    public ?string $created_at = null;
}
