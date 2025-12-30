<?php

namespace App\Models;

class Workorder extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_ON_HOLD,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const ALLOWED_PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    public int $id;
    public string $number;
    public int $estimate_id;
    public int $customer_id;
    public int $vehicle_id;
    public string $status = self::STATUS_PENDING;
    public string $priority = self::PRIORITY_NORMAL;
    public ?int $assigned_technician_id = null;
    public ?string $started_at = null;
    public ?string $completed_at = null;
    public ?string $estimated_completion = null;
    public float $subtotal = 0.0;
    public float $tax = 0.0;
    public float $call_out_fee = 0.0;
    public float $mileage_total = 0.0;
    public float $discounts = 0.0;
    public float $shop_fee = 0.0;
    public float $hazmat_disposal_fee = 0.0;
    public float $grand_total = 0.0;
    public ?string $internal_notes = null;
    public ?string $customer_notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_ON_HOLD], true);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $transitions = [
            self::STATUS_PENDING => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
            self::STATUS_IN_PROGRESS => [self::STATUS_ON_HOLD, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_ON_HOLD => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
            self::STATUS_COMPLETED => [], // Terminal state for workorder (leads to invoice)
            self::STATUS_CANCELLED => [], // Terminal state
        ];

        return in_array($newStatus, $transitions[$this->status] ?? [], true);
    }
}
