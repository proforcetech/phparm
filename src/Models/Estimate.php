<?php

namespace App\Models;

class Estimate extends BaseModel
{
    public const TYPE_STANDARD = 'standard';
    public const TYPE_SUB_ESTIMATE = 'sub_estimate';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CONVERTED = 'converted';

    public int $id;
    public ?int $parent_id = null;
    public ?int $parent_estimate_id = null;
    public ?int $workorder_id = null;
    public string $number;
    public int $customer_id;
    public int $vehicle_id;
    public bool $is_mobile = false;
    public string $status;
    public string $estimate_type = self::TYPE_STANDARD;
    public ?int $technician_id = null;
    public ?string $expiration_date = null;
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
    public ?string $rejection_reason = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function isSubEstimate(): bool
    {
        return $this->estimate_type === self::TYPE_SUB_ESTIMATE;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_SENT], true);
    }
}
