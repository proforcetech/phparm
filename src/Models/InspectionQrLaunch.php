<?php

namespace App\Models;

/**
 * Phase 8.5 of docs/expansion-plan.md — QR launch audit row.
 *
 * Each scan that lands on the inspection-launch endpoint persists one
 * of these regardless of whether a report is started. The status
 * progresses preview -> started, or jumps straight to failed/aborted
 * when the launch can't proceed (token didn't resolve, gate denied,
 * completion-service threw).
 */
class InspectionQrLaunch extends BaseModel
{
    public const STATUS_PREVIEW = 'preview';
    public const STATUS_STARTED = 'started';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_FAILED = 'failed';

    public const ALLOWED_STATUSES = [
        self::STATUS_PREVIEW,
        self::STATUS_STARTED,
        self::STATUS_ABORTED,
        self::STATUS_FAILED,
    ];

    public const SOURCE_QR = 'qr';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_API = 'api';

    public const ALLOWED_SOURCES = [
        self::SOURCE_QR,
        self::SOURCE_MANUAL,
        self::SOURCE_API,
    ];

    public const TOKEN_PATTERN = '/^[a-f0-9]{16,96}$/';
    public const NOTES_MAX_LEN = 500;

    public int $id = 0;
    public string $qr_token = '';
    public ?int $site_asset_id = null;
    public ?int $inspection_report_id = null;
    public ?int $inspection_template_id = null;
    public ?int $launched_by_user_id = null;
    public string $source = self::SOURCE_QR;
    public string $status = self::STATUS_PREVIEW;
    public ?string $client_meta = null;
    public ?string $notes = null;
    public ?string $created_at = null;
}
