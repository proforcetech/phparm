<?php

namespace App\Models;

/**
 * Polymorphic config-change audit row — Phase 16 / S1 + S2 of
 * docs/woms-expansion-plan.md.
 *
 * Append-only ledger that captures every authoritative change against a
 * credential, door assignment, access schedule, or POS terminal. Carries
 * before/after JSON snapshots so an auditor can reconstruct the history
 * without replaying app logs. Writes funnel through ProgrammingLogService
 * so the (target_type, target_id) pairing stays consistent.
 *
 * See migration 166_security_pos_verticals.sql.
 */
class ProgrammingLog extends BaseModel
{
    public const TARGET_CREDENTIAL = 'credential';
    public const TARGET_CREDENTIAL_DOOR = 'credential_door';
    public const TARGET_ACCESS_SCHEDULE = 'access_schedule';
    public const TARGET_POS_TERMINAL = 'pos_terminal';
    public const TARGET_DOOR = 'door';

    public const ALLOWED_TARGET_TYPES = [
        self::TARGET_CREDENTIAL,
        self::TARGET_CREDENTIAL_DOOR,
        self::TARGET_ACCESS_SCHEDULE,
        self::TARGET_POS_TERMINAL,
        self::TARGET_DOOR,
    ];

    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_ASSIGNED = 'assigned';
    public const ACTION_REVOKED = 'revoked';
    public const ACTION_ENABLED = 'enabled';
    public const ACTION_DISABLED = 'disabled';
    public const ACTION_CONFIG_CHANGED = 'config_changed';
    public const ACTION_WEBHOOK_RECEIVED = 'webhook_received';
    public const ACTION_SWEEP_ALERT = 'sweep_alert';

    public int $id = 0;
    public int $customer_id = 0;
    public ?int $site_id = null;
    public string $target_type = '';
    public int $target_id = 0;
    public string $action = '';
    public ?string $summary = null;
    public ?array $before_snapshot = null;
    public ?array $after_snapshot = null;
    public ?string $programmed_at = null;
    public ?int $programmed_by_user_id = null;
    public ?string $programmed_by_external = null;
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
