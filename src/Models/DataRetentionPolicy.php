<?php

namespace App\Models;

class DataRetentionPolicy extends BaseModel
{
    public const ACTION_DELETE = 'delete';
    public const ACTION_ARCHIVE = 'archive';

    public const ACTIONS = [
        self::ACTION_DELETE,
        self::ACTION_ARCHIVE,
    ];

    public const RUN_STATUS_SUCCESS = 'success';
    public const RUN_STATUS_FAILED = 'failed';
    public const RUN_STATUS_SKIPPED = 'skipped';
    public const RUN_STATUS_DRY_RUN = 'dry_run';

    public const RUN_STATUSES = [
        self::RUN_STATUS_SUCCESS,
        self::RUN_STATUS_FAILED,
        self::RUN_STATUS_SKIPPED,
        self::RUN_STATUS_DRY_RUN,
    ];

    public ?int $id = null;
    public string $entity_type = '';
    public string $table_name = '';
    public string $timestamp_column = 'created_at';
    public int $retention_days = 90;
    public string $action = self::ACTION_DELETE;
    public ?string $archive_table_name = null;
    public bool $is_active = true;
    public ?string $last_run_at = null;
    public ?string $last_run_status = null;
    public ?int $last_run_records = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
