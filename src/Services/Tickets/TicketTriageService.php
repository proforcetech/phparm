<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\TicketTriageSuggestion;
use App\Models\User;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 10.5 — orchestrates the AI triage suggestion workflow on tickets.
 *
 * Permission gates:
 *   ticket_triage.view     read suggestion list/find (per-ticket history,
 *                          pending queue)
 *   ticket_triage.generate run a scorer over a ticket and persist the
 *                          resulting suggestion (also marks any prior
 *                          pending suggestion for that ticket as 'stale')
 *   ticket_triage.manage   accept/reject a pending suggestion (the accept
 *                          path writes the suggested fields back to the
 *                          ticket via TicketRepository::update)
 *
 * Lifecycle (TicketTriageSuggestion::ALLOWED_TRANSITIONS):
 *   pending → accepted   triager said yes; applied_changes records what
 *                        actually got persisted to the ticket row
 *   pending → rejected   triager said no with a reason
 *   pending → stale      a fresh re-score replaced this one
 *
 * Re-scoring policy:
 *   generateForTicket() always marks any prior pending suggestion(s) for
 *   the ticket as 'stale' before inserting the new row. We never delete
 *   prior suggestions — they're useful for evaluating scorer accuracy
 *   over time and for triagers who want to see "what did the model say
 *   when this ticket first came in".
 *
 * Accept policy:
 *   Triagers can accept a subset of the suggestion's fields by passing
 *   apply_priority/apply_category/apply_assignee booleans (each defaults
 *   true). The applied_changes column captures the actual writes for
 *   audit — rendered as a JSON object so the row is self-describing.
 */
class TicketTriageService
{
    public function __construct(
        private readonly TicketTriageRepository $repo,
        private readonly TicketRepository $tickets,
        private readonly TicketTriageScorerInterface $scorer,
        private readonly AccessGate $gate,
    ) {
    }

    // ─────────────────────────────────────────────── reads ────

    /**
     * @return array<int, TicketTriageSuggestion>
     */
    public function listForTicket(User $actor, int $ticketId): array
    {
        $this->gate->assert($actor, 'ticket_triage.view');
        return $this->repo->listForTicket($ticketId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, TicketTriageSuggestion>
     */
    public function listPending(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'ticket_triage.view');
        return $this->repo->listPending($filters);
    }

    public function findSuggestion(User $actor, int $id): TicketTriageSuggestion
    {
        $this->gate->assert($actor, 'ticket_triage.view');
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("Triage suggestion {$id} not found");
        }
        return $row;
    }

    // ─────────────────────────────────────────────── generate ────

    public function generateForTicket(
        User $actor,
        int $ticketId,
        ?DateTimeImmutable $now = null
    ): TicketTriageSuggestion {
        $this->gate->assert($actor, 'ticket_triage.generate');

        $ticket = $this->tickets->findById($ticketId);
        if ($ticket === null) {
            throw new InvalidArgumentException("Ticket {$ticketId} not found");
        }

        // Evict any prior pending suggestion so the triage queue widget
        // never shows two pending rows for the same ticket.
        $this->repo->markPriorPendingStale($ticketId);

        $score = $this->scorer->score($ticket);
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        $priority = $score->suggestedPriority;
        if ($priority !== null
            && !in_array($priority, TicketTriageSuggestion::SUGGESTED_PRIORITIES, true)) {
            // A buggy scorer invented a priority outside the known
            // vocabulary — clamp to null rather than persist garbage.
            $priority = null;
        }

        return $this->repo->create([
            'ticket_id' => $ticketId,
            'generated_at' => $stamp,
            'generated_by' => $this->scorer->label(),
            'suggested_priority' => $priority,
            'suggested_category_id' => $score->suggestedCategoryId,
            'suggested_assignee_user_id' => $score->suggestedAssigneeUserId,
            'sentiment_score' => $score->sentimentScore,
            'urgency_score' => $score->urgencyScore,
            'confidence' => $score->confidence,
            'reasoning' => $score->reasoning,
            'status' => TicketTriageSuggestion::STATUS_PENDING,
        ]);
    }

    // ─────────────────────────────────────────────── decisions ────

    /**
     * Accept the suggestion and (selectively) apply its recommended fields
     * back to the ticket. The applied_changes column captures exactly what
     * got written so the audit trail is self-describing.
     *
     * @param array{
     *   apply_priority?: bool,
     *   apply_category?: bool,
     *   apply_assignee?: bool,
     *   notes?: string|null
     * } $options
     */
    public function acceptSuggestion(
        User $actor,
        int $id,
        array $options = [],
        ?DateTimeImmutable $now = null
    ): TicketTriageSuggestion {
        $this->gate->assert($actor, 'ticket_triage.manage');
        $suggestion = $this->repo->findById($id);
        if ($suggestion === null) {
            throw new InvalidArgumentException("Triage suggestion {$id} not found");
        }
        $this->assertTransitionAllowed(
            $suggestion->status,
            TicketTriageSuggestion::STATUS_ACCEPTED
        );

        $applyPriority = (bool) ($options['apply_priority'] ?? true);
        $applyCategory = (bool) ($options['apply_category'] ?? true);
        $applyAssignee = (bool) ($options['apply_assignee'] ?? true);

        $update = [];
        $applied = [];
        if ($applyPriority && $suggestion->suggested_priority !== null) {
            $update['priority'] = $suggestion->suggested_priority;
            $applied['priority'] = $suggestion->suggested_priority;
        }
        if ($applyCategory && $suggestion->suggested_category_id !== null) {
            $update['category_id'] = $suggestion->suggested_category_id;
            $applied['category_id'] = $suggestion->suggested_category_id;
        }
        if ($applyAssignee && $suggestion->suggested_assignee_user_id !== null) {
            $update['assigned_to_user_id'] = $suggestion->suggested_assignee_user_id;
            $applied['assigned_to_user_id'] = $suggestion->suggested_assignee_user_id;
        }

        if ($update !== []) {
            // tickets.update returns the fresh ticket but we don't need it
            // here — the audit happens via applied_changes on the suggestion.
            $this->tickets->update($suggestion->ticket_id, $update);
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->update($id, [
            'status' => TicketTriageSuggestion::STATUS_ACCEPTED,
            'accepted_by_user_id' => $actor->id ?? null,
            'accepted_at' => $stamp,
            'applied_changes' => json_encode($applied, JSON_UNESCAPED_SLASHES),
            'notes' => $options['notes'] ?? $suggestion->notes,
        ]);
    }

    public function rejectSuggestion(
        User $actor,
        int $id,
        string $reason,
        ?DateTimeImmutable $now = null
    ): TicketTriageSuggestion {
        $this->gate->assert($actor, 'ticket_triage.manage');
        $suggestion = $this->repo->findById($id);
        if ($suggestion === null) {
            throw new InvalidArgumentException("Triage suggestion {$id} not found");
        }
        $this->assertTransitionAllowed(
            $suggestion->status,
            TicketTriageSuggestion::STATUS_REJECTED
        );
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('rejection reason is required');
        }
        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->repo->update($id, [
            'status' => TicketTriageSuggestion::STATUS_REJECTED,
            'rejected_by_user_id' => $actor->id ?? null,
            'rejected_at' => $stamp,
            'rejection_reason' => $reason,
        ]);
    }

    // ─────────────────────────────────────────────── helpers ────

    private function assertTransitionAllowed(string $current, string $target): void
    {
        $allowedFrom = TicketTriageSuggestion::ALLOWED_TRANSITIONS[$target] ?? [];
        if (!in_array($current, $allowedFrom, true)) {
            throw new InvalidArgumentException(
                "Illegal transition: {$current} → {$target} "
                . '(allowed from: ' . implode(', ', $allowedFrom) . ')'
            );
        }
    }
}
