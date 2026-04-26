-- Phase 3.5 of docs/expansion-plan.md: Ticket ↔ Workorder links.
--
-- Tickets and workorders are deliberately separate (see migration 108).
-- A ticket may spawn 0..N workorders as field work is scheduled; a single
-- workorder may address 0..N tickets at once.  This join table expresses
-- that many-to-many relationship plus the link semantics.
--
-- link_kind:
--   * 'spawned'    — this WO was created to address the ticket
--   * 'references' — looser association (e.g., the ticket mentions prior work)

CREATE TABLE IF NOT EXISTS ticket_workorder_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    workorder_id INT UNSIGNED NOT NULL,
    link_kind VARCHAR(40) NOT NULL DEFAULT 'spawned',
    linked_by_user_id INT UNSIGNED NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_workorder_links (ticket_id, workorder_id, link_kind),
    INDEX idx_ticket_workorder_links_ticket (ticket_id),
    INDEX idx_ticket_workorder_links_workorder (workorder_id),
    CONSTRAINT fk_ticket_workorder_links_ticket FOREIGN KEY (ticket_id)
        REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_workorder_links_workorder FOREIGN KEY (workorder_id)
        REFERENCES workorders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
