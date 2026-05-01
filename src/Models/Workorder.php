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
    public const STATUS_GOA = 'goa';

    // Extended statuses for workflow automation
    public const STATUS_PARTS_PENDING = 'parts_pending';
    public const STATUS_AWAITING_AUTHORIZATION = 'awaiting_authorization';
    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    public const STATUS_QC_REQUIRED = 'qc_required';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    // Trade-aware WO type vocabulary — Phase 11 of docs/woms-expansion-plan.md.
    // Validated in PHP only (no DB CHECK constraint) so adding a new type
    // doesn't require a schema migration. Default is 'corrective' both here
    // and in the schema (migration 152), preserving legacy semantics.
    public const TYPE_CORRECTIVE = 'corrective';
    public const TYPE_PREVENTIVE = 'preventive';
    public const TYPE_INSPECTION = 'inspection';
    public const TYPE_INSTALL = 'install';
    public const TYPE_PROJECT = 'project';
    public const TYPE_RECURRING_VISIT = 'recurring_visit';
    public const TYPE_CHANGE_REQUEST = 'change_request';

    public const ALLOWED_TYPES = [
        self::TYPE_CORRECTIVE,
        self::TYPE_PREVENTIVE,
        self::TYPE_INSPECTION,
        self::TYPE_INSTALL,
        self::TYPE_PROJECT,
        self::TYPE_RECURRING_VISIT,
        self::TYPE_CHANGE_REQUEST,
    ];

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
        self::STATUS_GOA,
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
    // Subject FKs — exactly one is populated based on service_line_id; the
    // others are NULL. SubjectResolver enforces the per-line "must have a
    // subject" rule. Auto-repair lines require vehicle_id; property lines
    // require site_asset_id; future verticals add their own column here.
    public ?int $vehicle_id = null;
    public ?int $site_asset_id = null;
    public ?int $fleet_unit_id = null;
    public ?int $branch_id = null;
    public ?int $service_line_id = null;
    public string $status = self::STATUS_PENDING;
    public string $type = self::TYPE_CORRECTIVE;
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
    public float $goa_fee = 0.0;
    public ?string $goa_billing_party = null;
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
                self::STATUS_GOA,
            ],
            // From in_progress: can pause, need parts, need authorization, require QC, complete, or cancel
            self::STATUS_IN_PROGRESS => [
                self::STATUS_ON_HOLD,
                self::STATUS_PARTS_PENDING,
                self::STATUS_AWAITING_AUTHORIZATION,
                self::STATUS_QC_REQUIRED,
                self::STATUS_COMPLETED,
                self::STATUS_CANCELLED,
                self::STATUS_GOA,
            ],
            // From on_hold: can resume or cancel
            self::STATUS_ON_HOLD => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_CANCELLED,
                self::STATUS_GOA,
            ],
            // From parts_pending: can resume when parts arrive or cancel
            self::STATUS_PARTS_PENDING => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_ON_HOLD,
                self::STATUS_CANCELLED,
                self::STATUS_GOA,
            ],
            // From awaiting_authorization: can resume when authorized, hold, or cancel
            self::STATUS_AWAITING_AUTHORIZATION => [
                self::STATUS_IN_PROGRESS,
                self::STATUS_ON_HOLD,
                self::STATUS_CANCELLED,
                self::STATUS_GOA,
            ],
            // From qc_required: can pass QC to complete, fail back to in_progress, or cancel
            self::STATUS_QC_REQUIRED => [
                self::STATUS_COMPLETED,
                self::STATUS_IN_PROGRESS, // QC failed, needs rework
                self::STATUS_CANCELLED,
                self::STATUS_GOA,
            ],
            // From completed: can mark ready for pickup
            self::STATUS_COMPLETED => [
                self::STATUS_READY_FOR_PICKUP,
            ],
            // From ready_for_pickup: terminal state (vehicle picked up)
            self::STATUS_READY_FOR_PICKUP => [],
            // Cancelled is terminal
            self::STATUS_CANCELLED => [],
            self::STATUS_GOA => [],
        ];

        return in_array($newStatus, $transitions[$this->status] ?? [], true);
    }

    /**
     * Single chokepoint for validating a workorder type string. The DB column
     * is VARCHAR with no CHECK constraint, so callers writing the column must
     * gate input through this method to keep `ALLOWED_TYPES` authoritative.
     */
    public static function isValidType(string $type): bool
    {
        return in_array($type, self::ALLOWED_TYPES, true);
    }
}
