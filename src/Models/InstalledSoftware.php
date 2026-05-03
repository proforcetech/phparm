<?php

namespace App\Models;

/**
 * site_asset ↔ software_asset join — Phase 14 / M9 of
 * docs/woms-expansion-plan.md.
 *
 * Answers "what's installed on this machine" and "where is this software
 * running" without scanning license_assignments. license_assignment_id is
 * optional — installed copies may be on trial, illicit, or pending license
 * assignment, which is exactly what the over-allocation compliance view
 * has to surface.
 *
 * See migration 163_software_cmdb.sql.
 */
class InstalledSoftware extends BaseModel
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AGENT = 'agent';
    public const SOURCE_RMM = 'rmm';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_API = 'api';

    public const ALLOWED_SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_AGENT,
        self::SOURCE_RMM,
        self::SOURCE_IMPORT,
        self::SOURCE_API,
    ];

    public int $id = 0;
    public int $site_asset_id = 0;
    public int $software_asset_id = 0;
    public ?string $installed_version = null;
    public ?string $installed_at = null;
    public ?string $detected_at = null;
    public string $source = self::SOURCE_MANUAL;
    public ?int $license_assignment_id = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
