<?php

namespace App\Models;

/**
 * Phase 18 / S5 — per-PO-line quantity received in a single receipt event.
 */
class PurchaseOrderReceiptLine extends BaseModel
{
    public int $id = 0;
    public int $receipt_id = 0;
    public int $purchase_order_line_id = 0;
    public float $quantity_received = 0.0;
    public ?string $notes = null;
}
