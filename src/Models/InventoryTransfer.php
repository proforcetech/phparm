<?php

namespace App\Models;

class InventoryTransfer extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public int $id;
    public string $status = self::STATUS_PENDING;
    public ?string $source_location = null;
    public ?string $destination_location = null;
    public ?string $notes = null;
    public int $requested_by;
    public ?string $requested_at = null;
    public ?int $approved_by = null;
    public ?string $approved_at = null;
    public ?int $rejected_by = null;
    public ?string $rejected_at = null;
    public ?int $cancelled_by = null;
    public ?string $cancelled_at = null;
    public ?int $completed_by = null;
    public ?string $completed_at = null;

    /** @var InventoryTransferItem[] */
    public array $items = [];

    public ?string $requested_by_name = null;
    public ?string $approved_by_name = null;
    public ?string $rejected_by_name = null;
    public ?string $cancelled_by_name = null;
    public ?string $completed_by_name = null;
    public ?int $total_quantity_requested = null;
    public ?int $total_quantity_transferred = null;
}
