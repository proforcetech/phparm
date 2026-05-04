<?php

namespace App\Models;

/**
 * Phase 18 / S5 — single line item on a purchase order.
 *
 * status mirrors the header but per-line:
 *   pending   — no qty received yet
 *   partial   — some qty received, more outstanding
 *   received  — fully received (quantity_received >= quantity_ordered)
 *   cancelled — explicitly cancelled (header cancellation does NOT
 *               cascade to line.status; the join knows from header.status)
 */
class PurchaseOrderLine extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    public int $id = 0;
    public int $purchase_order_id = 0;
    public int $line_number = 0;
    public string $description = '';
    public ?string $sku = null;
    public ?int $inventory_item_id = null;
    public ?int $site_asset_id = null;
    public float $quantity_ordered = 0.0;
    public float $quantity_received = 0.0;
    public ?string $vendor_shipped_at = null;
    public ?string $vendor_tracking_number = null;
    public ?string $vendor_carrier = null;
    public int $unit_cost_cents = 0;
    public int $tax_cents = 0;
    public int $line_total_cents = 0;
    public ?string $notes = null;
    public string $status = self::STATUS_PENDING;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
