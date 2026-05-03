<?php

namespace App\Models;

/**
 * One materialized occurrence of a route stop — Phase 15 / M7 of
 * docs/woms-expansion-plan.md.
 *
 * The TRANSITIONS map is the authoritative state machine; both
 * RouteVisitService::transition() and the mobile UI's action-bar
 * visibility check it. Adding a state = update both this map and the
 * (small) set of writers that care about the new state.
 *
 * Visit lifecycle:
 *   planned -> en_route -> arrived -> completed
 *   planned -> skipped              (tech declined the stop)
 *   planned -> missed               (cron marked it after window expired)
 *   en_route -> {arrived, skipped}
 *   arrived -> {completed, skipped}
 *
 * See migration 165_recurring_service_routes.sql.
 */
class RouteVisit extends BaseModel
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_EN_ROUTE = 'en_route';
    public const STATUS_ARRIVED = 'arrived';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_MISSED = 'missed';

    public const ALLOWED_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_EN_ROUTE,
        self::STATUS_ARRIVED,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_MISSED,
    ];

    /**
     * Allowed forward transitions. Terminal states (completed/skipped/missed)
     * have no outgoing transitions.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PLANNED   => [self::STATUS_EN_ROUTE, self::STATUS_ARRIVED, self::STATUS_SKIPPED, self::STATUS_MISSED],
        self::STATUS_EN_ROUTE  => [self::STATUS_ARRIVED, self::STATUS_SKIPPED, self::STATUS_MISSED],
        self::STATUS_ARRIVED   => [self::STATUS_COMPLETED, self::STATUS_SKIPPED],
        self::STATUS_COMPLETED => [],
        self::STATUS_SKIPPED   => [],
        self::STATUS_MISSED    => [],
    ];

    public int $id = 0;
    public int $service_route_id = 0;
    public int $route_stop_id = 0;
    public ?int $workorder_id = null;
    public ?int $assigned_user_id = null;
    public string $scheduled_for = '';
    public int $scheduled_window_minutes = 60;
    public string $status = self::STATUS_PLANNED;
    public string $qr_token = '';
    public ?string $en_route_at = null;
    public ?string $arrived_at = null;
    public ?string $arrival_lat = null;
    public ?string $arrival_lng = null;
    public ?string $completed_at = null;
    public ?string $skipped_at = null;
    public ?string $skip_reason = null;
    public ?string $missed_at = null;
    public int $photos_uploaded = 0;
    public ?bool $verification_passed = null;
    public ?string $verification_notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @return array<int, string>
     */
    public function allowedTransitions(): array
    {
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
