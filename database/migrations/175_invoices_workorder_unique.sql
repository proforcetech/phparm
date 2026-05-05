-- =============================================================================
-- Enforce one-invoice-per-workorder at the database level.
--
-- WHY
-- WorkorderService::transition auto-creates an invoice when a workorder is
-- marked COMPLETED. Two concurrent completers (e.g. job auto-roll-up firing
-- at the same moment as a manual PATCH) would each pass the
-- "no invoice yet?" check and both insert. A UNIQUE index makes the second
-- INSERT fail with SQLSTATE 23000, which the service catches and resolves
-- by returning the winner's invoice instead of raising.
--
-- NULL HANDLING
-- MySQL/MariaDB UNIQUE indexes permit multiple NULL values, so manual
-- invoices (workorder_id IS NULL) remain unaffected — only invoices that
-- claim a specific workorder must be unique.
--
-- WHY THE TWO-STEP DANCE
-- Older installs have a non-unique idx_invoice_workorder, and the FK
-- fk_invoice_workorder uses it as its supporting index. Dropping that
-- index outright fails with errno 1553 because the FK would be left with
-- no index to lean on. We add a new UNIQUE index first (under a different
-- name so it can coexist), and only then drop the legacy non-unique one —
-- by that point the FK has a fresh index to use.
--
-- Fresh installs already have UNIQUE KEY idx_invoice_workorder via
-- install.sql; both steps below detect that and become no-ops.
--
-- IDEMPOTENCY
-- Both steps are guarded via information_schema. Re-runs are safe regardless
-- of which combination of indexes exists.
-- =============================================================================

-- 1) Ensure SOME unique index covers workorder_id. Accept either the legacy
--    name (fresh installs) or the new name (post-upgrade installs).
SET @has_unique_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'invoices'
      AND column_name = 'workorder_id'
      AND seq_in_index = 1
      AND non_unique = 0
);
SET @add_idx_sql := IF(@has_unique_idx = 0,
    'ALTER TABLE invoices ADD UNIQUE INDEX uniq_invoice_workorder (workorder_id)',
    'SELECT 1');
PREPARE add_idx_stmt FROM @add_idx_sql;
EXECUTE add_idx_stmt;
DEALLOCATE PREPARE add_idx_stmt;

-- 2) Now that a unique index is guaranteed to exist, drop the legacy
--    non-unique idx_invoice_workorder if it's still around. The FK falls
--    back to the new index automatically.
SET @has_legacy_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'invoices'
      AND index_name = 'idx_invoice_workorder'
      AND non_unique = 1
);
SET @drop_idx_sql := IF(@has_legacy_idx > 0,
    'ALTER TABLE invoices DROP INDEX idx_invoice_workorder',
    'SELECT 1');
PREPARE drop_idx_stmt FROM @drop_idx_sql;
EXECUTE drop_idx_stmt;
DEALLOCATE PREPARE drop_idx_stmt;
