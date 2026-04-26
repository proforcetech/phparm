-- Phase 6.5 of docs/expansion-plan.md — tie MessageThreads to tickets
-- and work orders so the customer portal can join the conversation on
-- their own requests.
--
-- Design decisions:
--   * One optional ticket_id + one optional workorder_id column (rather
--     than a polymorphic join table) keeps the hot-path read a single
--     indexed lookup. Threads can be linked to zero, one, or both (a
--     ticket that spawned a workorder may legitimately hold the same
--     conversation under both views).
--   * `is_internal` on message_messages lets staff drop private notes
--     on a thread that the portal user must NEVER see. Default 0 so
--     pre-existing messages stay portal-visible.
--   * `portal_account_id` on message_messages tags the source of a
--     portal-originated post. Staff posts leave this NULL. This is an
--     audit breadcrumb — the sender_id on portal posts is still the
--     portal user's user_id (MessageThread participants are users),
--     this column just records WHICH portal account session authored
--     the post, distinct from the underlying user.
--   * All four columns are NULLable + IF NOT EXISTS-style ALTERs so
--     this migration is safe to re-run.

-- 1. message_threads ← ticket_id
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'message_threads'
      AND column_name = 'ticket_id'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE message_threads ADD COLUMN ticket_id INT UNSIGNED NULL AFTER subject',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. message_threads ← workorder_id
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'message_threads'
      AND column_name = 'workorder_id'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE message_threads ADD COLUMN workorder_id INT UNSIGNED NULL AFTER ticket_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Index for listThreadsForTicket
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'message_threads'
      AND index_name = 'idx_message_threads_ticket'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE message_threads ADD INDEX idx_message_threads_ticket (ticket_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Index for listThreadsForWorkorder
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'message_threads'
      AND index_name = 'idx_message_threads_workorder'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE message_threads ADD INDEX idx_message_threads_workorder (workorder_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5. FK: message_threads.ticket_id → tickets.id (only if both tables
--    exist and no FK yet). ON DELETE SET NULL so that soft-deleting a
--    ticket (if we ever do) doesn't cascade the conversation history.
SET @has_fk := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'message_threads'
      AND constraint_name = 'fk_message_threads_ticket'
);
SET @tickets_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'tickets'
);
SET @sql := IF(@has_fk = 0 AND @tickets_exists = 1,
    'ALTER TABLE message_threads ADD CONSTRAINT fk_message_threads_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 6. FK: message_threads.workorder_id → workorders.id
SET @has_fk := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'message_threads'
      AND constraint_name = 'fk_message_threads_workorder'
);
SET @wo_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorders'
);
SET @sql := IF(@has_fk = 0 AND @wo_exists = 1,
    'ALTER TABLE message_threads ADD CONSTRAINT fk_message_threads_workorder FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 7. message_messages ← is_internal
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'message_messages'
      AND column_name = 'is_internal'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE message_messages ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0 AFTER body',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 8. message_messages ← portal_account_id (audit breadcrumb)
SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'message_messages'
      AND column_name = 'portal_account_id'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE message_messages ADD COLUMN portal_account_id INT UNSIGNED NULL AFTER is_internal',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 9. Index to filter internal-vs-external per thread quickly.
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'message_messages'
      AND index_name = 'idx_message_messages_thread_internal'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE message_messages ADD INDEX idx_message_messages_thread_internal (thread_id, is_internal)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 10. FK: message_messages.portal_account_id → portal_accounts.id
SET @has_fk := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'message_messages'
      AND constraint_name = 'fk_message_messages_portal_account'
);
SET @pa_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'portal_accounts'
);
SET @sql := IF(@has_fk = 0 AND @pa_exists = 1,
    'ALTER TABLE message_messages ADD CONSTRAINT fk_message_messages_portal_account FOREIGN KEY (portal_account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
