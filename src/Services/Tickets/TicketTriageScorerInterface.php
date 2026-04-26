<?php

namespace App\Services\Tickets;

use App\Models\Ticket;

/**
 * Phase 10.5 — Pluggable scorer that produces a TriageScore for a ticket.
 *
 * Two implementations are envisioned:
 *
 *   HeuristicTicketTriageScorer (shipped) — rule-based keyword + polarity
 *     analysis. Cheap, deterministic, no external calls. The default in
 *     environments without an AI provider configured.
 *
 *   AI-backed scorer (not in this codebase) — wraps a prompt to OpenAI,
 *     Anthropic, or a local model, parses the structured response into a
 *     TriageScore. Replaceable at the DI container level.
 *
 * Implementations should return a TriageScore whose `confidence` reflects how
 * sure the scorer is about its overall recommendation. The triage queue UI
 * uses confidence to decide whether to surface a "review carefully" badge.
 *
 * The label() method returns a stable identifier that the service writes into
 * generated_by on the persisted suggestion (e.g., "heuristic_v1",
 * "openai_gpt4_v2"). This gives downstream model-evaluation reports a join
 * key for slicing accuracy by provider/version.
 */
interface TicketTriageScorerInterface
{
    public function score(Ticket $ticket): TriageScore;

    public function label(): string;
}
