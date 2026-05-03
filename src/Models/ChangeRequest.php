<?php

namespace App\Models;

/**
 * ITIL Request for Change (RFC) — Phase 14 / S3 of
 * docs/woms-expansion-plan.md.
 *
 * One row per RFC. The TRANSITIONS map is the authoritative state machine
 * — both ChangeRequestService::transition() and the admin UI's
 * action-bar visibility check it. Adding a state = update both this map
 * and the (small) set of writers that care about the new state.
 *
 * See migration 164_change_management.sql.
 */
class ChangeRequest extends BaseModel
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CAB_REVIEW = 'cab_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALLOWED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_CAB_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_ROLLED_BACK,
        self::STATUS_CANCELLED,
    ];

    /**
     * Allowed forward transitions. cancelled is a wildcard exit from any
     * non-terminal state and is enforced separately in the service.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT       => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED   => [self::STATUS_CAB_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_CAB_REVIEW  => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED    => [self::STATUS_SCHEDULED, self::STATUS_CANCELLED],
        self::STATUS_SCHEDULED   => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_ROLLED_BACK],
        // Terminal states have no outgoing transitions.
        self::STATUS_REJECTED    => [],
        self::STATUS_COMPLETED   => [],
        self::STATUS_ROLLED_BACK => [],
        self::STATUS_CANCELLED   => [],
    ];

    public const TYPE_STANDARD = 'standard';
    public const TYPE_NORMAL = 'normal';
    public const TYPE_EMERGENCY = 'emergency';

    public const ALLOWED_TYPES = [
        self::TYPE_STANDARD,
        self::TYPE_NORMAL,
        self::TYPE_EMERGENCY,
    ];

    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';
    public const RISK_CRITICAL = 'critical';

    public const ALLOWED_RISK_LEVELS = [
        self::RISK_LOW,
        self::RISK_MEDIUM,
        self::RISK_HIGH,
        self::RISK_CRITICAL,
    ];

    public const ALLOWED_IMPACT_LEVELS = self::ALLOWED_RISK_LEVELS;

    public int $id = 0;
    public ?int $customer_id = null;
    public ?int $originating_ticket_id = null;
    public ?int $affected_site_asset_id = null;
    public int $requested_by_user_id = 0;
    public ?int $assigned_to_user_id = null;
    public string $title = '';
    public string $description = '';
    public string $change_type = self::TYPE_NORMAL;
    public string $risk_level = self::RISK_MEDIUM;
    public string $impact_level = self::RISK_MEDIUM;
    public string $status = self::STATUS_DRAFT;
    public ?string $implementation_plan = null;
    public ?string $backout_plan = null;
    public ?string $test_plan = null;
    public ?string $business_justification = null;
    public ?string $planned_start_at = null;
    public ?string $planned_end_at = null;
    public ?string $actual_start_at = null;
    public ?string $actual_end_at = null;
    public ?string $submitted_at = null;
    public ?string $cab_review_started_at = null;
    public ?string $decision_at = null;
    public ?int $decision_by_user_id = null;
    public ?string $decision_notes = null;
    public ?string $completed_at = null;
    public ?string $rolled_back_at = null;
    public ?string $rollback_reason = null;
    public ?string $cancelled_at = null;
    public ?string $cancellation_reason = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @return array<int, string>
     */
    public function allowedTransitions(): array
    {
        // Cancelled is a special wildcard exit that's allowed from any
        // non-terminal state — already encoded in TRANSITIONS for clarity.
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return (self::TRANSITIONS[$this->status] ?? []) === [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
