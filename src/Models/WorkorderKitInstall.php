<?php

namespace App\Models;

class WorkorderKitInstall extends BaseModel
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_INSTALLED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_PLANNED => [self::STATUS_INSTALLED, self::STATUS_CANCELLED],
        self::STATUS_INSTALLED => [self::STATUS_CANCELLED],
        self::STATUS_CANCELLED => [],
    ];

    public ?int $id = null;
    public int $workorder_id = 0;
    public ?int $workorder_job_id = null;
    public ?int $bundle_id = null;
    public string $bundle_name_snapshot = '';
    public ?int $installed_by_user_id = null;
    public string $status = self::STATUS_PLANNED;
    public ?string $planned_at = null;
    public ?string $installed_at = null;
    public ?string $cancelled_at = null;
    public ?string $cancellation_reason = null;
    public ?string $notes = null;
    public int $total_parts_consumed = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
