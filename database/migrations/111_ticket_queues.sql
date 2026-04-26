-- Phase 3.4 of docs/expansion-plan.md: Ticket queues (per-dept / per-division).
--
-- Queues are the "mailbox" a ticket sits in before an individual picks it up.
-- Assigning to a queue is the routing output when no individual assignee
-- is known (e.g., "Electrical team — Nashville").
--
-- tickets.queue_id existed in migration 108 as a raw INT; this migration
-- back-fills the foreign key.  Idempotent — re-runs are no-ops.

CREATE TABLE IF NOT EXISTS ticket_queues (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    division_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_queues_code (code),
    INDEX idx_ticket_queues_division (division_id),
    CONSTRAINT fk_ticket_queues_division FOREIGN KEY (division_id)
        REFERENCES divisions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add FK on tickets.queue_id now that the target table exists.  Guard with
-- information_schema.key_column_usage so re-runs are safe.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.referential_constraints
     WHERE constraint_schema = DATABASE()
       AND table_name = 'tickets'
       AND constraint_name = 'fk_tickets_queue'
);
SET @sql := IF(
    @fk_exists = 0,
    'ALTER TABLE tickets ADD CONSTRAINT fk_tickets_queue FOREIGN KEY (queue_id) REFERENCES ticket_queues(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
