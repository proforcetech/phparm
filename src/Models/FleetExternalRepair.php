<?php

namespace App\Models;

/**
 * Phase 7.5 of docs/expansion-plan.md — see
 * database/migrations/132_fleet_external_repairs.sql.
 *
 * Carries cost breakdown matching the internal workorder_items split
 * (labor / parts) plus an other_cost bucket for line items that don't
 * fit either (towing, disposal fees, shop supplies) — so fleet cost
 * reports can include external vendor spend on equal footing with
 * internal workorder cost.
 */
class FleetExternalRepair extends BaseModel
{
    public const CATEGORY_REPAIR = 'repair';
    public const CATEGORY_MAINTENANCE = 'maintenance';
    public const CATEGORY_TIRES = 'tires';
    public const CATEGORY_TOWING = 'towing';
    public const CATEGORY_PAINT = 'paint';
    public const CATEGORY_WARRANTY = 'warranty';
    public const CATEGORY_INSPECTION = 'inspection';
    public const CATEGORY_OTHER = 'other';

    public const ALLOWED_CATEGORIES = [
        self::CATEGORY_REPAIR,
        self::CATEGORY_MAINTENANCE,
        self::CATEGORY_TIRES,
        self::CATEGORY_TOWING,
        self::CATEGORY_PAINT,
        self::CATEGORY_WARRANTY,
        self::CATEGORY_INSPECTION,
        self::CATEGORY_OTHER,
    ];

    public const VENDOR_NAME_MAX_LEN = 120;
    public const VENDOR_INVOICE_MAX_LEN = 80;
    public const DESCRIPTION_MAX_LEN = 500;
    public const NOTES_MAX_LEN = 2000;
    public const ATTACHMENT_PATH_MAX_LEN = 255;

    public int $id = 0;
    public int $fleet_unit_id = 0;
    public string $vendor_name = '';
    public ?string $vendor_invoice_number = null;
    public string $category = self::CATEGORY_REPAIR;
    public string $service_date = '';
    public string $description = '';
    public float $labor_cost = 0.0;
    public float $parts_cost = 0.0;
    public float $other_cost = 0.0;
    public float $total_cost = 0.0;
    public ?int $odometer_at_service = null;
    public ?float $engine_hours_at_service = null;
    public ?string $notes = null;
    public ?string $attachment_path = null;
    public int $created_by_user_id = 0;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
