<?php

namespace App\Models;

/**
 * Phase 8.1 extends this with compliance metadata — severity drives
 * risk scoring (8.4), compliance_tag_id/compliance_reference wire items
 * to regulatory frameworks, requires_photo/requires_measurement capture
 * inspector obligations, pass_condition is the human-readable criterion
 * the inspector is checking against.
 */
class InspectionItem extends BaseModel
{
    public const SEVERITY_ADVISORY = 'advisory';
    public const SEVERITY_MINOR = 'minor';
    public const SEVERITY_MAJOR = 'major';
    public const SEVERITY_CRITICAL = 'critical';

    public const ALLOWED_SEVERITIES = [
        self::SEVERITY_ADVISORY,
        self::SEVERITY_MINOR,
        self::SEVERITY_MAJOR,
        self::SEVERITY_CRITICAL,
    ];

    public const COMPLIANCE_REFERENCE_MAX_LEN = 120;
    public const MEASUREMENT_UNIT_MAX_LEN = 24;
    public const PASS_CONDITION_MAX_LEN = 240;

    public int $id;
    public int $section_id;
    public string $name;
    public string $input_type;
    public ?string $default_value = null;
    public int $display_order = 0;
    public ?string $severity = null;
    public ?int $compliance_tag_id = null;
    public ?string $compliance_reference = null;
    public bool $requires_photo = false;
    public bool $requires_measurement = false;
    public ?string $measurement_unit = null;
    public ?string $pass_condition = null;
}
