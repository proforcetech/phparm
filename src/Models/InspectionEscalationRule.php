<?php

namespace App\Models;

/**
 * Phase 8.3 of docs/expansion-plan.md — failed-item escalation rule.
 *
 * A rule says: "when an inspection report completes with at least one
 * failed item at severity >= X (and optionally tagged with Y),
 * open an escalation record routed to a specific user OR role, and
 * best-effort dispatch a notification".
 *
 * Severity vocabulary matches the runtime output of
 * InspectionEstimateBridgeService::evaluateItemFailure —
 * low/medium/high/critical in escalation order.
 */
class InspectionEscalationRule extends BaseModel
{
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public const ALLOWED_SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    /** Ordering for >= comparisons in rule matching. */
    public const SEVERITY_ORDER = [
        self::SEVERITY_LOW => 1,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_HIGH => 3,
        self::SEVERITY_CRITICAL => 4,
    ];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const ALLOWED_PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    public const NOTIFY_VIA_EMAIL = 'email';
    public const NOTIFY_VIA_SMS = 'sms';
    public const NOTIFY_VIA_INTERNAL = 'internal';

    public const ALLOWED_NOTIFY_VIA = [
        self::NOTIFY_VIA_EMAIL,
        self::NOTIFY_VIA_SMS,
        self::NOTIFY_VIA_INTERNAL,
    ];

    public const NAME_MAX_LEN = 160;
    public const ROLE_MAX_LEN = 32;
    public const TEMPLATE_MAX_LEN = 120;

    public int $id = 0;
    public ?int $division_id = null;
    public string $name = '';
    public string $trigger_severity = self::SEVERITY_CRITICAL;
    public ?int $compliance_tag_id = null;
    public ?int $assign_to_user_id = null;
    public ?string $assign_to_role = null;
    public ?string $notify_via = null;
    public ?string $notification_template = null;
    public string $priority = self::PRIORITY_NORMAL;
    public bool $require_acknowledgment = true;
    public bool $is_active = true;
    public int $sort_order = 0;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
