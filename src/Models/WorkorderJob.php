<?php

namespace App\Models;

class WorkorderJob extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
    ];

    public int $id;
    public int $workorder_id;
    public int $estimate_job_id;
    public ?int $service_type_id = null;
    public string $title;
    public ?string $notes = null;
    public ?string $reference = null;
    public string $status = self::STATUS_PENDING;
    public ?int $assigned_technician_id = null;
    public ?string $started_at = null;
    public ?string $completed_at = null;
    public float $subtotal = 0.0;
    public float $tax = 0.0;
    public float $total = 0.0;
    public int $position = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
