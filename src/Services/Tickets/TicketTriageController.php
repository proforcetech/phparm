<?php

namespace App\Services\Tickets;

use App\Models\TicketTriageSuggestion;
use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 10.5 — thin HTTP facade for the ticket triage suggestion workflow.
 *
 * Each handler returns the {"data": ...} envelope used by the rest of the
 * API. Controllers do no business logic — gating, validation, lifecycle
 * moves, and ticket-write side-effects all live in TicketTriageService.
 */
class TicketTriageController
{
    public function __construct(private readonly TicketTriageService $service)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function listForTicket(User $actor, int $ticketId): array
    {
        $items = $this->service->listForTicket($actor, $ticketId);
        return ['data' => array_map(
            static fn(TicketTriageSuggestion $s) => $s->toArray(),
            $items
        )];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listPending(User $actor, array $filters): array
    {
        $items = $this->service->listPending($actor, $filters);
        return ['data' => array_map(
            static fn(TicketTriageSuggestion $s) => $s->toArray(),
            $items
        )];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSuggestion(User $actor, int $id): array
    {
        return ['data' => $this->service->findSuggestion($actor, $id)->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateForTicket(User $actor, int $ticketId): array
    {
        return ['data' => $this->service->generateForTicket($actor, $ticketId)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function acceptSuggestion(User $actor, int $id, array $payload): array
    {
        $options = [];
        foreach (['apply_priority', 'apply_category', 'apply_assignee'] as $flag) {
            if (array_key_exists($flag, $payload)) {
                $options[$flag] = (bool) $payload[$flag];
            }
        }
        if (array_key_exists('notes', $payload)) {
            $options['notes'] = $payload['notes'];
        }
        return ['data' => $this->service->acceptSuggestion($actor, $id, $options)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function rejectSuggestion(User $actor, int $id, array $payload): array
    {
        $reason = isset($payload['rejection_reason']) ? (string) $payload['rejection_reason'] : '';
        if (trim($reason) === '') {
            throw new InvalidArgumentException('rejection_reason is required');
        }
        return ['data' => $this->service->rejectSuggestion($actor, $id, $reason)->toArray()];
    }
}
