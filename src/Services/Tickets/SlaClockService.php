<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\TicketSlaClock;
use App\Models\TicketSlaPolicy;
use App\Services\Contracts\ContractSlaResolver;
use DateTimeImmutable;

/**
 * Orchestrates ticket SLA clock lifecycle (Phase 3.2 of docs/expansion-plan.md).
 *
 * Responsibilities:
 *   * attachForTicket()     — mint the 3 per-kind clocks based on the ticket's
 *                             priority/division policy.
 *   * pauseAll() / resumeAll() — triggered when the ticket flips in/out of
 *     the `pending_customer` status (no policy countdown while we wait on
 *     the customer).
 *   * stopKind()            — called when a clock's goal is met
 *                             (e.g., response clock stops on first agent reply).
 *   * detectBreaches()      — compute elapsed; stamp `breached_at` on any
 *     running clock that has exceeded target. Returns newly-breached rows.
 *
 * Elapsed model (see migration 109):
 *   elapsed = accumulated_seconds + (NOW() - last_started_at)   while running
 *   elapsed = accumulated_seconds                               while paused/stopped
 */
class SlaClockService
{
    public const KINDS = ['response', 'arrival', 'resolution'];

    public function __construct(
        private readonly TicketSlaClockRepository $clocks,
        private readonly TicketSlaPolicyRepository $policies,
        private readonly ?ContractSlaResolver $contractSla = null,
    ) {
    }

    /**
     * Resolve which SLA policy governs this ticket. Contract-sourced policies
     * (Phase 4.5) override the default (division, priority) lookup — when a
     * site is covered by an active contract entitlement linking an SLA policy,
     * that policy wins. Returns the chosen policy + origin metadata, or null
     * if no policy applies at all.
     *
     * @return array{
     *   policy: TicketSlaPolicy,
     *   source: string,
     *   contract_id: ?int,
     *   entitlement_id: ?int
     * }|null
     */
    public function resolvePolicy(Ticket $ticket): ?array
    {
        if ($this->contractSla !== null) {
            $override = $this->contractSla->resolveForTicket($ticket);
            if ($override !== null) {
                return [
                    'policy' => $override['policy'],
                    'source' => 'contract',
                    'contract_id' => $override['contract']->id,
                    'entitlement_id' => $override['entitlement']->id,
                ];
            }
        }
        $policy = $this->policies->findForTicket($ticket->division_id, $ticket->priority);
        if ($policy === null) {
            return null;
        }
        return [
            'policy' => $policy,
            'source' => 'default',
            'contract_id' => null,
            'entitlement_id' => null,
        ];
    }

    /**
     * Mint the initial clocks for a freshly-created ticket. Safe to re-call:
     * a clock row already present for (ticket, kind) is left alone.
     *
     * @return array<int, TicketSlaClock>
     */
    public function attachForTicket(Ticket $ticket): array
    {
        $resolved = $this->resolvePolicy($ticket);
        if ($resolved === null) {
            return [];
        }
        $policy = $resolved['policy'];
        $now = $this->now();
        $minutesByKind = [
            'response' => $policy->response_minutes,
            'arrival' => $policy->arrival_minutes,
            'resolution' => $policy->resolution_minutes,
        ];
        $created = [];
        foreach ($minutesByKind as $kind => $minutes) {
            if ($minutes === null || $minutes <= 0) {
                continue;
            }
            if ($this->clocks->findForTicketAndKind($ticket->id, $kind) !== null) {
                continue;
            }
            $created[] = $this->clocks->create([
                'ticket_id' => $ticket->id,
                'policy_id' => $policy->id,
                'clock_kind' => $kind,
                'target_minutes' => $minutes,
                'status' => 'running',
                'accumulated_seconds' => 0,
                'last_started_at' => $now,
            ]);
        }
        return $created;
    }

    /**
     * Pause every running clock on a ticket. Accumulated seconds absorb the
     * time since `last_started_at`.
     *
     * @return array<int, TicketSlaClock>
     */
    public function pauseAll(int $ticketId): array
    {
        $affected = [];
        foreach ($this->clocks->listForTicket($ticketId) as $clock) {
            if ($clock->status !== 'running') {
                continue;
            }
            $elapsed = $this->secondsSinceStart($clock);
            $now = $this->now();
            $affected[] = $this->clocks->update($clock->id, [
                'status' => 'paused',
                'accumulated_seconds' => $clock->accumulated_seconds + $elapsed,
                'last_started_at' => null,
                'paused_at' => $now,
            ]);
        }
        return $affected;
    }

    /**
     * Resume every paused clock on a ticket.
     *
     * @return array<int, TicketSlaClock>
     */
    public function resumeAll(int $ticketId): array
    {
        $affected = [];
        foreach ($this->clocks->listForTicket($ticketId) as $clock) {
            if ($clock->status !== 'paused') {
                continue;
            }
            $now = $this->now();
            $affected[] = $this->clocks->update($clock->id, [
                'status' => 'running',
                'last_started_at' => $now,
                'paused_at' => null,
            ]);
        }
        return $affected;
    }

    /**
     * Stop a specific clock kind. Used when a milestone is hit (e.g.,
     * resolution clock stops on status → resolved, response clock stops when
     * first_response_at is stamped).
     */
    public function stopKind(int $ticketId, string $kind): ?TicketSlaClock
    {
        $clock = $this->clocks->findForTicketAndKind($ticketId, $kind);
        if ($clock === null || $clock->status === 'stopped') {
            return $clock;
        }
        $accumulated = $clock->accumulated_seconds;
        if ($clock->status === 'running') {
            $accumulated += $this->secondsSinceStart($clock);
        }
        $now = $this->now();
        return $this->clocks->update($clock->id, [
            'status' => 'stopped',
            'accumulated_seconds' => $accumulated,
            'last_started_at' => null,
            'paused_at' => null,
            'stopped_at' => $now,
        ]);
    }

    /**
     * Stop every clock on the ticket. Called when the ticket is closed — we
     * don't want to keep ticking once it's terminal.
     *
     * @return array<int, TicketSlaClock>
     */
    public function stopAll(int $ticketId): array
    {
        $affected = [];
        foreach (self::KINDS as $kind) {
            $stopped = $this->stopKind($ticketId, $kind);
            if ($stopped !== null) {
                $affected[] = $stopped;
            }
        }
        return $affected;
    }

    /**
     * Compute a snapshot of all clocks for a ticket with derived fields
     * (elapsed_seconds, remaining_seconds, is_breached).
     *
     * @return array<int, array<string, mixed>>
     */
    public function snapshot(int $ticketId): array
    {
        $out = [];
        foreach ($this->clocks->listForTicket($ticketId) as $clock) {
            $out[] = $this->decorate($clock);
        }
        return $out;
    }

    /**
     * Scan for running clocks past their target, stamp `breached_at`, and
     * return the rows that were newly marked. Designed to be called from the
     * minute-by-minute cron runner.
     *
     * @return array<int, TicketSlaClock>
     */
    public function detectBreaches(): array
    {
        $now = $this->now();
        $breached = [];
        foreach ($this->clocks->listDueForBreach() as $clock) {
            $breached[] = $this->clocks->update($clock->id, [
                'breached_at' => $now,
            ]);
        }
        return $breached;
    }

    /**
     * @return array<string, mixed>
     */
    public function decorate(TicketSlaClock $clock): array
    {
        $row = $clock->toArray();
        $elapsed = $clock->accumulated_seconds;
        if ($clock->status === 'running') {
            $elapsed += $this->secondsSinceStart($clock);
        }
        $targetSeconds = $clock->target_minutes * 60;
        $row['elapsed_seconds'] = $elapsed;
        $row['remaining_seconds'] = $targetSeconds - $elapsed;
        $row['is_breached'] = $clock->breached_at !== null || $elapsed >= $targetSeconds;
        return $row;
    }

    private function secondsSinceStart(TicketSlaClock $clock): int
    {
        if ($clock->last_started_at === null) {
            return 0;
        }
        try {
            $started = new DateTimeImmutable($clock->last_started_at);
        } catch (\Throwable) {
            return 0;
        }
        $now = new DateTimeImmutable($this->now());
        $delta = $now->getTimestamp() - $started->getTimestamp();
        return $delta > 0 ? $delta : 0;
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
