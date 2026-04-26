<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\TicketEscalationRule;
use DateTimeImmutable;

/**
 * Evaluate escalation rules against open tickets (Phase 3.4 of
 * docs/expansion-plan.md).
 *
 * Designed to be called from bin/cron/ticket-escalation.php on a short
 * cadence (e.g., every 5 minutes).  One evaluation per call:
 *
 *   1. Pull every open ticket (status NOT IN resolved/closed/cancelled).
 *   2. For each ticket, iterate active rules in id order.
 *   3. Skip rules whose match_* scope doesn't cover the ticket.
 *   4. Skip rules whose trigger hasn't fired.
 *   5. Skip rules still in cooldown for that ticket.
 *   6. Apply action (reassign queue / raise priority) + log timeline event
 *      + record escalation event (for cooldown tracking).
 *
 * Actions are kept deliberately narrow; notification delivery is left to a
 * separate layer (future phase) — we record `action_notify_user_id` in the
 * timeline so downstream notifiers can pick it up.
 */
class TicketEscalationService
{
    public const TRIGGERS = ['stale', 'sla_breach_imminent', 'sla_breached'];

    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketEscalationRuleRepository $rules,
        private readonly TicketEscalationEventRepository $events,
        private readonly TicketEventRepository $timeline,
        private readonly SlaClockService $sla,
    ) {
    }

    /**
     * Run one pass; return a summary of actions taken.
     *
     * @return array{evaluated: int, fired: int, skipped_cooldown: int}
     */
    public function runOnce(): array
    {
        $summary = ['evaluated' => 0, 'fired' => 0, 'skipped_cooldown' => 0];
        $rules = $this->rules->listAll(activeOnly: true);
        if ($rules === []) {
            return $summary;
        }
        $now = new DateTimeImmutable($this->now());

        foreach ($this->tickets->listOpen() as $ticket) {
            $summary['evaluated']++;
            $slaSnapshot = null;

            foreach ($rules as $rule) {
                if (!$this->matches($rule, $ticket)) {
                    continue;
                }
                $slaSnapshot ??= $this->sla->snapshot($ticket->id);
                if (!$this->triggerFired($rule, $ticket, $slaSnapshot, $now)) {
                    continue;
                }
                if ($this->inCooldown($rule, $ticket->id, $now)) {
                    $summary['skipped_cooldown']++;
                    continue;
                }
                $this->fire($rule, $ticket);
                $summary['fired']++;
            }
        }
        return $summary;
    }

    private function matches(TicketEscalationRule $rule, Ticket $ticket): bool
    {
        if ($rule->match_division_id !== null && $rule->match_division_id !== $ticket->division_id) {
            return false;
        }
        if ($rule->match_queue_id !== null && $rule->match_queue_id !== $ticket->queue_id) {
            return false;
        }
        if ($rule->match_priority !== null && $rule->match_priority !== $ticket->priority) {
            return false;
        }
        if ($rule->match_status !== null && $rule->match_status !== $ticket->status) {
            return false;
        }
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $slaSnapshot
     */
    private function triggerFired(
        TicketEscalationRule $rule,
        Ticket $ticket,
        array $slaSnapshot,
        DateTimeImmutable $now,
    ): bool {
        switch ($rule->trigger_kind) {
            case 'stale':
                if ($rule->trigger_minutes === null || $ticket->updated_at === null) {
                    return false;
                }
                try {
                    $updated = new DateTimeImmutable($ticket->updated_at);
                } catch (\Throwable) {
                    return false;
                }
                $minutesIdle = ($now->getTimestamp() - $updated->getTimestamp()) / 60;
                return $minutesIdle >= $rule->trigger_minutes;

            case 'sla_breach_imminent':
                if ($rule->trigger_seconds === null) {
                    return false;
                }
                foreach ($slaSnapshot as $clock) {
                    if ($rule->trigger_sla_kind !== null && $clock['clock_kind'] !== $rule->trigger_sla_kind) {
                        continue;
                    }
                    if ($clock['status'] !== 'running') {
                        continue;
                    }
                    if (
                        !$clock['is_breached']
                        && (int) $clock['remaining_seconds'] <= $rule->trigger_seconds
                    ) {
                        return true;
                    }
                }
                return false;

            case 'sla_breached':
                foreach ($slaSnapshot as $clock) {
                    if ($rule->trigger_sla_kind !== null && $clock['clock_kind'] !== $rule->trigger_sla_kind) {
                        continue;
                    }
                    if ($clock['is_breached']) {
                        return true;
                    }
                }
                return false;

            default:
                return false;
        }
    }

    private function inCooldown(TicketEscalationRule $rule, int $ticketId, DateTimeImmutable $now): bool
    {
        $last = $this->events->lastFiredAt($ticketId, $rule->id);
        if ($last === null) {
            return false;
        }
        try {
            $lastAt = new DateTimeImmutable($last);
        } catch (\Throwable) {
            return false;
        }
        $minutesSince = ($now->getTimestamp() - $lastAt->getTimestamp()) / 60;
        return $minutesSince < $rule->cooldown_minutes;
    }

    private function fire(TicketEscalationRule $rule, Ticket $ticket): void
    {
        $mutations = [];
        if ($rule->action_reassign_queue_id !== null) {
            $mutations['queue_id'] = $rule->action_reassign_queue_id;
        }
        if ($rule->action_raise_priority_to !== null) {
            $mutations['priority'] = $rule->action_raise_priority_to;
        }
        if ($mutations !== []) {
            $this->tickets->update($ticket->id, $mutations);
        }
        $actions = $mutations;
        if ($rule->action_notify_user_id !== null) {
            $actions['notify_user_id'] = $rule->action_notify_user_id;
        }
        $this->events->record($ticket->id, $rule->id, $actions);
        $this->timeline->create([
            'ticket_id' => $ticket->id,
            'event_kind' => 'escalated',
            'actor_user_id' => null,
            'is_internal' => 1,
            'payload' => [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'trigger' => $rule->trigger_kind,
                'actions' => $actions,
            ],
        ]);
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
