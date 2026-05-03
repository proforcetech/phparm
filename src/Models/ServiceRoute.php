<?php

namespace App\Models;

/**
 * Recurring service route — Phase 15 / M7 of docs/woms-expansion-plan.md.
 *
 * Cleaning is the driving use case but the same primitives serve recurring
 * security audits, PM rounds, and any other "same techs, same sites, same
 * cadence" workflow. The cron-based generator (RouteVisitGenerator)
 * materializes route_visits forward through generation_horizon_days.
 *
 * See migration 165_recurring_service_routes.sql.
 */
class ServiceRoute extends BaseModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    public const ALLOWED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_ARCHIVED,
    ];

    public const RECURRENCE_DAILY = 'daily';
    public const RECURRENCE_WEEKLY = 'weekly';
    public const RECURRENCE_MONTHLY = 'monthly';
    public const RECURRENCE_CUSTOM = 'custom';

    public const ALLOWED_RECURRENCE_TYPES = [
        self::RECURRENCE_DAILY,
        self::RECURRENCE_WEEKLY,
        self::RECURRENCE_MONTHLY,
        self::RECURRENCE_CUSTOM,
    ];

    public int $id = 0;
    public int $customer_id = 0;
    public ?int $service_line_id = null;
    public ?int $default_assigned_user_id = null;
    public string $name = '';
    public ?string $description = null;
    public string $status = self::STATUS_ACTIVE;
    public string $recurrence_type = self::RECURRENCE_WEEKLY;
    public int $recurrence_interval = 1;
    public ?string $recurrence_days_of_week = null;
    public ?int $recurrence_day_of_month = null;
    public ?string $recurrence_time_of_day = null;
    public string $start_date = '';
    public ?string $end_date = null;
    public int $generation_horizon_days = 14;
    public ?string $last_generated_through = null;
    public bool $photo_verification_required = false;
    public int $min_photos_per_visit = 0;
    public int $estimated_visit_minutes = 30;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * Decode the recurrence_days_of_week comma list (e.g. "1,3,5") into ints.
     * Sun=0 ... Sat=6, matching PHP's `w` date format.
     *
     * @return array<int, int>
     */
    public function daysOfWeek(): array
    {
        if ($this->recurrence_days_of_week === null || $this->recurrence_days_of_week === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $this->recurrence_days_of_week) as $piece) {
            $n = (int) trim($piece);
            if ($n >= 0 && $n <= 6) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
