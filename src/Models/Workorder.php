<?php

namespace App\Models;

class Workorder extends BaseModel
{
    // Core statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    // Extended statuses for workflow automation
    public const STATUS_PARTS_PENDING = 'parts_pending';
    public const STATUS_AWAITING_AUTHORIZATION = 'awaiting_authorization';
    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    public const STATUS_QC_REQUIRED = 'qc_required';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_ON_HOLD,
        self::STATUS_PARTS_PENDING,
        self::STATUS_AWAITING_AUTHORIZATION,
        self::STATUS_QC_REQUIRED,
        self::STATUS_COMPLETED,
        self::STATUS_READY_FOR_PICKUP,
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
        $editableStatuses = [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_ON_HOLD,
            self::STATUS_PARTS_PENDING,
            self::STATUS_AWAITING_AUTHORIZATION,
            self::STATUS_QC_REQUIRED,
        ];

        return in_array($this->status, $editableStatuses, true);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $transitions = [
            // From pending: can start work or cancel
            self::STATUS_PENDING => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_CANCELLED,
            ],
            // From in_progress: can pause, need parts, need authorization, require QC, complete, or cancel
            self::STATUS_IN_PROGRESS => [
                self::STATUS_ON_HOLD,
                self::STATUS_PARTS_PENDING,
                self::STATUS_AWAITING_AUTHORIZATION,
                self::STATUS_QC_REQUIRED,
                self::STATUS_COMPLETED,
                self::STATUS_CANCELLED,
            ],
            // From on_hold: can resume or cancel
            self::STATUS_ON_HOLD => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_CANCELLED,
            ],
            // From parts_pending: can resume when parts arrive or cancel
            self::STATUS_PARTS_PENDING => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_ON_HOLD,
                self::STATUS_CANCELLED,
            ],
            // From awaiting_authorization: can resume when authorized, hold, or cancel
            self::STATUS_AWAITING_AUTHORIZATION => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_ON_HOLD,
                self::STATUS_CANCELLED,
            ],
            // From qc_required: can pass QC to complete, fail back to in_progress, or cancel
            self::STATUS_QC_REQUIRED => [
                self::STATUS_COMPLETED,
                self::STATUS_IN_PROGRESS, // QC failed, needs rework
                self::STATUS_CANCELLED,
            ],
            // From completed: can mark ready for pickup
            self::STATUS_COMPLETED => [
                self::STATUS_READY_FOR_PICKUP,
            ],
            // From ready_for_pickup: terminal state (vehicle picked up)
            self::STATUS_READY_FOR_PICKUP => [],
            // Cancelled is terminal
            self::STATUS_CANCELLED => [],
        ];

        return in_array($newStatus, $transitions[$this->status] ?? [], true);
    }
}
