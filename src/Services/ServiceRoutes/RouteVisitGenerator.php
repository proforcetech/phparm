<?php

namespace App\Services\ServiceRoutes;

use App\Models\RouteStop;
use App\Models\ServiceRoute;
use DateInterval;
use DateTimeImmutable;

/**
 * Phase 15 / M7 of docs/woms-expansion-plan.md — recurrence planner.
 *
 * Materializes route_visits forward through generation_horizon_days for each
 * active ServiceRoute. Idempotent because the underlying repository uses
 * INSERT IGNORE on (route_stop_id, scheduled_for) — a re-run on the same
 * day, or a cron tick that overlaps a previous run, returns the existing
 * row at the slot rather than double-emitting.
 *
 * Cron entry point: runDueRoutes().
 *
 * Recurrence semantics:
 *   daily   — emit every recurrence_interval days starting from start_date
 *   weekly  — emit on each daysOfWeek() day every recurrence_interval weeks
 *             (falls back to start_date's day-of-week when daysOfWeek empty)
 *   monthly — emit on recurrence_day_of_month every recurrence_interval
 *             months (clamps day-of-month to month length so Feb 31 → Feb 28)
 *   custom  — treated like daily for now; the route's generator is the only
 *             extension point for vertical-specific rules
 *
 * recurrence_time_of_day (HH:MM[:SS]) is appended to each emitted date;
 * defaults to 08:00:00 when null.
 */
class RouteVisitGenerator
{
    private const DEFAULT_TIME_OF_DAY = '08:00:00';

    public function __construct(
        private readonly ServiceRouteRepository $routes,
        private readonly RouteStopRepository $stops,
        private readonly RouteVisitRepository $visits,
    ) {
    }

    /**
     * Cron entry point. Picks routes whose checkpoint is behind the cutoff
     * (today + 1 day, so we always plan at least tomorrow) and rolls each
     * one forward through its individual horizon.
     *
     * @return array{routes_processed: int, visits_created: int}
     */
    public function runDueRoutes(int $batchSize = 200, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $cutoff = $now->modify('+1 day')->format('Y-m-d');

        $routes = $this->routes->listDueForGeneration($cutoff, $batchSize);

        $created = 0;
        foreach ($routes as $route) {
            $created += count($this->generateForRoute($route, $now));
        }

        return [
            'routes_processed' => count($routes),
            'visits_created' => $created,
        ];
    }

    /**
     * Roll a single route forward. Returns the visits that were actually
     * inserted (skipping rows that INSERT IGNORE found already at the slot).
     *
     * @return array<int, \App\Models\RouteVisit>
     */
    public function generateForRoute(ServiceRoute $route, ?DateTimeImmutable $now = null): array
    {
        if (!$route->isActive()) {
            return [];
        }

        $stops = $this->stops->listForRoute($route->id, true);
        if ($stops === []) {
            return [];
        }

        $now ??= new DateTimeImmutable('now');
        $tz = $now->getTimezone();

        // First emit date is whichever is later: route.start_date or
        // last_generated_through + 1 day. When no checkpoint, start_date.
        $startDate = new DateTimeImmutable($route->start_date, $tz);
        $checkpointDate = $route->last_generated_through === null
            ? $startDate
            : (new DateTimeImmutable($route->last_generated_through, $tz))->modify('+1 day');
        $from = $checkpointDate < $startDate ? $startDate : $checkpointDate;

        // Horizon end is now + generation_horizon_days (inclusive). Clamp to
        // route.end_date when set so we don't plan past the route's lifetime.
        $horizonEnd = $now->modify('+' . max(1, $route->generation_horizon_days) . ' days');
        if ($route->end_date !== null && $route->end_date !== '') {
            $endDate = new DateTimeImmutable($route->end_date . ' 23:59:59', $tz);
            if ($endDate < $horizonEnd) {
                $horizonEnd = $endDate;
            }
        }

        $emittedDates = $this->expandDates($route, $from, $horizonEnd);
        if ($emittedDates === []) {
            // Even with no emits, advance the checkpoint so the route doesn't
            // re-scan the same window every minute.
            $this->routes->markGeneratedThrough($route->id, $horizonEnd->format('Y-m-d'));
            return [];
        }

        $time = $this->normalizeTimeOfDay($route->recurrence_time_of_day);
        $created = [];
        $latestEmitted = null;

        foreach ($emittedDates as $date) {
            $scheduledFor = $date->format('Y-m-d') . ' ' . $time;
            foreach ($stops as $stop) {
                $result = $this->visits->createPlannedWithFlag([
                    'service_route_id' => $route->id,
                    'route_stop_id' => $stop->id,
                    'assigned_user_id' => $route->default_assigned_user_id,
                    'scheduled_for' => $scheduledFor,
                    'scheduled_window_minutes' => max(1, $stop->estimated_minutes),
                    'qr_token' => $this->generateQrToken(),
                ]);
                if ($result['inserted']) {
                    $created[] = $result['visit'];
                }
            }
            $latestEmitted = $date;
        }

        // Checkpoint = whichever is later, the last date we emitted on or
        // the horizon end. Either way, the next cron tick won't re-plan
        // dates we've already covered.
        $checkpoint = $latestEmitted !== null && $latestEmitted > $horizonEnd
            ? $latestEmitted
            : $horizonEnd;
        $this->routes->markGeneratedThrough($route->id, $checkpoint->format('Y-m-d'));

        return $created;
    }

    /**
     * Expand a route's recurrence rule into the list of dates that fall
     * within [from, to]. Returns dates only — the time-of-day is appended
     * by the caller from route.recurrence_time_of_day.
     *
     * @return array<int, DateTimeImmutable>
     */
    private function expandDates(ServiceRoute $route, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $startDate = new DateTimeImmutable($route->start_date, $from->getTimezone());
        $interval = max(1, $route->recurrence_interval);
        $cursor = $startDate < $from ? $startDate : $from;
        // Trim cursor to date-only midnight so DateTime arithmetic is stable.
        $cursor = new DateTimeImmutable($cursor->format('Y-m-d'), $from->getTimezone());
        $toDateOnly = new DateTimeImmutable($to->format('Y-m-d'), $from->getTimezone());

        $out = [];

        switch ($route->recurrence_type) {
            case ServiceRoute::RECURRENCE_DAILY:
            case ServiceRoute::RECURRENCE_CUSTOM:
                $cursor = $this->alignDaily($startDate, $cursor, $interval);
                while ($cursor <= $toDateOnly) {
                    if ($cursor >= $from->setTime(0, 0)) {
                        $out[] = $cursor;
                    }
                    $cursor = $cursor->add(new DateInterval('P' . $interval . 'D'));
                }
                break;

            case ServiceRoute::RECURRENCE_WEEKLY:
                $days = $route->daysOfWeek();
                if ($days === []) {
                    $days = [(int) $startDate->format('w')];
                }
                $weekCursor = $this->alignWeekly($startDate, $cursor, $interval);
                while ($weekCursor <= $toDateOnly) {
                    foreach ($days as $dow) {
                        $diff = ($dow - (int) $weekCursor->format('w') + 7) % 7;
                        $emit = $weekCursor->add(new DateInterval('P' . $diff . 'D'));
                        if ($emit >= $startDate
                            && $emit >= $from->setTime(0, 0)
                            && $emit <= $toDateOnly
                        ) {
                            $out[$emit->format('Y-m-d')] = $emit;
                        }
                    }
                    $weekCursor = $weekCursor->add(new DateInterval('P' . ($interval * 7) . 'D'));
                }
                $out = array_values($out);
                break;

            case ServiceRoute::RECURRENCE_MONTHLY:
                $day = $route->recurrence_day_of_month ?? (int) $startDate->format('j');
                $monthCursor = $this->alignMonthly($startDate, $cursor, $interval);
                while ($monthCursor <= $toDateOnly) {
                    $emit = $this->clampDayOfMonth($monthCursor, $day);
                    if ($emit >= $startDate
                        && $emit >= $from->setTime(0, 0)
                        && $emit <= $toDateOnly
                    ) {
                        $out[] = $emit;
                    }
                    $monthCursor = $monthCursor->modify('+' . $interval . ' months');
                }
                break;
        }

        // Sort + de-dupe defensively (weekly path uses keyed array so this
        // is mostly insurance for the other paths).
        usort($out, static fn ($a, $b) => $a <=> $b);
        $seen = [];
        $unique = [];
        foreach ($out as $d) {
            $key = $d->format('Y-m-d');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $d;
            }
        }
        return $unique;
    }

    /**
     * Snap the cursor forward to the next on-cadence day for daily routes.
     * On-cadence means (cursor - start_date) % interval == 0.
     */
    private function alignDaily(
        DateTimeImmutable $startDate,
        DateTimeImmutable $cursor,
        int $interval
    ): DateTimeImmutable {
        if ($interval <= 1) {
            return $cursor < $startDate ? $startDate : $cursor;
        }
        if ($cursor < $startDate) {
            return $startDate;
        }
        $diff = (int) $startDate->diff($cursor)->days;
        $offset = $diff % $interval;
        if ($offset === 0) {
            return $cursor;
        }
        return $cursor->add(new DateInterval('P' . ($interval - $offset) . 'D'));
    }

    /**
     * Snap the cursor back to the start of the on-cadence week for weekly
     * routes. We then walk daysOfWeek() within each on-cadence week.
     */
    private function alignWeekly(
        DateTimeImmutable $startDate,
        DateTimeImmutable $cursor,
        int $interval
    ): DateTimeImmutable {
        // Anchor weekly recurrence to start_date's Sunday.
        $anchor = $startDate->modify('-' . (int) $startDate->format('w') . ' days');
        if ($cursor < $anchor) {
            return $anchor;
        }
        $cursorSunday = $cursor->modify('-' . (int) $cursor->format('w') . ' days');
        if ($interval <= 1) {
            return $cursorSunday;
        }
        $weeksDiff = (int) (((int) $anchor->diff($cursorSunday)->days) / 7);
        $offset = $weeksDiff % $interval;
        if ($offset === 0) {
            return $cursorSunday;
        }
        return $cursorSunday->add(new DateInterval('P' . (($interval - $offset) * 7) . 'D'));
    }

    /**
     * Snap the cursor forward to the start of the next on-cadence month.
     */
    private function alignMonthly(
        DateTimeImmutable $startDate,
        DateTimeImmutable $cursor,
        int $interval
    ): DateTimeImmutable {
        $cursorMonth = new DateTimeImmutable($cursor->format('Y-m-01'), $cursor->getTimezone());
        $startMonth = new DateTimeImmutable($startDate->format('Y-m-01'), $cursor->getTimezone());
        if ($cursorMonth < $startMonth) {
            return $startMonth;
        }
        if ($interval <= 1) {
            return $cursorMonth;
        }
        $monthsDiff = ((int) $cursorMonth->format('Y') - (int) $startMonth->format('Y')) * 12
            + ((int) $cursorMonth->format('n') - (int) $startMonth->format('n'));
        $offset = $monthsDiff % $interval;
        if ($offset === 0) {
            return $cursorMonth;
        }
        return $cursorMonth->modify('+' . ($interval - $offset) . ' months');
    }

    /**
     * Clamp a day-of-month to the actual length of `month`, so day=31 in
     * February emits the 28th/29th rather than rolling into March.
     */
    private function clampDayOfMonth(DateTimeImmutable $month, int $day): DateTimeImmutable
    {
        $lastDay = (int) $month->format('t');
        $clamped = max(1, min($lastDay, $day));
        return new DateTimeImmutable(
            $month->format('Y-m') . '-' . str_pad((string) $clamped, 2, '0', STR_PAD_LEFT),
            $month->getTimezone()
        );
    }

    private function normalizeTimeOfDay(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return self::DEFAULT_TIME_OF_DAY;
        }
        $value = trim($value);
        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }
        return self::DEFAULT_TIME_OF_DAY;
    }

    /**
     * 32 hex chars (128 bits) — collision-free for the QR scan flow and
     * short enough to encode in a small QR.
     */
    private function generateQrToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
