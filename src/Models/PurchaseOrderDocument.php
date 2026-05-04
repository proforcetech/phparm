<?php

namespace App\Models;

/**
 * Phase 18 / C1 — file uploaded against a purchase order via the vendor
 * portal (or by staff). Common kinds:
 *   tracking      — shipping label / tracking screenshot
 *   packing_slip  — vendor packing slip
 *   invoice       — vendor invoice
 *   other         — uncategorized
 */
class PurchaseOrderDocument extends BaseModel
{
    public const KIND_TRACKING = 'tracking';
    public const KIND_PACKING_SLIP = 'packing_slip';
    public const KIND_INVOICE = 'invoice';
    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_TRACKING,
        self::KIND_PACKING_SLIP,
        self::KIND_INVOICE,
        self::KIND_OTHER,
    ];

    public int $id = 0;
    public int $purchase_order_id = 0;
    public ?int $purchase_order_line_id = null;
    public string $kind = self::KIND_TRACKING;
    public ?string $original_name = null;
    public ?string $stored_path = null;
    public ?string $mime_type = null;
    public ?int $size_bytes = null;
    public ?string $sha256 = null;
    public ?string $tracking_number = null;
    public ?string $carrier = null;
    public ?string $notes = null;
    public ?int $uploaded_via_token_id = null;
    public ?int $uploaded_by_user_id = null;
    public ?string $uploaded_at = null;
    public ?string $deleted_at = null;
}
