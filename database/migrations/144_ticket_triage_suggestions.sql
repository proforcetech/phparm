-- Phase 10.5 of docs/expansion-plan.md — AI-driven triage suggestions
-- (sentiment + urgency + suggested priority/category/assignee) on tickets.
--
-- A "triage suggestion" is an analytical output produced by a scorer (the
-- shipped HeuristicTicketTriageScorer is rule-based; an AI provider can be
-- plugged in via TicketTriageScorerInterface) that captures what the model
-- would recommend doing with the ticket. Triage agents review the
-- suggestion and either accept it (which applies the suggested fields to
-- the ticket) or reject it (with a reason).
--
-- Multiple suggestions can exist per ticket over time — re-running the
-- scorer after the ticket evolves produces a new suggestion and marks
-- prior pending ones as 'stale'. This preserves the history for model
-- evaluation while only ever surfacing the latest pending suggestion in
-- the triage queue.
--
-- All FKs guarded on referenced-table existence so the migration is safe on
-- partially-set-up databases.

CREATE TABLE IF NOT EXISTS ticket_triage_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    generated_at DATETIME NOT NULL,
    generated_by VARCHAR(80) NOT NULL DEFAULT 'heuristic_v1',
    suggested_priority VARCHAR(40) NULL,
    suggested_category_id INT NULL,
    suggested_assignee_user_id INT NULL,
    sentiment_score DECIMAL(4,3) NULL,
    urgency_score DECIMAL(4,3) NULL,
    confidence DECIMAL(4,3) NULL,
    reasoning TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    accepted_by_user_id INT NULL,
    accepted_at DATETIME NULL,
    applied_changes TEXT NULL,
    rejected_by_user_id INT NULL,
    rejected_at DATETIME NULL,
    rejection_reason TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Per-ticket suggestion history (newest first via id desc).
SET @idx_t := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'ticket_triage_suggestions'
      AND index_name = 'idx_tts_ticket');
SET @sql := IF(@idx_t = 0,
    'CREATE INDEX idx_tts_ticket ON ticket_triage_suggestions (ticket_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "All pending suggestions awaiting human review" — the triage queue widget.
SET @idx_status := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'ticket_triage_suggestions'
      AND index_name = 'idx_tts_status');
SET @sql := IF(@idx_status = 0,
    'CREATE INDEX idx_tts_status ON ticket_triage_suggestions (status)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Suggestions generated in the last N days" — model-evaluation reports.
SET @idx_gen := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'ticket_triage_suggestions'
      AND index_name = 'idx_tts_generated_at');
SET @sql := IF(@idx_gen = 0,
    'CREATE INDEX idx_tts_generated_at ON ticket_triage_suggestions (generated_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to tickets — CASCADE so deleting a ticket sweeps its suggestion history.
SET @tk_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'tickets');
SET @fk_t := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'ticket_triage_suggestions'
      AND constraint_name = 'fk_tts_ticket');
SET @sql := IF(@tk_table > 0 AND @fk_t = 0,
    'ALTER TABLE ticket_triage_suggestions ADD CONSTRAINT fk_tts_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to ticket_categories for the suggested category — SET NULL because the
-- suggestion is purely a recommendation; deleting a category shouldn't blow
-- away the suggestion record itself, just orphan the pointer.
SET @cat_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'ticket_categories');
SET @fk_c := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'ticket_triage_suggestions'
      AND constraint_name = 'fk_tts_category');
SET @sql := IF(@cat_table > 0 AND @fk_c = 0,
    'ALTER TABLE ticket_triage_suggestions ADD CONSTRAINT fk_tts_category
        FOREIGN KEY (suggested_category_id) REFERENCES ticket_categories(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users for suggested assignee + accept/reject actors — all SET NULL.
SET @users_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_a := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'ticket_triage_suggestions'
      AND constraint_name = 'fk_tts_assignee');
SET @sql := IF(@users_table > 0 AND @fk_a = 0,
    'ALTER TABLE ticket_triage_suggestions ADD CONSTRAINT fk_tts_assignee
        FOREIGN KEY (suggested_assignee_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_acc := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'ticket_triage_suggestions'
      AND constraint_name = 'fk_tts_accepted_by');
SET @sql := IF(@users_table > 0 AND @fk_acc = 0,
    'ALTER TABLE ticket_triage_suggestions ADD CONSTRAINT fk_tts_accepted_by
        FOREIGN KEY (accepted_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_rej := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'ticket_triage_suggestions'
      AND constraint_name = 'fk_tts_rejected_by');
SET @sql := IF(@users_table > 0 AND @fk_rej = 0,
    'ALTER TABLE ticket_triage_suggestions ADD CONSTRAINT fk_tts_rejected_by
        FOREIGN KEY (rejected_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
