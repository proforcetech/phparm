<?php

namespace App\Models;

/**
 * Phase 18 / S5 — one receiving event against a purchase order.
 *
 * A PO with three partial shipments has three receipts. Each receipt has
 * one or more PurchaseOrderReceiptLine rows recording how much of each PO
 * line came in this shipment.
 */
class PurchaseOrderReceipt extends BaseModel
{
    public int $id = 0;
    public int $purchase_order_id = 0;
    public ?string $received_at = null;
    public ?int $received_by_user_id = null;
    public ?string $packing_slip_ref = null;
    public ?string $notes = null;
}
