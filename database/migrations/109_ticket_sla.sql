-- Phase 3.2 of docs/expansion-plan.md: pausable SLA clocks for tickets.
--
-- Two tables:
--   * ticket_sla_policies — reusable SLA targets by (division, priority).
--     One row defines the 3 clocks (response/arrival/resolution) in minutes.
--   * ticket_sla_clocks   — live per-ticket per-kind clock state with
--     accumulated seconds so pause/resume can be stored durably.
--
-- Accumulation model: whenever we pause or stop, we add the seconds since
-- `last_started_at` into `accumulated_seconds` and update last_started_at.
-- On resume we reset last_started_at = NOW(). `elapsed` on a running clock
-- is `accumulated_seconds + (NOW() - last_started_at)`. This lets a nightly
-- cron detect breaches without a backlog of timer writes.

CREATE TABLE IF NOT EXISTS ticket_sla_policies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    division_id INT UNSIGNED NULL,
    priority VARCHAR(20) NOT NULL,
    name VARCHAR(160) NOT NULL,
    response_minutes INT UNSIGNED NULL,
    arrival_minutes INT UNSIGNED NULL,
    resolution_minutes INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_sla_policies_scope (division_id, priority),
    INDEX idx_ticket_sla_policies_active (is_active),
    CONSTRAINT fk_ticket_sla_policies_division FOREIGN KEY (division_id)
        REFERENCES divisions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_sla_clocks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    policy_id INT UNSIGNED NULL,
    clock_kind VARCHAR(20) NOT NULL,
    target_minutes INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    accumulated_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    last_started_at DATETIME NULL,
    paused_at DATETIME NULL,
    stopped_at DATETIME NULL,
    breached_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_sla_clocks_kind (ticket_id, clock_kind),
    INDEX idx_ticket_sla_clocks_status (status),
    INDEX idx_ticket_sla_clocks_breached (breached_at),
    CONSTRAINT fk_ticket_sla_clocks_ticket FOREIGN KEY (ticket_id)
        REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_sla_clocks_policy FOREIGN KEY (policy_id)
        REFERENCES ticket_sla_policies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
