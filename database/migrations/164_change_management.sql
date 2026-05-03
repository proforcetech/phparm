-- =============================================================================
-- Phase 14 / S3 of docs/woms-expansion-plan.md: ITIL change management — RFCs
-- (Request for Change) and CAB (Change Advisory Board) approvals.
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- 1. `change_requests`  — one row per RFC. Carries title, description, change
--                          type (standard/normal/emergency), risk level,
--                          implementation/back-out plans, change window
--                          (planned_start_at / planned_end_at), and the state
--                          machine column (status). Optional links to the
--                          originating ticket, the affected customer, and the
--                          impacted site_asset.
-- 2. `cab_approvals`    — per-CAB-member approval ledger. ChangeRequest moves
--                          out of cab_review only after the CABService
--                          aggregates the votes (configurable quorum).
--
-- STATE MACHINE (enforced by ChangeRequest::TRANSITIONS in the model):
--   draft -> submitted -> cab_review -> {approved, rejected}
--   approved -> scheduled -> in_progress -> {completed, rolled_back}
--   * -> cancelled (any non-terminal state)
--
-- WHY A SEPARATE cab_approvals TABLE
-- -----------------------------------------------------------------------------
-- We need an audit trail of who voted what AND when, plus an optional
-- comment per vote. Storing votes inline on change_requests (e.g. as JSON)
-- would prevent us from joining to users() for the email/name in the
-- approval-status UI. The dedicated table also lets us add a UNIQUE
-- (change_request_id, user_id) so a CAB member can't double-vote.
--
-- WHY NO ON-DELETE CASCADE FROM tickets / site_assets
-- -----------------------------------------------------------------------------
-- An RFC must outlive its originating ticket or impacted asset for audit
-- (compliance frameworks need the full change history). Both columns use
-- ON DELETE SET NULL so the RFC row stays.
--
-- FK TYPE NOTES
-- -----------------------------------------------------------------------------
-- Mirrors migration 163's split: legacy tables (users, customers, tickets,
-- site_assets) are INT UNSIGNED; new tables here use BIGINT UNSIGNED PKs.
--
-- IDEMPOTENCY & SAFETY
-- -----------------------------------------------------------------------------
-- Both tables created via CREATE TABLE IF NOT EXISTS. No ALTERs to existing
-- tables, no DROPs, no destructive ops. Re-runs are no-ops.
--
-- ROLLBACK NOTE (manual; never auto-rollback in production)
-- -----------------------------------------------------------------------------
--   DROP TABLE cab_approvals;
--   DROP TABLE change_requests;
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PART A: change_requests — RFC header + state.
--
-- change_type:   'standard' (pre-approved repeatable), 'normal' (full CAB),
--                'emergency' (expedited CAB / post-implementation review)
-- risk_level:    'low', 'medium', 'high', 'critical'
-- impact_level:  'low', 'medium', 'high', 'critical'
-- status:        see TRANSITIONS comment above
--
-- planned_start_at / planned_end_at define the change window the schedule
-- view paints; actual_start_at / actual_end_at are stamped when the change
-- moves to in_progress and then to completed/rolled_back.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS change_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NULL,
    originating_ticket_id INT UNSIGNED NULL,
    affected_site_asset_id INT UNSIGNED NULL,
    requested_by_user_id INT UNSIGNED NOT NULL,
    assigned_to_user_id INT UNSIGNED NULL,
    title VARCHAR(191) NOT NULL,
    description TEXT NOT NULL,
    change_type VARCHAR(20) NOT NULL DEFAULT 'normal',
    risk_level VARCHAR(20) NOT NULL DEFAULT 'medium',
    impact_level VARCHAR(20) NOT NULL DEFAULT 'medium',
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    implementation_plan TEXT NULL,
    backout_plan TEXT NULL,
    test_plan TEXT NULL,
    business_justification TEXT NULL,
    planned_start_at DATETIME NULL,
    planned_end_at DATETIME NULL,
    actual_start_at DATETIME NULL,
    actual_end_at DATETIME NULL,
    submitted_at DATETIME NULL,
    cab_review_started_at DATETIME NULL,
    decision_at DATETIME NULL,
    decision_by_user_id INT UNSIGNED NULL,
    decision_notes TEXT NULL,
    completed_at DATETIME NULL,
    rolled_back_at DATETIME NULL,
    rollback_reason VARCHAR(255) NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_change_requests_customer (customer_id),
    INDEX idx_change_requests_ticket (originating_ticket_id),
    INDEX idx_change_requests_asset (affected_site_asset_id),
    INDEX idx_change_requests_status (status),
    INDEX idx_change_requests_window (planned_start_at, planned_end_at),
    INDEX idx_change_requests_assigned (assigned_to_user_id),
    INDEX idx_change_requests_requested_by (requested_by_user_id),
    CONSTRAINT fk_change_requests_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_change_requests_ticket FOREIGN KEY (originating_ticket_id)
        REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_change_requests_asset FOREIGN KEY (affected_site_asset_id)
        REFERENCES site_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_change_requests_requested_by FOREIGN KEY (requested_by_user_id)
        REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_change_requests_assigned_to FOREIGN KEY (assigned_to_user_id)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_change_requests_decision_by FOREIGN KEY (decision_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART B: cab_approvals — per-CAB-member vote on an RFC.
--
-- vote: 'approve' | 'reject' | 'abstain'
--
-- The UNIQUE (change_request_id, user_id) prevents double-voting. To revote,
-- the CABService overwrites the row (UPDATE on conflict) — the audit log
-- captures the changes.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cab_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_request_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    vote VARCHAR(20) NOT NULL,
    comment TEXT NULL,
    voted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cab_approvals_change (change_request_id),
    INDEX idx_cab_approvals_user (user_id),
    INDEX idx_cab_approvals_vote (vote),
    UNIQUE KEY uq_cab_approval (change_request_id, user_id),
    CONSTRAINT fk_cab_approvals_change FOREIGN KEY (change_request_id)
        REFERENCES change_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_cab_approvals_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
