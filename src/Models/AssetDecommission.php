<?php

namespace App\Models;

/**
 * Decommissioning workflow row — Phase 13 (M5) of docs/woms-expansion-plan.md.
 *
 * Targets an existing site_asset and walks it through:
 *   initiated
 *     → wipe_in_progress → wipe_complete   (skipped when requires_wipe = 0)
 *     → recovery_in_progress → recovery_complete
 *     → entitlement_updated
 *     → audited
 *     → retired (terminal)
 * with `cancelled` as a terminal off-ramp from any non-terminal state.
 *
 * The terminal `retired` step is what flips the underlying
 * site_assets.status='retired' and stamps site_assets.decommissioned_at.
 *
 * Per-transition history is captured in `audit_logs` keyed by
 *   entity_type='asset_decommission', event='decommission.transitioned'.
 *
 * The formal `audited` step additionally records the inserted audit_logs.id
 * back onto this row (asset_decommissions.audit_log_id) so reports can pull
 * the auditor's signed entry directly.
 *
 * See migration 160_asset_lease_lifecycle.sql for the schema.
 */
class AssetDecommission extends BaseModel
{
    public const STATUS_INITIATED = 'initiated';
    public const STATUS_WIPE_IN_PROGRESS = 'wipe_in_progress';
    public const STATUS_WIPE_COMPLETE = 'wipe_complete';
    public const STATUS_RECOVERY_IN_PROGRESS = 'recovery_in_progress';
    public const STATUS_RECOVERY_COMPLETE = 'recovery_complete';
    public const STATUS_ENTITLEMENT_UPDATED = 'entitlement_updated';
    public const STATUS_AUDITED = 'audited';
    public const STATUS_RETIRED = 'retired';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALLOWED_STATUSES = [
        self::STATUS_INITIATED,
        self::STATUS_WIPE_IN_PROGRESS,
        self::STATUS_WIPE_COMPLETE,
        self::STATUS_RECOVERY_IN_PROGRESS,
        self::STATUS_RECOVERY_COMPLETE,
        self::STATUS_ENTITLEMENT_UPDATED,
        self::STATUS_AUDITED,
        self::STATUS_RETIRED,
        self::STATUS_CANCELLED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_RETIRED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Allowed forward transitions. `cancelled` is reachable from every
     * non-terminal state and is added at lookup time, so we don't repeat it
     * in every row here.
     *
     * From `initiated` the worker may either start the wipe (when
     * requires_wipe=1) or skip directly to recovery (when requires_wipe=0).
     * Both are listed; the service enforces which one matches the row's
     * requires_wipe flag.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_INITIATED => [
            self::STATUS_WIPE_IN_PROGRESS,
            self::STATUS_RECOVERY_IN_PROGRESS,
        ],
        self::STATUS_WIPE_IN_PROGRESS => [self::STATUS_WIPE_COMPLETE],
        self::STATUS_WIPE_COMPLETE => [self::STATUS_RECOVERY_IN_PROGRESS],
        self::STATUS_RECOVERY_IN_PROGRESS => [self::STATUS_RECOVERY_COMPLETE],
        self::STATUS_RECOVERY_COMPLETE => [self::STATUS_ENTITLEMENT_UPDATED],
        self::STATUS_ENTITLEMENT_UPDATED => [self::STATUS_AUDITED],
        self::STATUS_AUDITED => [self::STATUS_RETIRED],
        self::STATUS_RETIRED => [],
        self::STATUS_CANCELLED => [],
    ];

    public int $id = 0;
    public int $site_asset_id = 0;
    public int $customer_id = 0;
    public ?int $requested_by_user_id = null;
    public ?int $requested_by_portal_user_id = null;
    public ?string $requested_at = null;
    public string $reason = 'eol';
    public ?string $notes = null;
    public int $requires_wipe = 0;
    public string $recovery_method = 'none';
    public string $status = self::STATUS_INITIATED;
    public ?string $wipe_started_at = null;
    public ?string $wipe_completed_at = null;
    public ?int $wipe_completed_by = null;
    public ?string $wipe_certificate_url = null;
    public ?string $recovery_started_at = null;
    public ?string $recovery_completed_at = null;
    public ?int $recovery_completed_by = null;
    public ?string $recovery_reference = null;
    public ?int $recovery_value_cents = null;
    public ?string $entitlement_updated_at = null;
    public ?int $entitlement_updated_by = null;
    public ?string $audited_at = null;
    public ?int $audited_by = null;
    public ?int $audit_log_id = null;
    public ?string $retired_at = null;
    public ?int $retired_by = null;
    public ?string $cancelled_at = null;
    public ?int $cancelled_by = null;
    public ?string $cancelled_reason = null;
    public ?string $last_state_changed_at = null;
    public ?int $last_state_changed_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function requiresWipe(): bool
    {
        return (int) $this->requires_wipe === 1;
    }

    /**
     * Returns the forward transitions allowed from $status, with `cancelled`
     * appended for every non-terminal state.
     *
     * Note: `initiated` lists BOTH wipe_in_progress and recovery_in_progress.
     * The service further filters this by the row's requires_wipe flag —
     * callers wanting a row-aware list should use {@see allowedTransitionsFor()}.
     *
     * @return array<int, string>
     */
    public static function allowedTransitionsFrom(string $status): array
    {
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            return [];
        }
        $forward = self::TRANSITIONS[$status] ?? [];
        return array_values(array_unique(array_merge($forward, [self::STATUS_CANCELLED])));
    }

    /**
     * Row-aware variant: filters out the wipe/recovery branch that doesn't
     * match this row's requires_wipe flag. Use this when surfacing
     * affordances to the UI so users only see legal next steps.
     *
     * @return array<int, string>
     */
    public function allowedTransitions(): array
    {
        $base = self::allowedTransitionsFrom($this->status);
        if ($this->status !== self::STATUS_INITIATED) {
            return $base;
        }
        $drop = $this->requiresWipe()
            ? self::STATUS_RECOVERY_IN_PROGRESS
            : self::STATUS_WIPE_IN_PROGRESS;
        return array_values(array_filter($base, static fn ($s) => $s !== $drop));
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedTransitionsFrom($from), true);
    }
}
