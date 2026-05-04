<?php

namespace App\Models;

/**
 * POS terminal device — Phase 16 / S2 of docs/woms-expansion-plan.md.
 *
 * Registers a POS device, owns its HMAC `shared_secret` (stored hex, used to
 * verify incoming heartbeat webhooks), and the per-terminal staleness
 * thresholds plus `last_seen_at` denorm so the dashboard and stale-sweeper
 * don't have to scan `pos_heartbeats` on the hot path.
 *
 * `last_alert_ticket_id` references the most recent stale-alert ticket so
 * the sweeper doesn't re-fire while it's still open. Cleared when a fresh
 * heartbeat lands.
 *
 * See migration 166_security_pos_verticals.sql.
 */
class PosTerminal extends BaseModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_DECOMMISSIONED = 'decommissioned';

    public const ALLOWED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_DISABLED,
        self::STATUS_DECOMMISSIONED,
    ];

    public const HEARTBEAT_ONLINE = 'online';
    public const HEARTBEAT_OFFLINE = 'offline';
    public const HEARTBEAT_DEGRADED = 'degraded';
    public const HEARTBEAT_ERROR = 'error';

    public const ALLOWED_HEARTBEAT_STATUSES = [
        self::HEARTBEAT_ONLINE,
        self::HEARTBEAT_OFFLINE,
        self::HEARTBEAT_DEGRADED,
        self::HEARTBEAT_ERROR,
    ];

    public int $id = 0;
    public int $customer_id = 0;
    public int $site_id = 0;
    public ?int $site_asset_id = null;
    public string $terminal_code = '';
    public ?string $name = null;
    public ?string $vendor = null;
    public ?string $model = null;
    public ?string $serial_number = null;
    public string $shared_secret = '';
    public int $heartbeat_interval_seconds = 60;
    public int $stale_after_seconds = 300;
    public string $status = self::STATUS_ACTIVE;
    public ?string $last_seen_at = null;
    public ?string $last_status = null;
    public ?int $last_alert_ticket_id = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * True if last_seen_at is older than stale_after_seconds (or never seen).
     */
    public function isStale(?string $now = null): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        if ($this->last_seen_at === null || $this->last_seen_at === '') {
            return true;
        }
        $now ??= date('Y-m-d H:i:s');
        $delta = strtotime($now) - strtotime($this->last_seen_at);
        if ($delta === false) {
            return false;
        }
        return $delta >= $this->stale_after_seconds;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
