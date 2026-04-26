-- Phase 3.4 of docs/expansion-plan.md: Ticket escalation rules.
--
-- Escalation rules are evaluated by a cron job (bin/cron/ticket-escalation.php).
-- For each open ticket, each active rule that matches (scope filters)
-- AND whose trigger fires (stale_minutes, sla_breach_imminent, sla_breached)
-- AND whose cooldown window has elapsed applies its action
-- (reassign_queue_id, raise_priority_to, plus always-log a timeline event).
--
-- A separate ticket_escalation_events table tracks when a rule last fired
-- on each ticket so we can enforce cooldown_minutes.

CREATE TABLE IF NOT EXISTS ticket_escalation_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    -- Trigger: one of 'stale' | 'sla_breach_imminent' | 'sla_breached'
    trigger_kind VARCHAR(40) NOT NULL,
    -- For 'stale': minutes of inactivity; for 'sla_breach_imminent': seconds remaining threshold
    trigger_minutes INT UNSIGNED NULL,
    trigger_seconds INT UNSIGNED NULL,
    trigger_sla_kind VARCHAR(20) NULL,
    -- Scope filters (all NULL = apply to every open ticket)
    match_division_id INT UNSIGNED NULL,
    match_queue_id INT UNSIGNED NULL,
    match_priority VARCHAR(20) NULL,
    match_status VARCHAR(30) NULL,
    -- Actions
    action_reassign_queue_id INT UNSIGNED NULL,
    action_raise_priority_to VARCHAR(20) NULL,
    action_notify_user_id INT UNSIGNED NULL,
    -- Prevent re-firing on the same ticket within this window
    cooldown_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ticket_escalation_active (is_active),
    CONSTRAINT fk_ticket_esc_rules_division FOREIGN KEY (match_division_id)
        REFERENCES divisions(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_esc_rules_queue FOREIGN KEY (match_queue_id)
        REFERENCES ticket_queues(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_esc_rules_action_queue FOREIGN KEY (action_reassign_queue_id)
        REFERENCES ticket_queues(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_escalation_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    rule_id INT UNSIGNED NOT NULL,
    fired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actions_applied JSON NULL,
    INDEX idx_ticket_esc_events_ticket (ticket_id, fired_at),
    INDEX idx_ticket_esc_events_rule (rule_id, fired_at),
    CONSTRAINT fk_ticket_esc_events_ticket FOREIGN KEY (ticket_id)
        REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_esc_events_rule FOREIGN KEY (rule_id)
        REFERENCES ticket_escalation_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
