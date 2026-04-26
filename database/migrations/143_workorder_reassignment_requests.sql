-- Phase 10.4 of docs/expansion-plan.md — WO primary-tech reassignment flow.
--
-- Two tables co-evolve:
--
--   workorder_reassignment_requests   Tech (or dispatcher acting on tech's
--                                     behalf) files a request to swap the
--                                     primary tech on a work order — "I had
--                                     an emergency" / "this needs an HVAC
--                                     specialist not me" / "I'm double-booked
--                                     across calls". Manager or dispatcher
--                                     approves, picks a replacement at
--                                     fulfilment time, then the request is
--                                     "fulfilled" and the WO row is updated.
--
--   workorder_reassignment_history    Append-only audit log of every actual
--                                     reassignment that hit the workorder
--                                     row, regardless of whether it came
--                                     from a request workflow or a direct
--                                     manager-decided swap. request_id is
--                                     nullable because the direct path
--                                     skips the request stage.
--
-- The request is the workflow / approval layer; the history is the
-- system-of-record for "who has been the primary tech on this WO over time".
-- Splitting them lets us represent "emergency reassignment with no formal
-- request" cleanly while still keeping a single audit trail to query.
--
-- All FKs guarded on referenced-table existence so the migration is safe on
-- partially-set-up databases.

-- ─────────────────────────────────── reassignment requests ────

CREATE TABLE IF NOT EXISTS workorder_reassignment_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT NOT NULL,
    requested_by_user_id INT NOT NULL,
    current_assignee_user_id INT NULL,
    proposed_assignee_user_id INT NULL,
    reassignment_reason VARCHAR(40) NOT NULL DEFAULT 'other',
    reason TEXT NOT NULL,
    urgency VARCHAR(20) NOT NULL DEFAULT 'normal',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    requested_at DATETIME NULL,
    approved_by_user_id INT NULL,
    approved_at DATETIME NULL,
    declined_by_user_id INT NULL,
    declined_at DATETIME NULL,
    cancelled_by_user_id INT NULL,
    cancelled_at DATETIME NULL,
    fulfilled_by_user_id INT NULL,
    fulfilled_at DATETIME NULL,
    new_assignee_user_id INT NULL,
    rejection_reason TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Per-WO list of reassignment requests (newest first via id desc).
SET @idx_wo := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND index_name = 'idx_wrr_workorder');
SET @sql := IF(@idx_wo = 0,
    'CREATE INDEX idx_wrr_workorder ON workorder_reassignment_requests (workorder_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Cross-cutting "all pending reassignment requests" dashboard query.
SET @idx_status := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND index_name = 'idx_wrr_status');
SET @sql := IF(@idx_status = 0,
    'CREATE INDEX idx_wrr_status ON workorder_reassignment_requests (status)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "What requests has user X filed?" — feeds the requestor's dashboard.
SET @idx_req := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND index_name = 'idx_wrr_requested_by');
SET @sql := IF(@idx_req = 0,
    'CREATE INDEX idx_wrr_requested_by ON workorder_reassignment_requests (requested_by_user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to workorders — CASCADE so deleting the WO sweeps its requests too.
SET @wo_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorders');
SET @fk_wo := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND constraint_name = 'fk_wrr_workorder');
SET @sql := IF(@wo_table > 0 AND @fk_wo = 0,
    'ALTER TABLE workorder_reassignment_requests ADD CONSTRAINT fk_wrr_workorder
        FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for requested_by — RESTRICT keeps the audit trail by refusing
-- to delete a user with open requests still pending dispatch decision.
SET @users_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_req_by := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND constraint_name = 'fk_wrr_requested_by');
SET @sql := IF(@users_table > 0 AND @fk_req_by = 0,
    'ALTER TABLE workorder_reassignment_requests ADD CONSTRAINT fk_wrr_requested_by
        FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for current_assignee — SET NULL acceptable; if the prior tech
-- leaves the company we keep the request and just lose the snapshot pointer.
SET @fk_curr := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND constraint_name = 'fk_wrr_current_assignee');
SET @sql := IF(@users_table > 0 AND @fk_curr = 0,
    'ALTER TABLE workorder_reassignment_requests ADD CONSTRAINT fk_wrr_current_assignee
        FOREIGN KEY (current_assignee_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for proposed_assignee — SET NULL.
SET @fk_prop := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND constraint_name = 'fk_wrr_proposed_assignee');
SET @sql := IF(@users_table > 0 AND @fk_prop = 0,
    'ALTER TABLE workorder_reassignment_requests ADD CONSTRAINT fk_wrr_proposed_assignee
        FOREIGN KEY (proposed_assignee_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for new_assignee — the actual replacement chosen at fulfilment.
-- SET NULL acceptable; the history table holds the canonical from→to record.
SET @fk_new := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND constraint_name = 'fk_wrr_new_assignee');
SET @sql := IF(@users_table > 0 AND @fk_new = 0,
    'ALTER TABLE workorder_reassignment_requests ADD CONSTRAINT fk_wrr_new_assignee
        FOREIGN KEY (new_assignee_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for the four lifecycle actors.
SET @fk_appr := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND constraint_name = 'fk_wrr_approved_by');
SET @sql := IF(@users_table > 0 AND @fk_appr = 0,
    'ALTER TABLE workorder_reassignment_requests ADD CONSTRAINT fk_wrr_approved_by
        FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_decl := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND constraint_name = 'fk_wrr_declined_by');
SET @sql := IF(@users_table > 0 AND @fk_decl = 0,
    'ALTER TABLE workorder_reassignment_requests ADD CONSTRAINT fk_wrr_declined_by
        FOREIGN KEY (declined_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_canc := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND constraint_name = 'fk_wrr_cancelled_by');
SET @sql := IF(@users_table > 0 AND @fk_canc = 0,
    'ALTER TABLE workorder_reassignment_requests ADD CONSTRAINT fk_wrr_cancelled_by
        FOREIGN KEY (cancelled_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_full := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_requests'
      AND constraint_name = 'fk_wrr_fulfilled_by');
SET @sql := IF(@users_table > 0 AND @fk_full = 0,
    'ALTER TABLE workorder_reassignment_requests ADD CONSTRAINT fk_wrr_fulfilled_by
        FOREIGN KEY (fulfilled_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ───────────────────────────── reassignment history ────

CREATE TABLE IF NOT EXISTS workorder_reassignment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT NOT NULL,
    request_id INT NULL,
    from_user_id INT NULL,
    to_user_id INT NOT NULL,
    reassigned_by_user_id INT NULL,
    reassigned_at DATETIME NOT NULL,
    reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Per-WO chronological history view (sorted by reassigned_at).
SET @idx_h_wo := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_history'
      AND index_name = 'idx_wrh_workorder');
SET @sql := IF(@idx_h_wo = 0,
    'CREATE INDEX idx_wrh_workorder ON workorder_reassignment_history (workorder_id, reassigned_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Show me every WO that was reassigned away from user X" — the tech's own
-- handoff history view.
SET @idx_h_from := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_history'
      AND index_name = 'idx_wrh_from_user');
SET @sql := IF(@idx_h_from = 0,
    'CREATE INDEX idx_wrh_from_user ON workorder_reassignment_history (from_user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Show me every WO that landed on user X via reassignment."
SET @idx_h_to := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_history'
      AND index_name = 'idx_wrh_to_user');
SET @sql := IF(@idx_h_to = 0,
    'CREATE INDEX idx_wrh_to_user ON workorder_reassignment_history (to_user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to workorders — CASCADE matches the request table.
SET @fk_h_wo := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_history'
      AND constraint_name = 'fk_wrh_workorder');
SET @sql := IF(@wo_table > 0 AND @fk_h_wo = 0,
    'ALTER TABLE workorder_reassignment_history ADD CONSTRAINT fk_wrh_workorder
        FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to request — SET NULL because the history is the system-of-record and
-- can outlive an archived/deleted request.
SET @req_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorder_reassignment_requests');
SET @fk_h_req := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_history'
      AND constraint_name = 'fk_wrh_request');
SET @sql := IF(@req_table > 0 AND @fk_h_req = 0,
    'ALTER TABLE workorder_reassignment_history ADD CONSTRAINT fk_wrh_request
        FOREIGN KEY (request_id) REFERENCES workorder_reassignment_requests(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for from/to/reassigned_by. from→SET NULL (prior tech may leave),
-- to→RESTRICT (cannot delete the current owner mid-job — must reassign first),
-- reassigned_by→SET NULL.
SET @fk_h_from := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_history'
      AND constraint_name = 'fk_wrh_from_user');
SET @sql := IF(@users_table > 0 AND @fk_h_from = 0,
    'ALTER TABLE workorder_reassignment_history ADD CONSTRAINT fk_wrh_from_user
        FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_h_to := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_history'
      AND constraint_name = 'fk_wrh_to_user');
SET @sql := IF(@users_table > 0 AND @fk_h_to = 0,
    'ALTER TABLE workorder_reassignment_history ADD CONSTRAINT fk_wrh_to_user
        FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_h_by := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_reassignment_history'
      AND constraint_name = 'fk_wrh_reassigned_by');
SET @sql := IF(@users_table > 0 AND @fk_h_by = 0,
    'ALTER TABLE workorder_reassignment_history ADD CONSTRAINT fk_wrh_reassigned_by
        FOREIGN KEY (reassigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
