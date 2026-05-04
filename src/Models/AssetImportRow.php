<?php

namespace App\Models;

/**
 * Phase 18 / S12 — single CSV row inside an asset import job.
 *
 * status:
 *   pending   — uploaded, not yet validated against current mapping
 *   validated — passes mapping + DB checks, parsed_data is the apply payload
 *   invalid   — mapping or DB lookup failed; error_message tells the operator
 *   created   — INSERT succeeded; created_asset_id points at site_assets row
 *   skipped   — caller chose not to apply (e.g. operator deselected this row)
 */
class AssetImportRow extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALIDATED = 'validated';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_CREATED = 'created';
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_VALIDATED,
        self::STATUS_INVALID,
        self::STATUS_CREATED,
        self::STATUS_SKIPPED,
    ];

    public int $id = 0;
    public int $import_id = 0;
    public int $row_number = 0;
    public ?array $raw_data = null;
    public ?array $parsed_data = null;
    public string $status = self::STATUS_PENDING;
    public ?string $error_message = null;
    public ?int $created_asset_id = null;
}
