<?php

namespace App\Services\Pm;

use App\Models\PmSchedule;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 5.2 of docs/expansion-plan.md — frequency engine.
 *
 * Pure computation: given a schedule and (optionally) an anchor date or meter
 * reading, return the next_due_at. The 5.3 generator calls advanceAfterGeneration
 * immediately after spawning a PM ticket so next_due_at tracks the next cadence
 * without drift.
 *
 * Frequency kinds and expected frequency_config shapes:
 *
 *   fixed_interval  { interval_days | interval_weeks | interval_months | interval_years: int }
 *                   Each tick shifts next_due_at forward by the same amount.
 *
 *   calendar        { day_of_month?: int,
 *                     day_of_week?: 'mon'..'sun',
 *                     months_of_year?: int[] (1..12) }
 *                   Advances to the next calendar anchor after the current
 *                   next_due_at. A monthly policy sets day_of_month; quarterly
 *                   sets months_of_year=[1,4,7,10] + day_of_month=1; a weekly
 *                   standing visit sets day_of_week.
 *
 *   meter           { interval_units: number, unit: 'hours'|'miles'|'km',
 *                     baseline_reading: number }
 *                   Auto-advance only kicks in when advanceForReading() is
 *                   called with a fresh reading that has crossed the threshold.
 *
 *   condition       { trigger: 'manual' }
 *                   Stays on whatever next_due_at it has (usually NULL) until
 *                   a user / external event manually sets it. The engine is
 *                   a no-op for this kind.
 */
class PmFrequencyService
{
    /**
     * Compute next_due_at *starting* from a base date. Used when the schedule
     * is first created (starts_at → next_due_at) or when re-anchoring.
     */
    public function initialNextDue(PmSchedule $schedule): ?string
    {
        if ($schedule->starts_at === '') {
            return null;
        }
        if (in_array($schedule->frequency_kind, ['meter', 'condition'], true)) {
            // Event-driven kinds: next_due_at is set by the event that
            // triggers them, not by the clock. starts_at is just a bookmark.
            return $schedule->starts_at;
        }
        // For fixed_interval and calendar, the first tick is starts_at itself.
        return $schedule->starts_at;
    }

    /**
     * Advance next_due_at after the generator cron has spawned work for this
     * schedule. Returns the new next_due_at (or null when the schedule has no
     * automatic cadence — meter/condition).
     */
    public function advanceAfterGeneration(PmSchedule $schedule): ?string
    {
        $anchor = $schedule->next_due_at ?: $schedule->starts_at;
        if ($anchor === '' || $anchor === null) {
            return null;
        }
        return match ($schedule->frequency_kind) {
            'fixed_interval' => $this->addFixedInterval($anchor, $schedule->frequency_config ?? []),
            'calendar' => $this->nextCalendarAnchor($anchor, $schedule->frequency_config ?? []),
            'meter', 'condition' => null,
            default => throw new InvalidArgumentException(
                "unknown frequency_kind: {$schedule->frequency_kind}"
            ),
        };
    }

    /**
     * For meter-driven schedules, return the next_due_at date when a fresh
     * reading has crossed the configured threshold. Callers should pass the
     * most recent reading (in the unit the schedule was configured for); if
     * the threshold hasn't been reached, returns null to signal "not yet".
     *
     * Without asset meter infrastructure yet (planned for Phase 7), meter
     * schedules are projected as "due today" once crossed — downstream
     * consumers can clock a lead_time_days offset as needed.
     */
    public function advanceForReading(
        PmSchedule $schedule,
        float $currentReading,
        ?string $today = null,
    ): ?string {
        if ($schedule->frequency_kind !== 'meter') {
            return null;
        }
        $config = $schedule->frequency_config ?? [];
        $interval = isset($config['interval_units'])
            ? (float) $config['interval_units'] : 0.0;
        $baseline = isset($config['baseline_reading'])
            ? (float) $config['baseline_reading'] : 0.0;
        if ($interval <= 0) {
            return null;
        }
        $threshold = $baseline + $interval;
        if ($currentReading < $threshold) {
            return null;
        }
        // Crossed — due today. The next_due_at shifts by whole multiples of
        // the interval so a double-crossing (e.g. reading jumped 2x interval)
        // still advances forward the right number of ticks.
        $overrun = $currentReading - $baseline;
        $multiples = max(1, (int) floor($overrun / $interval));
        $newBaseline = $baseline + ($multiples * $interval);
        // Mutate config on the in-memory model so the caller can persist it.
        $config['baseline_reading'] = $newBaseline;
        $schedule->frequency_config = $config;
        return ($today ?: (new DateTimeImmutable('today'))->format('Y-m-d'));
    }

    // ────────────────────────────── fixed_interval ──────────────────────────

    /**
     * @param array<string, mixed> $config
     */
    private function addFixedInterval(string $anchor, array $config): string
    {
        $spec = $this->intervalSpec($config);
        if ($spec === null) {
            throw new InvalidArgumentException(
                'fixed_interval requires one of interval_days, interval_weeks, interval_months, interval_years'
            );
        }
        $date = new DateTimeImmutable($anchor);
        $date = $date->add(new DateInterval($spec));
        return $date->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function intervalSpec(array $config): ?string
    {
        foreach (
            [
                'interval_days' => 'D',
                'interval_weeks' => 'W',
                'interval_months' => 'M',
                'interval_years' => 'Y',
            ] as $key => $designator
        ) {
            if (isset($config[$key]) && (int) $config[$key] > 0) {
                $n = (int) $config[$key];
                // ISO-8601 duration format: PnD, PnW, PnM, PnY
                return 'P' . $n . $designator;
            }
        }
        return null;
    }

    // ──────────────────────────────── calendar ──────────────────────────────

    /**
     * Walk forward from `anchor` (exclusive) to the next calendar anchor that
     * satisfies all configured constraints. Tries at most ~400 days so
     * mis-configured schedules can't loop forever.
     *
     * @param array<string, mixed> $config
     */
    private function nextCalendarAnchor(string $anchor, array $config): string
    {
        $hasDayOfMonth = isset($config['day_of_month']);
        $hasDayOfWeek = isset($config['day_of_week']);
        $hasMonths = !empty($config['months_of_year']);

        if (!$hasDayOfMonth && !$hasDayOfWeek && !$hasMonths) {
            throw new InvalidArgumentException(
                'calendar frequency needs at least one of day_of_month, day_of_week, months_of_year'
            );
        }

        $date = new DateTimeImmutable($anchor);
        $dow = $hasDayOfWeek ? $this->dayOfWeekNumber((string) $config['day_of_week']) : null;
        $dom = $hasDayOfMonth ? max(1, min(31, (int) $config['day_of_month'])) : null;
        $months = $hasMonths
            ? array_values(array_filter(
                array_map('intval', (array) $config['months_of_year']),
                fn($m) => $m >= 1 && $m <= 12
            ))
            : null;

        for ($i = 1; $i <= 400; $i++) {
            $candidate = $date->add(new DateInterval('P' . $i . 'D'));
            if ($dow !== null && (int) $candidate->format('N') !== $dow) {
                continue;
            }
            if ($dom !== null && (int) $candidate->format('j') !== $this->clampDayOfMonth($candidate, $dom)) {
                continue;
            }
            if ($months !== null && !in_array((int) $candidate->format('n'), $months, true)) {
                continue;
            }
            return $candidate->format('Y-m-d');
        }
        throw new InvalidArgumentException(
            'calendar config never matches within 400-day horizon; config likely invalid'
        );
    }

    private function dayOfWeekNumber(string $name): int
    {
        $map = [
            'mon' => 1, 'monday' => 1,
            'tue' => 2, 'tues' => 2, 'tuesday' => 2,
            'wed' => 3, 'weds' => 3, 'wednesday' => 3,
            'thu' => 4, 'thur' => 4, 'thurs' => 4, 'thursday' => 4,
            'fri' => 5, 'friday' => 5,
            'sat' => 6, 'saturday' => 6,
            'sun' => 7, 'sunday' => 7,
        ];
        $key = strtolower($name);
        if (!isset($map[$key])) {
            throw new InvalidArgumentException("unknown day_of_week: {$name}");
        }
        return $map[$key];
    }

    /**
     * If day_of_month=31 but the month only has 30 days, fall back to the
     * last day of that month (so Feb 28/29 still matches dom=31).
     */
    private function clampDayOfMonth(DateTimeImmutable $date, int $dom): int
    {
        $last = (int) $date->format('t');
        return min($dom, $last);
    }
}
