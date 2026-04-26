<?php

namespace App\Services\Pm;

use App\Models\PmPlan;
use App\Models\PmSchedule;
use App\Services\Tickets\TicketRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use DateTimeImmutable;
use Throwable;

/**
 * Phase 5.3 of docs/expansion-plan.md — PM→ticket auto-generation cron.
 *
 * The daily job walks every active schedule whose next_due_at - lead_time_days
 * has been reached, spawns a ticket from the linked plan, records a
 * pm_generations row, and advances the schedule via the frequency engine so
 * the next cadence is locked in atomically.
 *
 * Design choices:
 *   - One schedule per transaction-ish boundary: a failure on schedule N does
 *     NOT abort the run. The failure is logged to pm_generations with
 *     status='failed' so 5.4 can surface it.
 *   - next_due_at advances BEFORE last_generated_at so the next cron pass has
 *     a forward-looking due date even if last_generated write fails.
 *   - meter/condition kinds are generated just like fixed_interval/calendar
 *     but advanceAfterGeneration returns null — we leave next_due_at in place
 *     so the kind's own event (meter reading, manual trigger) can clear it.
 */
class PmGeneratorService
{
    public function __construct(
        private readonly PmScheduleRepository $schedules,
        private readonly PmPlanRepository $plans,
        private readonly TicketRepository $tickets,
        private readonly PmGenerationRepository $generations,
        private readonly PmFrequencyService $frequency,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Run the cron.
     *
     * @return array{generated: int, failed: int, details: array<int, array<string, mixed>>}
     */
    public function runDueThrough(?string $today = null): array
    {
        $today ??= (new DateTimeImmutable('today'))->format('Y-m-d');
        $due = $this->schedules->listDueThrough($today);

        $generated = 0;
        $failed = 0;
        $details = [];

        foreach ($due as $schedule) {
            try {
                $detail = $this->generateOne($schedule, $today);
                $generated++;
                $details[] = $detail;
            } catch (Throwable $e) {
                $failed++;
                $this->generations->record([
                    'schedule_id' => $schedule->id,
                    'plan_id' => $schedule->plan_id,
                    'ticket_id' => null,
                    'due_at' => $schedule->next_due_at ?? $today,
                    'status' => 'failed',
                    'failure_reason' => substr($e->getMessage(), 0, 500),
                ]);
                $details[] = [
                    'schedule_id' => $schedule->id,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['generated' => $generated, 'failed' => $failed, 'details' => $details];
    }

    /**
     * Generate a single ticket for a due schedule. Kept public so Phase 5.5's
     * manual-trigger path and tests can exercise one schedule at a time.
     *
     * @return array<string, mixed>
     */
    public function generateOne(PmSchedule $schedule, string $today): array
    {
        $plan = $this->plans->findById($schedule->plan_id);
        if ($plan === null) {
            throw new \RuntimeException(
                "pm_plan {$schedule->plan_id} not found for schedule {$schedule->id}"
            );
        }

        $dueAt = $schedule->next_due_at ?? $today;
        $ticketData = $this->buildTicketData($plan, $schedule, $dueAt);
        $ticket = $this->tickets->create($ticketData);

        $generation = $this->generations->record([
            'schedule_id' => $schedule->id,
            'plan_id' => $plan->id,
            'ticket_id' => $ticket->id,
            'due_at' => $dueAt,
            'status' => 'generated',
        ]);

        // Advance cadence. For event-driven kinds (meter/condition)
        // advanceAfterGeneration returns null — keep whatever next_due_at
        // already existed so the next event can clear it.
        $nextDue = $this->frequency->advanceAfterGeneration($schedule);
        $update = ['last_generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s')];
        if ($nextDue !== null) {
            $update['next_due_at'] = $nextDue;
        }
        // If the frequency engine mutated frequency_config (meter kind does
        // when baseline_reading advances), persist the mutation as well.
        $update['frequency_config'] = $schedule->frequency_config;
        $this->schedules->update($schedule->id, $update);

        $this->audit->log(new AuditEntry(
            'pm.generated',
            'pm_schedule',
            $schedule->id,
            null,
            [
                'plan_id' => $plan->id,
                'ticket_id' => $ticket->id,
                'due_at' => $dueAt,
                'next_due_at' => $nextDue,
            ]
        ));

        return [
            'schedule_id' => $schedule->id,
            'plan_id' => $plan->id,
            'ticket_id' => $ticket->id,
            'generation_id' => $generation->id,
            'due_at' => $dueAt,
            'next_due_at' => $nextDue,
            'status' => 'generated',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTicketData(PmPlan $plan, PmSchedule $schedule, string $dueAt): array
    {
        $title = sprintf('%s — due %s', $plan->title, $dueAt);
        $description = $plan->description;
        if ($plan->checklist_json !== null && $plan->checklist_json !== []) {
            $description = ($description ? $description . "\n\n" : '')
                . 'Checklist:'
                . "\n" . $this->formatChecklist($plan->checklist_json);
        }

        return [
            'company_id' => $schedule->company_id,
            'site_id' => $schedule->site_id,
            'division_id' => $plan->division_id,
            'asset_id' => $schedule->asset_id,
            'category_id' => $plan->default_category_id,
            'priority' => $plan->default_priority,
            'status' => 'new',
            'title' => $title,
            'description' => $description,
            'assigned_to_user_id' => $plan->default_assigned_user_id,
            'queue_id' => $plan->default_queue_id,
            'source' => 'pm_generator',
            'source_ref' => 'pm_schedule:' . $schedule->id,
        ];
    }

    /**
     * @param array<int|string, mixed> $items
     */
    private function formatChecklist(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $lines[] = '  - ' . $item;
            } elseif (is_array($item) && isset($item['label'])) {
                $lines[] = '  - ' . (string) $item['label'];
            }
        }
        return implode("\n", $lines);
    }
}
