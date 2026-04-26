<?php

namespace App\Models;

/**
 * Phase 6.7 of docs/expansion-plan.md — see database/migrations/126_portal_eta_promises.sql.
 */
class PortalEtaPromise extends BaseModel
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_TRAFFIC_API = 'traffic_api';

    public const ALLOWED_SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_SYSTEM,
        self::SOURCE_TRAFFIC_API,
    ];

    public int $id = 0;
    public int $company_id = 0;
    public string $entity_type = '';
    public int $entity_id = 0;
    public string $window_start_at = '';
    public string $window_end_at = '';
    public string $source = self::SOURCE_MANUAL;
    public ?int $confidence = null;
    public ?string $note = null;
    public int $created_by_user_id = 0;
    public ?string $created_at = null;
    public ?string $superseded_at = null;
    public ?int $superseded_by_id = null;
}
