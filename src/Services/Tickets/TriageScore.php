<?php

namespace App\Services\Tickets;

/**
 * Phase 10.5 — DTO returned by a TicketTriageScorerInterface implementation.
 *
 * A TriageScore is the analytical output of running a scorer over a ticket.
 * The TicketTriageService wraps a TriageScore into a persisted
 * TicketTriageSuggestion row, attaching the ticket id, generator label, and
 * lifecycle status. Keeping the DTO separate from the persisted model means
 * scorer implementations don't need to know anything about persistence
 * mechanics — they just inspect a Ticket and return a verdict.
 *
 * All score fields are nullable because not every scorer produces every
 * signal — a heuristic might compute urgency from keywords but have nothing
 * meaningful to say about sentiment, while an LLM-backed scorer might
 * return all three plus a free-text reasoning trace.
 *
 * Score conventions (used by the heuristic; AI providers should aim to
 * match):
 *   sentiment_score   −1.0 (very negative) → +1.0 (very positive)
 *   urgency_score      0.0 (no urgency)    → +1.0 (extreme urgency)
 *   confidence         0.0 (no confidence) → +1.0 (very confident)
 */
final class TriageScore
{
    public function __construct(
        public readonly ?string $suggestedPriority = null,
        public readonly ?int $suggestedCategoryId = null,
        public readonly ?int $suggestedAssigneeUserId = null,
        public readonly ?float $sentimentScore = null,
        public readonly ?float $urgencyScore = null,
        public readonly ?float $confidence = null,
        public readonly ?string $reasoning = null,
    ) {
    }
}
