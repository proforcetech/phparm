<?php

namespace App\Models;

/**
 * Phase 8.4 of docs/expansion-plan.md — inspection risk score snapshot.
 *
 * One row per inspection report. Service layer computes the score from
 * the bridge service's identifyFailedItems output using the runtime
 * severity vocabulary (low/medium/high/critical) emitted by
 * evaluateItemFailure. Compliance-tagged items receive a multiplier on
 * top of their base weight, since a failure against a regulated item
 * (DOT brake wear, OSHA PPE check, EPA hazmat inspection) is a
 * materially bigger risk exposure than an advisory cosmetic ding.
 *
 * risk_level buckets compress the raw score into a qualitative label
 * the UI + trend views can color-code and sort on without each caller
 * rederiving the same thresholds.
 */
class InspectionRiskScore extends BaseModel
{
    public const RISK_LEVEL_LOW = 'low';
    public const RISK_LEVEL_MODERATE = 'moderate';
    public const RISK_LEVEL_ELEVATED = 'elevated';
    public const RISK_LEVEL_HIGH = 'high';
    public const RISK_LEVEL_CRITICAL = 'critical';

    public const ALLOWED_RISK_LEVELS = [
        self::RISK_LEVEL_LOW,
        self::RISK_LEVEL_MODERATE,
        self::RISK_LEVEL_ELEVATED,
        self::RISK_LEVEL_HIGH,
        self::RISK_LEVEL_CRITICAL,
    ];

    /**
     * Per-severity base weights applied to the runtime severity output
     * of InspectionEstimateBridgeService::evaluateItemFailure.
     */
    public const SEVERITY_WEIGHTS = [
        'low' => 1.0,
        'medium' => 3.0,
        'high' => 6.0,
        'critical' => 10.0,
    ];

    /**
     * Multiplier applied when the failed item carries a compliance tag.
     * Regulated findings weigh more than purely advisory findings at
     * the same severity.
     */
    public const COMPLIANCE_TAG_MULTIPLIER = 1.5;

    /**
     * Score thresholds keyed by level. Service uses these to bucket the
     * computed total_score into a qualitative level for the UI.
     */
    public const LEVEL_THRESHOLDS = [
        self::RISK_LEVEL_LOW => 0.0,        // 0 exactly = clean report
        self::RISK_LEVEL_MODERATE => 0.01,  // anything failing but small
        self::RISK_LEVEL_ELEVATED => 10.0,  // a few medium / single high
        self::RISK_LEVEL_HIGH => 25.0,      // multiple high or a critical
        self::RISK_LEVEL_CRITICAL => 60.0,  // cluster of criticals / DOT-stop-the-truck territory
    ];

    public int $id = 0;
    public int $inspection_report_id = 0;
    public ?int $vehicle_id = null;
    public ?int $customer_id = null;
    public ?int $division_id = null;
    public float $total_score = 0.0;
    public string $risk_level = self::RISK_LEVEL_LOW;
    public int $failed_item_count = 0;
    public int $critical_count = 0;
    public int $high_count = 0;
    public int $medium_count = 0;
    public int $low_count = 0;
    public int $compliance_tagged_count = 0;
    public ?string $scored_at = null;
    public ?int $scored_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
