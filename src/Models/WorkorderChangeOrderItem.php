<?php

namespace App\Models;

/**
 * Phase 10.2 — A single line item on a change order.
 *
 * kind drives downstream behaviour:
 *   labor    — added to the WO's labor totals
 *   part     — added to the WO's parts totals (and may trigger inventory
 *              draw-downs on apply, if connected to an inventory item;
 *              tracked separately in the inventory link table when needed)
 *   fee      — flat charge (disposal fee, environmental fee, etc.)
 *   discount — quantity * unit_price_cents is summed with sign honored;
 *              callers should pass a negative unit_price_cents for a discount
 *              so the line_total stays signed without a separate sign column.
 */
class WorkorderChangeOrderItem extends BaseModel
{
    public const KIND_LABOR = 'labor';
    public const KIND_PART = 'part';
    public const KIND_FEE = 'fee';
    public const KIND_DISCOUNT = 'discount';

    public const KINDS = [
        self::KIND_LABOR,
        self::KIND_PART,
        self::KIND_FEE,
        self::KIND_DISCOUNT,
    ];

    public int $id = 0;
    public int $change_order_id = 0;
    public string $kind = self::KIND_LABOR;
    public string $description = '';
    public float $quantity = 1.0;
    public int $unit_price_cents = 0;
    public int $line_total_cents = 0;
    public int $sort_order = 0;
    public ?string $created_at = null;
}
