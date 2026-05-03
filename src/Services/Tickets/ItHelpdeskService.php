<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use InvalidArgumentException;

/**
 * IT-helpdesk overlay rules for tickets (Phase 14 / M8 of
 * docs/woms-expansion-plan.md).
 *
 * General-support tickets (auto repair, building, etc.) ignore this entire
 * service — it only fires when a ticket carries an `it_request_kind`
 * (incident / request / question / outage). The service:
 *
 *   1. Validates the IT-specific fields up front (kind whitelist, severity
 *      whitelist, non-negative affected user count).
 *   2. Auto-derives a default severity when the caller didn't pick one,
 *      based on `it_request_kind` and `affected_users_count`. Auto-derivation
 *      never *lowers* an explicit caller choice.
 *   3. Enforces business-impact narrative on P1/P2 — operators MUST justify
 *      the high severity so escalation pages aren't fired blind.
 *   4. Returns the (possibly enriched) field set for the repository to persist.
 *
 * The rules deliberately live in PHP (not the DB) so we can tweak thresholds
 * without a migration. The TicketEscalationService then matches by severity
 * via `match_severity` rules added in migration 162.
 */
class ItHelpdeskService
{
    /**
     * Affected-user thresholds used for severity auto-derivation. Tuned to
     * common ITIL practice: small handful = P3, departmental = P2, site-wide
     * outage = P1. Operators can override by passing severity explicitly.
     */
    public const THRESHOLD_P1_USERS = 200;
    public const THRESHOLD_P2_USERS = 50;

    /**
     * Severities that REQUIRE a `business_impact` narrative on creation.
     * P3/P4 may omit it (most everyday tickets don't need it).
     */
    public const HIGH_SEVERITIES = ['P1', 'P2'];

    /**
     * Severity numeric weight — higher = more severe. Used so auto-derivation
     * can compare severities without hardcoding ordering at every call site.
     */
    private const SEVERITY_WEIGHT = ['P4' => 1, 'P3' => 2, 'P2' => 3, 'P1' => 4];

    /**
     * Validate and enrich an inbound ticket payload. Returns the same array
     * with IT-helpdesk fields normalized; pass-through (no-op) for non-IT
     * tickets. Throws InvalidArgumentException on validation failure so the
     * controller surfaces a 400.
     *
     * @param array<string, mixed> $body
     * @param Ticket|null          $existing  null on create, current row on update
     *
     * @return array<string, mixed>
     */
    public function applyRules(array $body, ?Ticket $existing = null): array
    {
        $effectiveKind = $this->effectiveValue('it_request_kind', $body, $existing);
        if ($effectiveKind === null || $effectiveKind === '') {
            // Non-IT ticket: validate only that no junk slipped into the IT
            // fields (e.g. someone POSTs `severity=foo` without a kind).
            $this->validateOptionalFields($body);
            return $body;
        }

        if (!in_array($effectiveKind, Ticket::IT_REQUEST_KINDS, true)) {
            throw new InvalidArgumentException(
                'it_request_kind must be one of: ' . implode(', ', Ticket::IT_REQUEST_KINDS)
            );
        }

        $this->validateOptionalFields($body);

        $callerSeverity = array_key_exists('severity', $body)
            ? ($body['severity'] === '' ? null : $body['severity'])
            : ($existing?->severity);

        $effectiveAffected = $this->effectiveValue('affected_users_count', $body, $existing);
        $affected = $effectiveAffected === null ? null : (int) $effectiveAffected;

        $derived = $this->deriveSeverity($effectiveKind, $affected);

        $finalSeverity = $callerSeverity !== null
            ? $this->maxSeverity($callerSeverity, $derived)
            : $derived;

        if ($finalSeverity !== null) {
            $body['severity'] = $finalSeverity;
        }

        // Business-impact narrative is mandatory on P1/P2. Check the EFFECTIVE
        // value — caller may rely on a previously-set value during update.
        if ($finalSeverity !== null && in_array($finalSeverity, self::HIGH_SEVERITIES, true)) {
            $impact = $this->effectiveValue('business_impact', $body, $existing);
            if ($impact === null || trim((string) $impact) === '') {
                throw new InvalidArgumentException(
                    "business_impact is required for severity {$finalSeverity} tickets"
                );
            }
        }

        return $body;
    }

    /**
     * Auto-derive a severity from the IT request kind + affected users count.
     * Returns null if there's no signal at all (caller will get a no-op).
     */
    public function deriveSeverity(string $kind, ?int $affectedUsers): ?string
    {
        $base = match ($kind) {
            'outage'   => 'P2',
            'incident' => 'P3',
            'request'  => 'P4',
            'question' => 'P4',
            default    => null,
        };

        if ($affectedUsers !== null) {
            if ($affectedUsers >= self::THRESHOLD_P1_USERS) {
                $base = $this->maxSeverity($base, 'P1');
            } elseif ($affectedUsers >= self::THRESHOLD_P2_USERS) {
                $base = $this->maxSeverity($base, 'P2');
            }
        }

        return $base;
    }

    /**
     * Return the more severe of two severities (nullable). Used so we never
     * accidentally LOWER a severity during auto-derivation.
     */
    private function maxSeverity(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        $weightA = self::SEVERITY_WEIGHT[$a] ?? 0;
        $weightB = self::SEVERITY_WEIGHT[$b] ?? 0;
        return $weightA >= $weightB ? $a : $b;
    }

    /**
     * Resolve the effective value of a field across the body (preferred, even
     * when explicitly null) and the existing row (fallback).
     *
     * @param array<string, mixed> $body
     */
    private function effectiveValue(string $field, array $body, ?Ticket $existing): mixed
    {
        if (array_key_exists($field, $body)) {
            return $body[$field];
        }
        return $existing?->{$field} ?? null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validateOptionalFields(array $body): void
    {
        if (
            array_key_exists('severity', $body)
            && $body['severity'] !== null
            && $body['severity'] !== ''
            && !in_array($body['severity'], Ticket::SEVERITIES, true)
        ) {
            throw new InvalidArgumentException(
                'severity must be one of: ' . implode(', ', Ticket::SEVERITIES)
            );
        }
        if (
            array_key_exists('affected_users_count', $body)
            && $body['affected_users_count'] !== null
            && (int) $body['affected_users_count'] < 0
        ) {
            throw new InvalidArgumentException('affected_users_count must be non-negative');
        }
    }
}
