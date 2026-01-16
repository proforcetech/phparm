<?php

namespace App\Models;

class InventoryPullRequest extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PULLED = 'pulled';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_PULL = 'pull';
    public const TYPE_ORDER = 'order';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PULLED,
        self::STATUS_ORDERED,
        self::STATUS_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    public const ALLOWED_TYPES = [
        self::TYPE_PULL,
        self::TYPE_ORDER,
    ];

    public int $id;
    public ?int $branch_id = null;
    public int $workorder_id;
    public ?int $workorder_job_id = null;
    public ?int $inventory_item_id = null;
    public ?string $sku = null;
    public string $description;
    public int $quantity_requested = 1;
    public int $quantity_fulfilled = 0;
    public float $unit_cost = 0.0;
    public float $unit_price = 0.0;
    public string $status = self::STATUS_PENDING;
    public string $request_type = self::TYPE_PULL;
    public ?string $notes = null;
    public ?string $vendor = null;
    public ?string $order_reference = null;
    public ?int $requested_by = null;
    public ?string $requested_at = null;
    public ?int $fulfilled_by = null;
    public ?string $fulfilled_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    // Joined data (populated by repository)
    public ?string $workorder_number = null;
    public ?string $job_description = null;
    public ?string $inventory_item_name = null;
    public ?string $requested_by_name = null;
    public ?string $fulfilled_by_name = null;

    /**
     * Check if the request can be fulfilled from inventory
     */
    public function canPullFromStock(): bool
    {
        return $this->request_type === self::TYPE_PULL;
    }

    /**
     * Check if the request needs to be ordered
     */
    public function needsOrdering(): bool
    {
        return $this->request_type === self::TYPE_ORDER;
    }

    /**
     * Get remaining quantity to fulfill
     */
    public function quantityRemaining(): int
    {
        return max(0, $this->quantity_requested - $this->quantity_fulfilled);
    }

    /**
     * Check if fully fulfilled
     */
    public function isFullyFulfilled(): bool
    {
        return $this->quantity_fulfilled >= $this->quantity_requested;
    }

    /**
     * Get total cost
     */
    public function totalCost(): float
    {
        return $this->quantity_requested * $this->unit_cost;
    }

    /**
     * Get total price
     */
    public function totalPrice(): float
    {
        return $this->quantity_requested * $this->unit_price;
    }
}
