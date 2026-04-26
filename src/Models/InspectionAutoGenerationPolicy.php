<?php

namespace App\Models;

/**
 * Phase 8.2 of docs/expansion-plan.md — deficiency → auto-WO/quote
 * generation rule.
 *
 * A policy says: "when an inspection report completes with at least N
 * failed items matching severity >= X (and optionally tagged with Y),
 * auto-generate Z on behalf of the user". Z is either a new estimate
 * or a sub-estimate attached to the report's already-open workorder.
 *
 * Severity strings align with the runtime output of
 * InspectionEstimateBridgeService::evaluateItemFailure — 'low',
 * 'medium', 'high', 'critical' (in that escalation order).
 */
class InspectionAutoGenerationPolicy extends BaseModel
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

    /** Ordering for >= comparisons in policy matching. */
    public const SEVERITY_ORDER = [
        self::SEVERITY_LOW => 1,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_HIGH => 3,
        self::SEVERITY_CRITICAL => 4,
    ];

    /** Create a brand-new standalone estimate. */
    public const TARGET_ESTIMATE = 'estimate';

    /** Attach as a sub-estimate to the workorder linked to the report. */
    public const TARGET_SUBESTIMATE_ON_WORKORDER = 'subestimate_on_workorder';

    /**
     * Sub-estimate when the report is linked to an active workorder,
     * otherwise fall back to a new standalone estimate. The usual
     * default — covers the "inspection during active service" case
     * (shop finds a second failure mid-job) and the "standalone
     * inspection appointment" case from the same policy.
     */
    public const TARGET_AUTO = 'auto';

    public const ALLOWED_TARGET_KINDS = [
        self::TARGET_ESTIMATE,
        self::TARGET_SUBESTIMATE_ON_WORKORDER,
        self::TARGET_AUTO,
    ];

    public const NAME_MAX_LEN = 160;

    public int $id = 0;
    public ?int $division_id = null;
    public string $name = '';
    public string $trigger_severity = self::SEVERITY_HIGH;
    public ?int $compliance_tag_id = null;
    public string $target_kind = self::TARGET_AUTO;
    public int $min_failed_items = 1;
    public bool $require_customer_approval = true;
    public ?int $auto_assign_to_technician_id = null;
    public bool $is_active = true;
    public int $sort_order = 0;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
