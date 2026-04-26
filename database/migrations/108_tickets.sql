-- Phase 3.1 of docs/expansion-plan.md: Support Desk / Tickets.
--
-- Deliberately separate from `workorders`:
--   * Tickets are inbound requests/incidents. Many never become a WO.
--   * Workorders represent billable/dispatchable field work.
--   * A ticket can spawn 0..N workorders (linked via ticket_workorder_links
--     in Phase 3.5).
--
-- The category catalog is a two-level tree (category → subcategory) so
-- routing rules can target either level without schema pain.
--
-- `ticket_events` is the per-ticket timeline. We keep it distinct from the
-- generic audit_logs table because tickets get high write traffic and users
-- need filtering by event_kind to render the timeline efficiently.

-- 1. Category catalog
CREATE TABLE IF NOT EXISTS ticket_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    division_id INT UNSIGNED NULL,
    parent_id INT UNSIGNED NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    default_priority VARCHAR(20) NOT NULL DEFAULT 'p3_normal',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_categories_code (code),
    INDEX idx_ticket_categories_parent (parent_id),
    INDEX idx_ticket_categories_division (division_id),
    CONSTRAINT fk_ticket_categories_parent FOREIGN KEY (parent_id)
        REFERENCES ticket_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_ticket_categories_division FOREIGN KEY (division_id)
        REFERENCES divisions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tickets table
CREATE TABLE IF NOT EXISTS tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_number VARCHAR(40) NOT NULL,
    company_id INT UNSIGNED NULL,
    site_id INT UNSIGNED NULL,
    division_id INT UNSIGNED NULL,
    asset_id INT UNSIGNED NULL,
    parent_ticket_id INT UNSIGNED NULL,
    category_id INT UNSIGNED NULL,
    subcategory_id INT UNSIGNED NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'p3_normal',
    status VARCHAR(30) NOT NULL DEFAULT 'new',
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    -- Who reported it (either a portal contact or an internal user)
    reported_by_contact_id INT UNSIGNED NULL,
    reported_by_user_id INT UNSIGNED NULL,
    reporter_name VARCHAR(160) NULL,
    reporter_email VARCHAR(255) NULL,
    reporter_phone VARCHAR(40) NULL,
    -- Assignment
    assigned_to_user_id INT UNSIGNED NULL,
    queue_id INT UNSIGNED NULL,
    -- Provenance
    source VARCHAR(40) NOT NULL DEFAULT 'manual',
    source_ref VARCHAR(255) NULL,
    -- Closure (populated in Phase 3.6 at close-time; stubbed here so
    -- 3.6 can be a data-fill-only migration)
    close_reason VARCHAR(60) NULL,
    resolution_code VARCHAR(60) NULL,
    failure_code VARCHAR(60) NULL,
    -- Timestamps
    first_response_at DATETIME NULL,
    resolved_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tickets_number (ticket_number),
    INDEX idx_tickets_company (company_id),
    INDEX idx_tickets_site (site_id),
    INDEX idx_tickets_division (division_id),
    INDEX idx_tickets_asset (asset_id),
    INDEX idx_tickets_status (status),
    INDEX idx_tickets_priority (priority),
    INDEX idx_tickets_assigned (assigned_to_user_id),
    INDEX idx_tickets_queue (queue_id),
    INDEX idx_tickets_category (category_id),
    INDEX idx_tickets_parent (parent_ticket_id),
    CONSTRAINT fk_tickets_parent FOREIGN KEY (parent_ticket_id)
        REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_category FOREIGN KEY (category_id)
        REFERENCES ticket_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_subcategory FOREIGN KEY (subcategory_id)
        REFERENCES ticket_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_asset FOREIGN KEY (asset_id)
        REFERENCES site_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Ticket events (timeline)
CREATE TABLE IF NOT EXISTS ticket_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT UNSIGNED NOT NULL,
    event_kind VARCHAR(60) NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    actor_contact_id INT UNSIGNED NULL,
    message TEXT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 1,
    payload JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ticket_events_ticket (ticket_id, created_at),
    INDEX idx_ticket_events_kind (event_kind),
    CONSTRAINT fk_ticket_events_ticket FOREIGN KEY (ticket_id)
        REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
