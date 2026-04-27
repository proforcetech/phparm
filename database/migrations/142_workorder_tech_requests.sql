-- Phase 10.3 of docs/expansion-plan.md — additional-tech requests on a WO.
--
-- Two tables co-evolve:
--
--   workorder_tech_requests   The primary tech files a request for additional
--                             help on their work order — "I need a second set
--                             of hands" / "I need an HVAC specialist" / "I
--                             want a shadow on this for training". Manager or
--                             dispatcher approves, then assigns an actual
--                             user, then the request is "fulfilled".
--
--   workorder_additional_techs The persisted secondary-tech assignments on
--                             the WO. The primary tech remains on
--                             workorders.assigned_technician_id; this table
--                             tracks the additional helpers. request_id is
--                             nullable because a manager can drop an extra
--                             tech onto a WO directly without a formal
--                             request.
--
-- Soft-removal pattern on workorder_additional_techs (removed_at NULL when
-- active) so the history of who worked the WO is preserved for time-tracking
-- and accountability rather than disappearing on un-assign.
--
-- All FKs guarded on referenced-table existence so the migration is safe on
-- partially-set-up databases.

-- ───────────────────────────────────────────── tech requests ────

CREATE TABLE IF NOT EXISTS workorder_tech_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    requested_by_user_id INT UNSIGNED NOT NULL,
    request_type VARCHAR(40) NOT NULL DEFAULT 'extra_hands',
    reason TEXT NOT NULL,
    estimated_hours DECIMAL(8,2) NULL,
    skills_needed VARCHAR(255) NULL,
    urgency VARCHAR(20) NOT NULL DEFAULT 'normal',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    requested_at DATETIME NULL,
    approved_by_user_id INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    declined_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    fulfilled_at DATETIME NULL,
    fulfilled_user_id INT UNSIGNED NULL,
    rejection_reason TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Per-WO list of requests (sorted by id desc — newest first).
SET @idx_wo := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_tech_requests'
      AND index_name = 'idx_wtr_workorder');
SET @sql := IF(@idx_wo = 0,
    'CREATE INDEX idx_wtr_workorder ON workorder_tech_requests (workorder_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "All pending requests" dashboard query.
SET @idx_status := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_tech_requests'
      AND index_name = 'idx_wtr_status');
SET @sql := IF(@idx_status = 0,
    'CREATE INDEX idx_wtr_status ON workorder_tech_requests (status)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "What requests has user X filed?" — used by the requestor's dashboard.
SET @idx_req := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_tech_requests'
      AND index_name = 'idx_wtr_requested_by');
SET @sql := IF(@idx_req = 0,
    'CREATE INDEX idx_wtr_requested_by ON workorder_tech_requests (requested_by_user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to workorders — CASCADE so deleting the WO sweeps its requests too.
SET @wo_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorders');
SET @fk_wo := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_tech_requests'
      AND constraint_name = 'fk_wtr_workorder');
SET @sql := IF(@wo_table > 0 AND @fk_wo = 0,
    'ALTER TABLE workorder_tech_requests ADD CONSTRAINT fk_wtr_workorder
        FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for requested_by — SET NULL is unsafe (we'd lose authorship);
-- RESTRICT keeps the audit trail by refusing to delete a user with open
-- requests they still need to handle.
SET @users_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_req := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_tech_requests'
      AND constraint_name = 'fk_wtr_requested_by');
SET @sql := IF(@users_table > 0 AND @fk_req = 0,
    'ALTER TABLE workorder_tech_requests ADD CONSTRAINT fk_wtr_requested_by
        FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for approver — SET NULL acceptable; if approver leaves we keep
-- the request and just lose the "who approved" pointer.
SET @fk_appr := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_tech_requests'
      AND constraint_name = 'fk_wtr_approved_by');
SET @sql := IF(@users_table > 0 AND @fk_appr = 0,
    'ALTER TABLE workorder_tech_requests ADD CONSTRAINT fk_wtr_approved_by
        FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for fulfilled_user_id — SET NULL likewise.
SET @fk_full := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_tech_requests'
      AND constraint_name = 'fk_wtr_fulfilled_user');
SET @sql := IF(@users_table > 0 AND @fk_full = 0,
    'ALTER TABLE workorder_tech_requests ADD CONSTRAINT fk_wtr_fulfilled_user
        FOREIGN KEY (fulfilled_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ──────────────────────────────────────── additional techs on WO ────

CREATE TABLE IF NOT EXISTS workorder_additional_techs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    request_id INT UNSIGNED NULL,
    tech_role VARCHAR(40) NOT NULL DEFAULT 'secondary_tech',
    added_at DATETIME NULL,
    added_by_user_id INT UNSIGNED NULL,
    removed_at DATETIME NULL,
    removed_by_user_id INT UNSIGNED NULL,
    removal_reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Per-WO list of additional techs (active first, history available).
SET @idx_wo2 := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_additional_techs'
      AND index_name = 'idx_wat_workorder');
SET @sql := IF(@idx_wo2 = 0,
    'CREATE INDEX idx_wat_workorder ON workorder_additional_techs (workorder_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "What WOs is user X assigned to as a secondary tech?" dashboard view.
SET @idx_user := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_additional_techs'
      AND index_name = 'idx_wat_user');
SET @sql := IF(@idx_user = 0,
    'CREATE INDEX idx_wat_user ON workorder_additional_techs (user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Show only currently-active assignments" — partial-indexable via removed_at IS NULL.
SET @idx_active := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_additional_techs'
      AND index_name = 'idx_wat_active');
SET @sql := IF(@idx_active = 0,
    'CREATE INDEX idx_wat_active ON workorder_additional_techs (workorder_id, removed_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to workorders — CASCADE matches the request table.
SET @fk_wo2 := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_additional_techs'
      AND constraint_name = 'fk_wat_workorder');
SET @sql := IF(@wo_table > 0 AND @fk_wo2 = 0,
    'ALTER TABLE workorder_additional_techs ADD CONSTRAINT fk_wat_workorder
        FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users — RESTRICT so deleting a tech with open WO assignments fails
-- loudly (operator must remove them first).
SET @fk_user := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_additional_techs'
      AND constraint_name = 'fk_wat_user');
SET @sql := IF(@users_table > 0 AND @fk_user = 0,
    'ALTER TABLE workorder_additional_techs ADD CONSTRAINT fk_wat_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to request — SET NULL because the additional-tech assignment can outlive
-- the request record (requests get archived; the assignment is the live state).
SET @req_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorder_tech_requests');
SET @fk_req2 := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_additional_techs'
      AND constraint_name = 'fk_wat_request');
SET @sql := IF(@req_table > 0 AND @fk_req2 = 0,
    'ALTER TABLE workorder_additional_techs ADD CONSTRAINT fk_wat_request
        FOREIGN KEY (request_id) REFERENCES workorder_tech_requests(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for added_by + removed_by — SET NULL.
SET @fk_added := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_additional_techs'
      AND constraint_name = 'fk_wat_added_by');
SET @sql := IF(@users_table > 0 AND @fk_added = 0,
    'ALTER TABLE workorder_additional_techs ADD CONSTRAINT fk_wat_added_by
        FOREIGN KEY (added_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_removed := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_additional_techs'
      AND constraint_name = 'fk_wat_removed_by');
SET @sql := IF(@users_table > 0 AND @fk_removed = 0,
    'ALTER TABLE workorder_additional_techs ADD CONSTRAINT fk_wat_removed_by
        FOREIGN KEY (removed_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
