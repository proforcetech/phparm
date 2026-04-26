<?php

namespace App\Models;

/**
 * Phase 8.1 of docs/expansion-plan.md — compliance tag vocabulary for
 * inspection templates.
 *
 * A tag identifies a regulatory framework or customer-required
 * inspection regime (DOT annual, OSHA lockout/tagout, state safety
 * inspection, insurance audit, etc.). Tags are either global
 * (division_id NULL — applies to every service line) or scoped to a
 * specific division (division_id set — only visible to that service
 * line's template picker).
 */
class InspectionComplianceTag extends BaseModel
{
    public const REG_BODY_DOT = 'dot';
    public const REG_BODY_OSHA = 'osha';
    public const REG_BODY_EPA = 'epa';
    public const REG_BODY_FEDERAL = 'federal';
    public const REG_BODY_STATE = 'state';
    public const REG_BODY_INSURANCE = 'insurance';
    public const REG_BODY_INTERNAL = 'internal';
    public const REG_BODY_OTHER = 'other';

    public const ALLOWED_REGULATORY_BODIES = [
        self::REG_BODY_DOT,
        self::REG_BODY_OSHA,
        self::REG_BODY_EPA,
        self::REG_BODY_FEDERAL,
        self::REG_BODY_STATE,
        self::REG_BODY_INSURANCE,
        self::REG_BODY_INTERNAL,
        self::REG_BODY_OTHER,
    ];

    public const CODE_MAX_LEN = 64;
    public const LABEL_MAX_LEN = 160;
    public const DESCRIPTION_MAX_LEN = 2000;

    public int $id = 0;
    public string $code = '';
    public string $label = '';
    public ?string $description = null;
    public string $regulatory_body = self::REG_BODY_OTHER;
    public ?int $division_id = null;
    public bool $is_active = true;
    public int $sort_order = 0;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
