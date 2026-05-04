<?php

namespace App\Models;

/**
 * POS heartbeat — Phase 16 / S2 of docs/woms-expansion-plan.md.
 *
 * Append-only time-series row recorded per webhook receipt. The terminal-
 * level "is it up?" answer lives on `pos_terminals.last_seen_at`; this table
 * is for forensics ("did it flap last week?") and reporting.
 *
 * `received_at` is server-authoritative; `reported_at` is whatever the
 * device put in the payload (NULL if absent).
 *
 * See migration 166_security_pos_verticals.sql.
 */
class PosHeartbeat extends BaseModel
{
    public int $id = 0;
    public int $terminal_id = 0;
    public ?string $received_at = null;
    public ?string $reported_at = null;
    public string $status = PosTerminal::HEARTBEAT_ONLINE;
    public ?array $payload = null;
    public ?string $ip_address = null;
    public ?string $created_at = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
