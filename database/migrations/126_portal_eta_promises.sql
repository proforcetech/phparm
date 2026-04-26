-- Phase 6.7 of docs/expansion-plan.md — portal ETA tracking.
--
-- Staff publishes "we expect to be at/finish this between X and Y" against
-- a ticket or workorder; the portal shows the current promise plus the
-- history of revisions so customers can see when an ETA slipped and why.
--
-- Design:
--   * Polymorphic (entity_type, entity_id) spans both ticket and
--     workorder surfaces with one table and one service code path
--     (mirrors 125_portal_uploads.sql).
--   * Append-only chain. Creating a new promise does NOT overwrite the
--     current row — it inserts a new row, marks the previous row
--     superseded_at=now() + superseded_by_id=new.id, and the UI queries
--     "latest where superseded_at IS NULL" for the current ETA. This
--     gives customers a visible slip history for free.
--   * Cancelling a promise stamps superseded_at without inserting a
--     successor — "we no longer have an estimate" is distinguishable
--     from "here's the new estimate".
--   * source enum tracks provenance: manual (staff-typed), system
--     (auto-computed from a schedule), traffic_api (future integration
--     with TrafficAwareEtaService for roadside dispatch). confidence
--     0-100 lets the portal render a confidence band.
--   * note is a short, customer-visible reason for the revision ("parts
--     delayed 2 days", "tech arrived early") — rendered inline in the
--     timeline.
--
-- Scope filters:
--   * company_id denormalized from the owning entity so the hot-path
--     "list ETAs for this entity in this company" read is a single
--     indexed lookup without a join back to tickets/customers.
--   * (entity_type, entity_id, superseded_at) composite = the canonical
--     "current ETA on ticket 42" (superseded_at IS NULL) and history
--     reads share one index.

CREATE TABLE IF NOT EXISTS portal_eta_promises (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    window_start_at DATETIME NOT NULL,
    window_end_at DATETIME NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'manual',
    confidence TINYINT UNSIGNED NULL,
    note VARCHAR(1000) NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    superseded_at TIMESTAMP NULL DEFAULT NULL,
    superseded_by_id INT UNSIGNED NULL,
    INDEX idx_portal_eta_entity_current (entity_type, entity_id, superseded_at),
    INDEX idx_portal_eta_entity_history (entity_type, entity_id, created_at),
    INDEX idx_portal_eta_company (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Self-referential FK for the supersede chain. Nullable because the
-- latest row in the chain has no successor. ON DELETE SET NULL so a
-- pruned row doesn't cascade and wipe the chain.
SET @has_fk_supersede := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'portal_eta_promises'
      AND constraint_name = 'fk_portal_eta_supersede'
);
SET @fk_supersede_sql := IF(
    @has_fk_supersede = 0,
    'ALTER TABLE portal_eta_promises ADD CONSTRAINT fk_portal_eta_supersede FOREIGN KEY (superseded_by_id) REFERENCES portal_eta_promises (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE fk_supersede_stmt FROM @fk_supersede_sql;
EXECUTE fk_supersede_stmt;
DEALLOCATE PREPARE fk_supersede_stmt;
