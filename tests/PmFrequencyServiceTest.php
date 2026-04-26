<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\PmSchedule;
use App\Services\Pm\PmFrequencyService;

/**
 * Phase 5.2 of docs/expansion-plan.md — frequency engine.
 *
 * Covers fixed_interval (days/weeks/months/years + year-edge carry),
 * calendar (day_of_month with Feb-clamp, day_of_week, quarterly
 * months_of_year combo), meter advance with multi-interval skip,
 * and condition being a no-op.
 */

function pmfSched(string $kind, array $config, string $startsAt = '2026-01-15', ?string $nextDue = null): PmSchedule
{
    $s = new PmSchedule();
    $s->frequency_kind = $kind;
    $s->frequency_config = $config;
    $s->starts_at = $startsAt;
    $s->next_due_at = $nextDue ?? $startsAt;
    return $s;
}

function pmfCheck(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $ex) {
        echo "  FAIL {$label}: " . $ex->getMessage() . "\n";
        exit(1);
    }
}

function pmfExpectThrow(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        echo "  FAIL {$label}: expected throw containing '{$needle}'\n";
        exit(1);
    } catch (Throwable $ex) {
        if (!str_contains($ex->getMessage(), $needle)) {
            echo "  FAIL {$label}: wrong throw — '" . $ex->getMessage() . "' vs '{$needle}'\n";
            exit(1);
        }
        echo "  PASS {$label}\n";
    }
}

$svc = new PmFrequencyService();

echo "Phase 5.2 — frequency engine\n";

// 1. initialNextDue returns starts_at for every kind (including event-driven).
pmfCheck(function () use ($svc) {
    $s = pmfSched('fixed_interval', ['interval_days' => 7], '2026-02-01');
    if ($svc->initialNextDue($s) !== '2026-02-01') {
        throw new RuntimeException('fixed_interval initial != starts_at');
    }
    $s2 = pmfSched('meter', ['interval_units' => 250, 'unit' => 'hours', 'baseline_reading' => 1000]);
    if ($svc->initialNextDue($s2) !== '2026-01-15') {
        throw new RuntimeException('meter initial != starts_at');
    }
}, 'initialNextDue == starts_at for all kinds');

// 2. fixed_interval — days.
pmfCheck(function () use ($svc) {
    $s = pmfSched('fixed_interval', ['interval_days' => 14], '2026-01-01', '2026-01-01');
    if ($svc->advanceAfterGeneration($s) !== '2026-01-15') {
        throw new RuntimeException('14-day advance wrong');
    }
}, 'fixed_interval days');

// 3. fixed_interval — weeks.
pmfCheck(function () use ($svc) {
    $s = pmfSched('fixed_interval', ['interval_weeks' => 2], '2026-01-01', '2026-01-01');
    if ($svc->advanceAfterGeneration($s) !== '2026-01-15') {
        throw new RuntimeException('2-week advance wrong');
    }
}, 'fixed_interval weeks');

// 4. fixed_interval — months (Jan 31 → Mar 3, PHP's standard month arithmetic).
pmfCheck(function () use ($svc) {
    $s = pmfSched('fixed_interval', ['interval_months' => 1], '2026-01-15', '2026-01-15');
    if ($svc->advanceAfterGeneration($s) !== '2026-02-15') {
        throw new RuntimeException('1-month advance wrong');
    }
}, 'fixed_interval months');

// 5. fixed_interval — years.
pmfCheck(function () use ($svc) {
    $s = pmfSched('fixed_interval', ['interval_years' => 1], '2026-02-29', '2026-02-28');
    if ($svc->advanceAfterGeneration($s) !== '2027-02-28') {
        throw new RuntimeException('1-year advance wrong');
    }
}, 'fixed_interval years');

// 6. fixed_interval — missing config throws.
pmfExpectThrow(function () use ($svc) {
    $s = pmfSched('fixed_interval', [], '2026-01-01', '2026-01-01');
    $svc->advanceAfterGeneration($s);
}, 'requires one of', 'fixed_interval requires an interval_* key');

// 7. calendar — day_of_month monthly.
pmfCheck(function () use ($svc) {
    $s = pmfSched('calendar', ['day_of_month' => 1], '2026-03-01', '2026-03-01');
    if ($svc->advanceAfterGeneration($s) !== '2026-04-01') {
        throw new RuntimeException('next 1st-of-month wrong');
    }
}, 'calendar monthly day_of_month');

// 8. calendar — day_of_month=31 clamps in Feb.
pmfCheck(function () use ($svc) {
    $s = pmfSched('calendar', ['day_of_month' => 31], '2026-01-31', '2026-01-31');
    if ($svc->advanceAfterGeneration($s) !== '2026-02-28') {
        throw new RuntimeException('31st in Feb-2026 should clamp to 28');
    }
}, 'calendar day_of_month=31 clamps to last day');

// 9. calendar — quarterly (months_of_year + day_of_month).
pmfCheck(function () use ($svc) {
    $s = pmfSched('calendar', [
        'months_of_year' => [1, 4, 7, 10], 'day_of_month' => 1,
    ], '2026-04-01', '2026-04-01');
    if ($svc->advanceAfterGeneration($s) !== '2026-07-01') {
        throw new RuntimeException('quarterly should jump Apr→Jul');
    }
}, 'calendar quarterly months_of_year');

// 10. calendar — day_of_week weekly.
pmfCheck(function () use ($svc) {
    // 2026-03-02 is a Monday; next Monday is 2026-03-09.
    $s = pmfSched('calendar', ['day_of_week' => 'mon'], '2026-03-02', '2026-03-02');
    if ($svc->advanceAfterGeneration($s) !== '2026-03-09') {
        throw new RuntimeException('weekly Monday wrong');
    }
}, 'calendar weekly day_of_week');

// 11. calendar — empty config throws.
pmfExpectThrow(function () use ($svc) {
    $s = pmfSched('calendar', [], '2026-01-01', '2026-01-01');
    $svc->advanceAfterGeneration($s);
}, 'needs at least one of', 'calendar requires config');

// 12. calendar — invalid day_of_week throws.
pmfExpectThrow(function () use ($svc) {
    $s = pmfSched('calendar', ['day_of_week' => 'funday'], '2026-01-01', '2026-01-01');
    $svc->advanceAfterGeneration($s);
}, 'unknown day_of_week', 'calendar day_of_week validated');

// 13. meter — reading below threshold returns null (not yet due).
pmfCheck(function () use ($svc) {
    $s = pmfSched('meter', [
        'interval_units' => 250, 'unit' => 'hours', 'baseline_reading' => 1000,
    ]);
    if ($svc->advanceForReading($s, 1100.0, '2026-04-23') !== null) {
        throw new RuntimeException('below threshold should return null');
    }
}, 'meter below threshold → null');

// 14. meter — reading crosses threshold → due today, baseline bumped one interval.
pmfCheck(function () use ($svc) {
    $s = pmfSched('meter', [
        'interval_units' => 250, 'unit' => 'hours', 'baseline_reading' => 1000,
    ]);
    $out = $svc->advanceForReading($s, 1260.0, '2026-04-23');
    if ($out !== '2026-04-23') {
        throw new RuntimeException("expected due today 2026-04-23, got {$out}");
    }
    if (($s->frequency_config['baseline_reading'] ?? null) !== 1250.0) {
        throw new RuntimeException('baseline should advance to 1250');
    }
}, 'meter threshold crossed → due today');

// 15. meter — huge reading skip advances baseline the right number of intervals.
pmfCheck(function () use ($svc) {
    $s = pmfSched('meter', [
        'interval_units' => 100, 'unit' => 'hours', 'baseline_reading' => 500,
    ]);
    $out = $svc->advanceForReading($s, 930.0, '2026-04-23');
    if ($out !== '2026-04-23') {
        throw new RuntimeException('expected due today');
    }
    // 500 → 930 overrun 430, floor(430/100)=4 multiples → baseline 900
    if (($s->frequency_config['baseline_reading'] ?? null) !== 900.0) {
        throw new RuntimeException('baseline should skip-advance to 900, got '
            . var_export($s->frequency_config['baseline_reading'] ?? null, true));
    }
}, 'meter multi-interval skip');

// 16. meter — non-meter schedule returns null.
pmfCheck(function () use ($svc) {
    $s = pmfSched('fixed_interval', ['interval_days' => 7]);
    if ($svc->advanceForReading($s, 9999.0) !== null) {
        throw new RuntimeException('fixed_interval should not honor reading advance');
    }
}, 'advanceForReading ignores non-meter kinds');

// 17. condition — advanceAfterGeneration returns null (manual).
pmfCheck(function () use ($svc) {
    $s = pmfSched('condition', ['trigger' => 'manual']);
    if ($svc->advanceAfterGeneration($s) !== null) {
        throw new RuntimeException('condition kind should be a no-op on advance');
    }
}, 'condition is manual — advance returns null');

// 18. Unknown kind throws.
pmfExpectThrow(function () use ($svc) {
    $s = pmfSched('lunar_phase', [], '2026-01-01', '2026-01-01');
    $svc->advanceAfterGeneration($s);
}, 'unknown frequency_kind', 'unknown kind throws');

echo "\nALL 18 PASS\n";
