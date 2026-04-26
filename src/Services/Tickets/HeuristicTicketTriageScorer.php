<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\TicketTriageSuggestion;

/**
 * Phase 10.5 — Default rule-based triage scorer.
 *
 * Strategy:
 *   urgency_score    — keyword-driven. "outage", "down", "fire", "leak",
 *                      "no_heat", "no_brakes" etc. push urgency up;
 *                      explicit deadline phrases ("by tomorrow", "asap")
 *                      add a smaller bump.
 *   sentiment_score  — token-level polarity. Negative tokens ("broken",
 *                      "ruined", "frustrated", "angry") drag toward −1.0;
 *                      positive tokens ("thanks", "great", "appreciate")
 *                      push toward +1.0.
 *   suggested_priority — derived from urgency_score buckets:
 *                          urgency >= 0.85  → p1_critical
 *                          urgency >= 0.60  → p2_high
 *                          urgency >= 0.30  → p3_normal
 *                          urgency <  0.30  → p4_low
 *                      An optional polarity penalty bumps a p3 to p2 when
 *                      sentiment is strongly negative (an angry but
 *                      non-urgent customer is still operationally hot).
 *   suggested_category_id  — null. The heuristic doesn't have
 *                            category-classification capability; we leave
 *                            that to AI providers.
 *   suggested_assignee_user_id — null. Assignee suggestions need org-graph
 *                                knowledge (skill tags, current load) that
 *                                this rule-based pass doesn't carry.
 *   confidence       — derived from how many strong signals fired. A ticket
 *                      with 3+ urgency keywords AND a sentiment hit gets
 *                      ~0.85; a ticket with no signals at all gets ~0.20.
 *   reasoning        — human-readable trace of which rules fired, joined
 *                      with " | " — useful for triagers debugging why the
 *                      heuristic suggested p1 on a benign-looking ticket.
 *
 * The keyword lists are intentionally kept small and domain-tilted (auto
 * repair / service tickets); a production install can override the scorer
 * binding to plug in a tuned model.
 */
final class HeuristicTicketTriageScorer implements TicketTriageScorerInterface
{
    /**
     * Tokens that strongly imply urgency. Each match is worth URGENCY_HIT.
     * Tuned for auto-repair shop service tickets — "no_brakes" and
     * "smoking" are way more important than they would be in a generic
     * help-desk corpus.
     */
    private const URGENCY_TOKENS = [
        'outage', 'down', 'fire', 'smoke', 'smoking', 'leak', 'leaking',
        'flood', 'flooding', 'no heat', 'no brakes', 'brake failure',
        'overheat', 'overheating', 'stranded', 'broken down',
        'cannot drive', "can't drive", 'unsafe', 'dangerous', 'collision',
        'accident', 'injury', 'injured', 'hazard',
    ];

    /**
     * Lighter urgency signals — deadline pressure phrases.
     */
    private const DEADLINE_TOKENS = [
        'asap', 'as soon as possible', 'urgent', 'urgently',
        'by today', 'by tomorrow', 'right away', 'immediately',
        'today please', 'this morning', 'this afternoon',
    ];

    private const NEGATIVE_TOKENS = [
        'broken', 'damaged', 'ruined', 'frustrated', 'angry', 'upset',
        'unhappy', 'disappointed', 'terrible', 'awful', 'horrible',
        'worst', 'never again', 'unprofessional',
    ];

    private const POSITIVE_TOKENS = [
        'thanks', 'thank you', 'appreciate', 'great', 'wonderful',
        'fantastic', 'pleased', 'happy', 'grateful', 'love',
    ];

    private const URGENCY_HIT = 0.30;
    private const DEADLINE_HIT = 0.15;
    private const NEGATIVE_HIT = 0.20;
    private const POSITIVE_HIT = 0.20;

    public function score(Ticket $ticket): TriageScore
    {
        $haystack = self::buildHaystack($ticket);

        [$urgency, $urgencyHits, $urgencyTrace] = self::scoreUrgency($haystack);
        [$sentiment, $sentimentTrace] = self::scoreSentiment($haystack);

        $priority = self::priorityFromUrgency($urgency, $sentiment);
        $confidence = self::confidenceFromHits($urgencyHits, $sentimentTrace !== '');
        $reasoning = self::buildReasoning($urgencyTrace, $sentimentTrace, $priority);

        return new TriageScore(
            suggestedPriority: $priority,
            suggestedCategoryId: null,
            suggestedAssigneeUserId: null,
            sentimentScore: $sentiment,
            urgencyScore: $urgency,
            confidence: $confidence,
            reasoning: $reasoning,
        );
    }

    public function label(): string
    {
        return 'heuristic_v1';
    }

    private static function buildHaystack(Ticket $ticket): string
    {
        return strtolower(
            ($ticket->title ?? '') . ' ' . ($ticket->description ?? '')
        );
    }

    /**
     * @return array{0: float, 1: int, 2: string}  [score, hit count, trace]
     */
    private static function scoreUrgency(string $haystack): array
    {
        $score = 0.0;
        $hits = 0;
        $matches = [];
        foreach (self::URGENCY_TOKENS as $tok) {
            if (str_contains($haystack, $tok)) {
                $score += self::URGENCY_HIT;
                $hits++;
                $matches[] = $tok;
            }
        }
        foreach (self::DEADLINE_TOKENS as $tok) {
            if (str_contains($haystack, $tok)) {
                $score += self::DEADLINE_HIT;
                $hits++;
                $matches[] = $tok;
            }
        }
        $score = min(1.0, $score);
        $trace = $matches === []
            ? 'no urgency keywords matched'
            : 'urgency keywords: ' . implode(', ', $matches);
        return [$score, $hits, $trace];
    }

    /**
     * @return array{0: ?float, 1: string}  [score, trace]
     */
    private static function scoreSentiment(string $haystack): array
    {
        $score = 0.0;
        $matches = [];
        foreach (self::NEGATIVE_TOKENS as $tok) {
            if (str_contains($haystack, $tok)) {
                $score -= self::NEGATIVE_HIT;
                $matches[] = '-' . $tok;
            }
        }
        foreach (self::POSITIVE_TOKENS as $tok) {
            if (str_contains($haystack, $tok)) {
                $score += self::POSITIVE_HIT;
                $matches[] = '+' . $tok;
            }
        }
        if ($matches === []) {
            return [null, ''];
        }
        $score = max(-1.0, min(1.0, $score));
        return [$score, 'sentiment tokens: ' . implode(', ', $matches)];
    }

    private static function priorityFromUrgency(float $urgency, ?float $sentiment): string
    {
        if ($urgency >= 0.85) {
            return 'p1_critical';
        }
        if ($urgency >= 0.60) {
            return 'p2_high';
        }
        if ($urgency >= 0.30) {
            // Bump to p2 when the customer is clearly upset — angry but
            // non-urgent tickets still need expedited human review.
            if ($sentiment !== null && $sentiment <= -0.40) {
                return 'p2_high';
            }
            return 'p3_normal';
        }
        return 'p4_low';
    }

    private static function confidenceFromHits(int $urgencyHits, bool $sentimentFired): float
    {
        $base = 0.20;
        $base += min(0.60, $urgencyHits * 0.20);
        if ($sentimentFired) {
            $base += 0.15;
        }
        return min(1.0, round($base, 3));
    }

    private static function buildReasoning(string $urgencyTrace, string $sentimentTrace, string $priority): string
    {
        $parts = [
            $urgencyTrace,
            $sentimentTrace !== '' ? $sentimentTrace : 'no sentiment tokens matched',
            "priority bucket: {$priority}",
        ];
        return implode(' | ', array_filter($parts, static fn(string $p) => $p !== ''));
    }

    /**
     * Public introspection of the suggested-priority vocabulary used by the
     * heuristic. Mirrors TicketTriageSuggestion::SUGGESTED_PRIORITIES so a
     * UI dropdown can render the same values.
     *
     * @return array<int, string>
     */
    public static function priorities(): array
    {
        return TicketTriageSuggestion::SUGGESTED_PRIORITIES;
    }
}
