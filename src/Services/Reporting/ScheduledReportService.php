<?php

namespace App\Services\Reporting;

use App\Models\ScheduledReport;
use App\Models\User;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Schedule management + execution for recurring reports.
 *
 * The cron expression is a 5-field expression evaluated by computeNextRun()
 * (matches what bin/cron/run.php's local matchesField helper supports —
 * literal numbers, *, * / n, n-m ranges, and n,m,o lists). next_run_at is
 * advanced minute-by-minute up to 7 days out by sweeping for the first
 * minute that matches all five fields.
 *
 * processDue() finds active schedules with next_run_at <= now, runs them,
 * stamps last_run_at + recomputes next_run_at, and returns a summary so
 * the cron runner can log it. Email delivery is handed to a callable
 * dispatcher to keep this service independent of the notification stack.
 */
class ScheduledReportService
{
    public function __construct(
        private ScheduledReportRepository $scheduleRepo,
        private SavedReportRepository $savedRepo,
        private SavedReportService $reportService,
        private ReportExportService $exporter,
        private AccessGate $gate
    ) {
    }

    /**
     * @return array<int, ScheduledReport>
     */
    public function listAll(User $actor): array
    {
        $this->gate->assert($actor, 'reporting.view');
        return $this->scheduleRepo->listAll();
    }

    public function find(User $actor, int $id): ?ScheduledReport
    {
        $this->gate->assert($actor, 'reporting.view');
        return $this->scheduleRepo->find($id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(User $actor, array $payload, ?DateTimeImmutable $now = null): ScheduledReport
    {
        $this->gate->assert($actor, 'reporting.manage');

        $savedReportId = (int) ($payload['saved_report_id'] ?? 0);
        if ($savedReportId <= 0 || $this->savedRepo->find($savedReportId) === null) {
            throw new InvalidArgumentException('Unknown saved_report_id.');
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Schedule name is required.');
        }
        $cron = trim((string) ($payload['cron_expression'] ?? ''));
        if ($cron === '') {
            throw new InvalidArgumentException('cron_expression is required.');
        }
        $this->validateCron($cron);

        $timezone = $this->validateTimezone((string) ($payload['timezone'] ?? 'UTC'));
        $format = (string) ($payload['output_format'] ?? 'csv');
        if (!in_array($format, ScheduledReport::FORMATS, true)) {
            throw new InvalidArgumentException('Unknown output_format.');
        }
        $recipients = $this->validateRecipients((string) ($payload['recipients'] ?? ''));

        $next = $this->computeNextRun($cron, $timezone, $now ?? new DateTimeImmutable());

        return $this->scheduleRepo->create([
            'saved_report_id' => $savedReportId,
            'name' => $name,
            'cron_expression' => $cron,
            'timezone' => $timezone,
            'output_format' => $format,
            'recipients' => $recipients,
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            'next_run_at' => $next,
            'created_by' => (int) $actor->id,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(User $actor, int $id, array $payload, ?DateTimeImmutable $now = null): ?ScheduledReport
    {
        $this->gate->assert($actor, 'reporting.manage');

        $existing = $this->scheduleRepo->find($id);
        if ($existing === null) {
            return null;
        }

        $update = [];
        foreach (['name'] as $field) {
            if (array_key_exists($field, $payload)) {
                $update[$field] = trim((string) $payload[$field]);
            }
        }
        if (array_key_exists('cron_expression', $payload)) {
            $cron = trim((string) $payload['cron_expression']);
            $this->validateCron($cron);
            $update['cron_expression'] = $cron;
        }
        if (array_key_exists('timezone', $payload)) {
            $update['timezone'] = $this->validateTimezone((string) $payload['timezone']);
        }
        if (array_key_exists('output_format', $payload)) {
            $format = (string) $payload['output_format'];
            if (!in_array($format, ScheduledReport::FORMATS, true)) {
                throw new InvalidArgumentException('Unknown output_format.');
            }
            $update['output_format'] = $format;
        }
        if (array_key_exists('recipients', $payload)) {
            $update['recipients'] = $this->validateRecipients((string) $payload['recipients']);
        }
        if (array_key_exists('is_active', $payload)) {
            $update['is_active'] = (bool) $payload['is_active'];
        }

        // Recompute next_run_at if the schedule shape changed
        if (array_key_exists('cron_expression', $update) || array_key_exists('timezone', $update)) {
            $cron = $update['cron_expression'] ?? $existing->cron_expression;
            $tz = $update['timezone'] ?? $existing->timezone;
            $update['next_run_at'] = $this->computeNextRun($cron, $tz, $now ?? new DateTimeImmutable());
        }

        return $this->scheduleRepo->update($id, $update);
    }

    public function delete(User $actor, int $id): bool
    {
        $this->gate->assert($actor, 'reporting.manage');
        return $this->scheduleRepo->delete($id);
    }

    /**
     * Cron-driven entry point: run all due schedules.
     *
     * @param callable(ScheduledReport, string, string, array<int, string>): void $emailDispatcher
     *        Called with ($schedule, $bodyText, $attachmentBytes, $recipients).
     * @return array<int, array<string, mixed>>
     */
    public function processDue(callable $emailDispatcher, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable();
        $due = $this->scheduleRepo->listDue($now->format('Y-m-d H:i:s'));
        $results = [];

        foreach ($due as $schedule) {
            $startedAt = $now->format('Y-m-d H:i:s');
            $saved = $this->savedRepo->find($schedule->saved_report_id);

            if ($saved === null) {
                $this->scheduleRepo->recordRun(
                    (int) $schedule->id,
                    $startedAt,
                    $this->computeNextRun($schedule->cron_expression, $schedule->timezone, $now),
                    'failed',
                    'Saved report not found'
                );
                $results[] = [
                    'schedule_id' => $schedule->id,
                    'status' => 'failed',
                    'error' => 'Saved report not found',
                ];
                continue;
            }

            try {
                $result = $this->reportService->runForSchedule(
                    $saved->report_key,
                    $saved->parameters ?? [],
                    (int) $saved->id,
                    (int) $schedule->id,
                    $now
                );
                $payload = $this->exporter->export(
                    $result['rows'],
                    $result['columns'],
                    $schedule->output_format
                );
                $recipients = array_filter(array_map('trim', explode(',', $schedule->recipients)));
                $emailDispatcher($schedule, $this->renderBody($schedule, $saved, $result), $payload, $recipients);

                $this->scheduleRepo->recordRun(
                    (int) $schedule->id,
                    $startedAt,
                    $this->computeNextRun($schedule->cron_expression, $schedule->timezone, $now),
                    'succeeded',
                    null
                );
                $results[] = [
                    'schedule_id' => $schedule->id,
                    'status' => 'succeeded',
                    'rows' => $result['total'],
                ];
            } catch (Throwable $e) {
                $this->scheduleRepo->recordRun(
                    (int) $schedule->id,
                    $startedAt,
                    $this->computeNextRun($schedule->cron_expression, $schedule->timezone, $now),
                    'failed',
                    substr($e->getMessage(), 0, 1000)
                );
                $results[] = [
                    'schedule_id' => $schedule->id,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function renderBody(ScheduledReport $schedule, $saved, array $result): string
    {
        return "Scheduled report: {$schedule->name}\n"
            . "Report: {$saved->name}\n"
            . "Rows: {$result['total']}\n"
            . "Generated: " . ($result['execution']['started_at'] ?? '');
    }

    /**
     * Public so tests can advance schedules deterministically.
     */
    public function computeNextRun(string $cron, string $timezone, DateTimeImmutable $from): string
    {
        $tz = new DateTimeZone($timezone);
        $local = $from->setTimezone($tz);
        // Truncate to the minute boundary, then advance one minute so we
        // never re-trigger the same minute we were just called from.
        $cursor = $local->setTime((int) $local->format('H'), (int) $local->format('i'), 0)
            ->modify('+1 minute');

        // Sweep at most 7 days of minutes (10080) — well past any reasonable schedule.
        for ($i = 0; $i < 10080; $i++) {
            if ($this->cronMatches($cron, $cursor)) {
                return $cursor->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            $cursor = $cursor->modify('+1 minute');
        }

        throw new RuntimeException('Cron expression yields no run within 7 days: ' . $cron);
    }

    private function cronMatches(string $cron, DateTimeImmutable $when): bool
    {
        $parts = explode(' ', trim($cron));
        if (count($parts) !== 5) {
            return false;
        }
        [$minute, $hour, $dom, $month, $dow] = $parts;
        return $this->fieldMatches($minute, (int) $when->format('i'))
            && $this->fieldMatches($hour, (int) $when->format('G'))
            && $this->fieldMatches($dom, (int) $when->format('j'))
            && $this->fieldMatches($month, (int) $when->format('n'))
            && $this->fieldMatches($dow, (int) $when->format('w'));
    }

    private function fieldMatches(string $field, int $value): bool
    {
        if ($field === '*') {
            return true;
        }
        if (is_numeric($field)) {
            return (int) $field === $value;
        }
        if (str_starts_with($field, '*/')) {
            $step = (int) substr($field, 2);
            return $step > 0 && $value % $step === 0;
        }
        if (str_contains($field, ',')) {
            $list = array_map('intval', explode(',', $field));
            return in_array($value, $list, true);
        }
        if (str_contains($field, '-')) {
            [$start, $end] = explode('-', $field);
            return $value >= (int) $start && $value <= (int) $end;
        }
        return false;
    }

    private function validateCron(string $cron): void
    {
        $parts = explode(' ', trim($cron));
        if (count($parts) !== 5) {
            throw new InvalidArgumentException('cron_expression must have 5 fields.');
        }
    }

    private function validateTimezone(string $timezone): string
    {
        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (\Exception) {
            throw new InvalidArgumentException('Unknown timezone: ' . $timezone);
        }
    }

    private function validateRecipients(string $recipients): string
    {
        $list = array_filter(array_map('trim', explode(',', $recipients)));
        if ($list === []) {
            throw new InvalidArgumentException('At least one recipient is required.');
        }
        foreach ($list as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Invalid recipient email: ' . $email);
            }
        }
        return implode(',', $list);
    }
}
