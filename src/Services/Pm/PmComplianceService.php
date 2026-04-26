<?php

namespace App\Services\Pm;

use App\Models\PmSchedule;
use DateTimeImmutable;

/**
 * Phase 5.4 of docs/expansion-plan.md — missed/overdue tracking + compliance.
 *
 * Two views:
 *
 *   overdueReport(asOfDate, filters)
 *     Schedules whose next_due_at is in the past. Tells an operator right
 *     now: "these PMs should have fired, but haven't." Includes days_overdue
 *     so the UI can colour-code by severity.
 *
 *   scheduleCompliance(schedule, since, until)
 *     Over a window, compares expected cadence ticks against actual
 *     pm_generations rows. Returns {expected, generated, failed,
 *     compliance_rate}. Only defined for fixed_interval + calendar kinds
 *     — meter/condition are event-driven so "expected count" isn't a
 *     meaningful scalar; those get a rate of null with reason='event_driven'.
 *
 *   companyCompliance(companyId, since, until)
 *     Rolls every active schedule for a company into one weighted number,
 *     so managers can see a single "your fleet is at 87% PM compliance"
 *     headline.
 */
class PmComplianceService
{
    public function __construct(
        private readonly PmScheduleRepository $schedules,
        private readonly PmGenerationRepository $generations,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{as_of: string, overdue: array<int, array<string, mixed>>, count: int}
     */
    public function overdueReport(?string $asOf = null, array $filters = []): array
    {
        $asOf ??= (new DateTimeImmutable('today'))->format('Y-m-d');
        $rows = $this->schedules->listOverdue($asOf, $filters);
        $today = new DateTimeImmutable($asOf);

        $out = [];
        foreach ($rows as $s) {
            $due = new DateTimeImmutable((string) $s->next_due_at);
            $days = (int) $today->diff($due)->days * ($today > $due ? 1 : -1);
            $out[] = [
                'schedule_id' => $s->id,
                'plan_id' => $s->plan_id,
                'company_id' => $s->company_id,
                'site_id' => $s->site_id,
                'asset_id' => $s->asset_id,
                'frequency_kind' => $s->frequency_kind,
                'next_due_at' => $s->next_due_at,
                'last_generated_at' => $s->last_generated_at,
                'days_overdue' => $days,
            ];
        }
        return ['as_of' => $asOf, 'overdue' => $out, 'count' => count($out)];
    }

    /**
     * @return array{
     *   schedule_id: int,
     *   since: string, until: string,
     *   expected: int|null, generated: int, failed: int,
     *   compliance_rate: float|null, reason: ?string
     * }
     */
    public function scheduleCompliance(PmSchedule $schedule, string $since, ?string $until = null): array
    {
        $until ??= (new DateTimeImmutable('today'))->format('Y-m-d');
        $counts = $this->generations->countsForScheduleSince($schedule->id, $since);

        $expected = null;
        $reason = null;
        if (in_array($schedule->frequency_kind, ['meter', 'condition'], true)) {
            $reason = 'event_driven';
        } else {
            $expected = $this->expectedTicks($schedule, $since, $until);
        }

        $rate = null;
        if ($expected !== null && $expected > 0) {
            $rate = min(1.0, $counts['generated'] / $expected);
        } elseif ($expected === 0) {
            // No ticks expected in window — by convention 100%.
            $rate = 1.0;
        }

        return [
            'schedule_id' => $schedule->id,
            'since' => $since,
            'until' => $until,
            'expected' => $expected,
            'generated' => $counts['generated'],
            'failed' => $counts['failed'],
            'compliance_rate' => $rate,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{
     *   company_id: int, since: string, until: string,
     *   schedule_count: int, expected: int, generated: int, failed: int,
     *   compliance_rate: float|null,
     *   per_schedule: array<int, array<string, mixed>>
     * }
     */
    public function companyCompliance(int $companyId, string $since, ?string $until = null): array
    {
        $until ??= (new DateTimeImmutable('today'))->format('Y-m-d');
        $schedules = $this->schedules->search(['company_id' => $companyId, 'status' => 'active']);

        $expectedTotal = 0;
        $generatedTotal = 0;
        $failedTotal = 0;
        $hasExpected = false;
        $perSchedule = [];

        foreach ($schedules as $s) {
            $row = $this->scheduleCompliance($s, $since, $until);
            $perSchedule[] = $row;
            if ($row['expected'] !== null) {
                $expectedTotal += $row['expected'];
                $hasExpected = true;
            }
            $generatedTotal += $row['generated'];
            $failedTotal += $row['failed'];
        }

        $rate = null;
        if ($hasExpected && $expectedTotal > 0) {
            $rate = min(1.0, $generatedTotal / $expectedTotal);
        } elseif ($hasExpected) {
            $rate = 1.0;
        }

        return [
            'company_id' => $companyId,
            'since' => $since,
            'until' => $until,
            'schedule_count' => count($schedules),
            'expected' => $expectedTotal,
            'generated' => $generatedTotal,
            'failed' => $failedTotal,
            'compliance_rate' => $rate,
            'per_schedule' => $perSchedule,
        ];
    }

    /**
     * Expected number of cadence ticks in [since, until] for fixed_interval
     * and calendar schedules. Computed by stepping from starts_at forward
     * using the same frequency engine the generator uses — guarantees
     * expected and actual use the same math.
     */
    private function expectedTicks(PmSchedule $schedule, string $since, string $until): int
    {
        $kind = $schedule->frequency_kind;
        $config = $schedule->frequency_config ?? [];

        $sinceDt = new DateTimeImmutable($since);
        $untilDt = new DateTimeImmutable($until);

        if ($kind === 'fixed_interval') {
            $days = $this->intervalDays($config);
            if ($days <= 0) {
                return 0;
            }
            $span = max(0, (int) $sinceDt->diff($untilDt)->days + 1);
            return intdiv($span, $days);
        }

        if ($kind === 'calendar') {
            // Walk from starts_at forward tick-by-tick and count how many land
            // in [since, until]. Bounded at 600 iterations to protect against
            // mis-configured rules.
            $freq = new PmFrequencyService();
            $cursor = $schedule->starts_at;
            $count = 0;
            for ($i = 0; $i < 600; $i++) {
                $probe = clone $schedule;
                $probe->next_due_at = $cursor;
                $next = $freq->advanceAfterGeneration($probe);
                if ($next === null) {
                    break;
                }
                $nextDt = new DateTimeImmutable($next);
                if ($nextDt > $untilDt) {
                    break;
                }
                if ($nextDt >= $sinceDt) {
                    $count++;
                }
                $cursor = $next;
            }
            return $count;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function intervalDays(array $config): int
    {
        if (isset($config['interval_days'])) {
            return max(0, (int) $config['interval_days']);
        }
        if (isset($config['interval_weeks'])) {
            return max(0, (int) $config['interval_weeks']) * 7;
        }
        if (isset($config['interval_months'])) {
            return max(0, (int) $config['interval_months']) * 30;
        }
        if (isset($config['interval_years'])) {
            return max(0, (int) $config['interval_years']) * 365;
        }
        return 0;
    }
}
