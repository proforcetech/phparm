-- =============================================================================
-- Phase 13 of docs/woms-expansion-plan.md: Lease & Lifecycle (M3, M4, M5)
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- 1. `asset_leases` (M3)         — lessor-facing lease record attached to a
--                                   site_asset. Tracks payment terms, mileage
--                                   cap (fleet), residual/buyout, and per-row
--                                   alert-sent timestamps so the 90/60/30/0-day
--                                   expiry worker is idempotent.
-- 2. `asset_acquisitions` (M4)    — orchestrator over the front half of the
--                                   lifecycle loop (quote → approval → PO →
--                                   receipt → install WO → activation).
--                                   References, but does not replace, the
--                                   underlying estimate / WO docs.
-- 3. `asset_decommissions` (M5)   — orchestrator over the back half (retire →
--                                   wipe → recover → entitlement update →
--                                   audit → asset.status=retired).
--
-- WHY NO EVENT TABLES
-- -----------------------------------------------------------------------------
-- The existing `audit_logs` table is already polymorphic
-- (entity_type VARCHAR(100), entity_id VARCHAR(100)) and holds the
-- transition history for both state machines as
--   event='acquisition.transitioned' / 'decommission.transitioned'
--   entity_type='asset_acquisition' / 'asset_decommission'
--   context = { "from": <state>, "to": <state>, "actor_id": ..., "note": ... }
-- so we don't need parallel `_events` tables.
--
-- FK TYPE NOTES
-- -----------------------------------------------------------------------------
-- Mirrors the legacy/modern split already in the repo:
--   site_assets.id, customers.id, users.id, workorders.id, estimates.id,
--   sites.id, asset_types.id  →  INT UNSIGNED (legacy)
--   service_lines.id                                 →  BIGINT UNSIGNED (Phase 11)
-- Each new table itself uses BIGINT UNSIGNED for its own id (matches the
-- Phase 11+ convention so the next phase can FK into them as BIGINT).
--
-- IDEMPOTENCY & SAFETY
-- -----------------------------------------------------------------------------
-- All three tables created via CREATE TABLE IF NOT EXISTS. No ALTERs to
-- existing tables, no DROPs, no destructive ops. Re-runs are no-ops.
--
-- ROLLBACK NOTE (manual; never auto-rollback in production)
-- -----------------------------------------------------------------------------
--   DROP TABLE asset_decommissions;
--   DROP TABLE asset_acquisitions;
--   DROP TABLE asset_leases;
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PART A: asset_leases — lessor-facing lease record on a site_asset
-- `lessor_*` is freeform until a vendors table exists; this matches how
-- site_assets.vendor is captured today (also a string).
-- `monthly_payment_cents` is BIGINT to handle high-value capital leases.
-- The four `alert_*_sent_at` columns let the daily expiry worker emit each
-- 90/60/30/0-day milestone exactly once per lease.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_leases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_asset_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    lessor_name VARCHAR(160) NOT NULL,
    lessor_contact VARCHAR(255) NULL,
    lease_number VARCHAR(80) NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    monthly_payment_cents BIGINT UNSIGNED NULL,
    payment_schedule VARCHAR(20) NOT NULL DEFAULT 'monthly',
    mileage_cap INT UNSIGNED NULL,
    current_mileage INT UNSIGNED NULL,
    residual_value_cents BIGINT UNSIGNED NULL,
    buyout_price_cents BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    end_of_lease_decision VARCHAR(20) NULL,
    decision_made_at DATETIME NULL,
    decision_made_by INT UNSIGNED NULL,
    alert_90d_sent_at DATETIME NULL,
    alert_60d_sent_at DATETIME NULL,
    alert_30d_sent_at DATETIME NULL,
    alert_0d_sent_at DATETIME NULL,
    terms TEXT NULL,
    notes TEXT NULL,
    attachments JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_asset_leases_asset (site_asset_id),
    INDEX idx_asset_leases_customer (customer_id),
    INDEX idx_asset_leases_status (status),
    INDEX idx_asset_leases_end_date (end_date),
    INDEX idx_asset_leases_decision_user (decision_made_by),
    CONSTRAINT fk_asset_leases_asset FOREIGN KEY (site_asset_id)
        REFERENCES site_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_leases_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_asset_leases_decision_user FOREIGN KEY (decision_made_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART B: asset_acquisitions — front-half lifecycle orchestrator (M4)
-- Each transition writes an `audit_logs` row keyed by entity_type='asset_acquisition'
-- so the timeline view can render the full history from there.
-- The four document FKs (estimate_id, install_workorder_id, target_site_asset_id)
-- are nullable because they're populated as the workflow progresses; vendor PO
-- info is captured inline since no vendor_pos table exists yet.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_acquisitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NULL,
    service_line_id BIGINT UNSIGNED NULL,
    asset_type_id INT UNSIGNED NULL,
    requested_by_user_id INT UNSIGNED NULL,
    requested_by_portal_user_id INT UNSIGNED NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    title VARCHAR(191) NOT NULL,
    description TEXT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    target_install_date DATE NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    estimate_id INT UNSIGNED NULL,
    customer_approved_at DATETIME NULL,
    customer_approved_by INT UNSIGNED NULL,
    customer_rejected_at DATETIME NULL,
    customer_rejection_reason TEXT NULL,
    vendor_name VARCHAR(160) NULL,
    vendor_po_number VARCHAR(80) NULL,
    vendor_po_total_cents BIGINT UNSIGNED NULL,
    vendor_po_issued_at DATETIME NULL,
    received_at DATETIME NULL,
    received_by INT UNSIGNED NULL,
    install_workorder_id INT UNSIGNED NULL,
    install_scheduled_at DATETIME NULL,
    installed_at DATETIME NULL,
    target_site_asset_id INT UNSIGNED NULL,
    activated_at DATETIME NULL,
    activated_by INT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT UNSIGNED NULL,
    cancelled_reason TEXT NULL,
    last_state_changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_state_changed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_acq_customer (customer_id),
    INDEX idx_acq_site (site_id),
    INDEX idx_acq_service_line (service_line_id),
    INDEX idx_acq_asset_type (asset_type_id),
    INDEX idx_acq_status (status),
    INDEX idx_acq_estimate (estimate_id),
    INDEX idx_acq_install_wo (install_workorder_id),
    INDEX idx_acq_target_asset (target_site_asset_id),
    INDEX idx_acq_requested_by_user (requested_by_user_id),
    INDEX idx_acq_requested_by_portal (requested_by_portal_user_id),
    CONSTRAINT fk_acq_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_acq_site FOREIGN KEY (site_id)
        REFERENCES sites(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_asset_type FOREIGN KEY (asset_type_id)
        REFERENCES asset_types(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_estimate FOREIGN KEY (estimate_id)
        REFERENCES estimates(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_install_wo FOREIGN KEY (install_workorder_id)
        REFERENCES workorders(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_target_asset FOREIGN KEY (target_site_asset_id)
        REFERENCES site_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_requested_by_user FOREIGN KEY (requested_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_requested_by_portal FOREIGN KEY (requested_by_portal_user_id)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_received_by FOREIGN KEY (received_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_activated_by FOREIGN KEY (activated_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_customer_approved_by FOREIGN KEY (customer_approved_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_cancelled_by FOREIGN KEY (cancelled_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_acq_last_state_changed_by FOREIGN KEY (last_state_changed_by)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART C: asset_decommissions — back-half lifecycle orchestrator (M5)
-- Targets an existing site_asset and walks it through wipe → recovery →
-- entitlement → audit → retired. `requires_wipe` is set at initiation time
-- (true for IT/security/POS verticals); when false, the wipe_pending state is
-- skipped at the controller level.
-- The terminal step writes an audit_logs row AND sets site_assets.status='retired'
-- AND populates site_assets.decommissioned_at — handled application-side, not via FK.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_decommissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_asset_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    requested_by_user_id INT UNSIGNED NULL,
    requested_by_portal_user_id INT UNSIGNED NULL,
    requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(40) NOT NULL DEFAULT 'eol',
    notes TEXT NULL,
    requires_wipe TINYINT(1) NOT NULL DEFAULT 0,
    recovery_method VARCHAR(30) NOT NULL DEFAULT 'none',
    status VARCHAR(40) NOT NULL DEFAULT 'initiated',
    wipe_started_at DATETIME NULL,
    wipe_completed_at DATETIME NULL,
    wipe_completed_by INT UNSIGNED NULL,
    wipe_certificate_url VARCHAR(500) NULL,
    recovery_started_at DATETIME NULL,
    recovery_completed_at DATETIME NULL,
    recovery_completed_by INT UNSIGNED NULL,
    recovery_reference VARCHAR(160) NULL,
    recovery_value_cents BIGINT UNSIGNED NULL,
    entitlement_updated_at DATETIME NULL,
    entitlement_updated_by INT UNSIGNED NULL,
    audited_at DATETIME NULL,
    audited_by INT UNSIGNED NULL,
    audit_log_id BIGINT UNSIGNED NULL,
    retired_at DATETIME NULL,
    retired_by INT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancelled_by INT UNSIGNED NULL,
    cancelled_reason TEXT NULL,
    last_state_changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_state_changed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_decomm_asset (site_asset_id),
    INDEX idx_decomm_customer (customer_id),
    INDEX idx_decomm_status (status),
    INDEX idx_decomm_requested_by_user (requested_by_user_id),
    INDEX idx_decomm_requested_by_portal (requested_by_portal_user_id),
    INDEX idx_decomm_audit_log (audit_log_id),
    CONSTRAINT fk_decomm_asset FOREIGN KEY (site_asset_id)
        REFERENCES site_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_decomm_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_decomm_requested_by_user FOREIGN KEY (requested_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_decomm_requested_by_portal FOREIGN KEY (requested_by_portal_user_id)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_decomm_wipe_completed_by FOREIGN KEY (wipe_completed_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_decomm_recovery_completed_by FOREIGN KEY (recovery_completed_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_decomm_entitlement_updated_by FOREIGN KEY (entitlement_updated_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_decomm_audited_by FOREIGN KEY (audited_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_decomm_retired_by FOREIGN KEY (retired_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_decomm_cancelled_by FOREIGN KEY (cancelled_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_decomm_last_state_changed_by FOREIGN KEY (last_state_changed_by)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_decomm_audit_log FOREIGN KEY (audit_log_id)
        REFERENCES audit_logs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
