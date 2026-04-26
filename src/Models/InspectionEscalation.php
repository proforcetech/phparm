<?php

namespace App\Models;

/**
 * Phase 8.3 of docs/expansion-plan.md — per-item escalation record.
 *
 * Created by rule evaluation against a completed inspection report.
 * Lifecycle: pending → acknowledged → resolved. `status` progresses
 * forward only; resolve implies acknowledgment happened (either
 * explicitly or as a side-effect of resolve).
 */
class InspectionEscalation extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_RESOLVED,
    ];

    public const NOTIFY_STATUS_PENDING = 'pending';
    public const NOTIFY_STATUS_SENT = 'sent';
    public const NOTIFY_STATUS_FAILED = 'failed';
    public const NOTIFY_STATUS_SKIPPED = 'skipped';

    public const RESOLUTION_NOTE_MAX_LEN = 2000;

    public int $id = 0;
    public int $rule_id = 0;
    public int $inspection_report_id = 0;
    public int $inspection_report_item_id = 0;
    public string $priority = InspectionEscalationRule::PRIORITY_NORMAL;
    public string $severity = InspectionEscalationRule::SEVERITY_HIGH;
    public ?int $assigned_to_user_id = null;
    public ?string $assigned_to_role = null;
    public string $status = self::STATUS_PENDING;
    public ?string $notification_status = null;
    public ?string $notification_error = null;
    public ?int $acknowledged_by_user_id = null;
    public ?string $acknowledged_at = null;
    public ?int $resolved_by_user_id = null;
    public ?string $resolved_at = null;
    public ?string $resolution_note = null;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
