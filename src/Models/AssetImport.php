<?php

namespace App\Models;

/**
 * Phase 18 / S12 — header row for a bulk asset CSV import job.
 *
 * Status flow: pending → validated → applied (or cancelled / failed at any
 * step). The mapping JSON pins how csv columns translate to site_assets
 * fields so the apply step writes EXACTLY what the operator reviewed in
 * the dry-run.
 */
class AssetImport extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_APPLYING = 'applying';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VALIDATED,
        self::STATUS_APPLYING,
        self::STATUS_APPLIED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public int $id = 0;
    public string $status = self::STATUS_PENDING;
    public ?string $original_filename = null;
    public ?array $mapping = null;
    public ?int $default_site_id = null;
    public ?int $default_division_id = null;
    public ?int $default_asset_type_id = null;
    public int $total_rows = 0;
    public int $valid_rows = 0;
    public int $error_rows = 0;
    public int $created_rows = 0;
    public ?int $started_by_user_id = null;
    public ?string $started_at = null;
    public ?string $validated_at = null;
    public ?string $applied_at = null;
    public ?string $notes = null;
}
