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
-- IDEMPOTENCY
-- Guarded via information_schema. If a UNIQUE index with this name already
-- exists, the migration is a no-op. The legacy non-unique index is dropped
-- by name only when present, so re-runs are safe.
-- =============================================================================

-- 1) Drop the legacy non-unique index, if present.
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

-- 2) Add the UNIQUE index. Use the same name so existing code that touches
--    the index by name keeps working.
SET @has_unique_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'invoices'
      AND index_name = 'idx_invoice_workorder'
      AND non_unique = 0
);
SET @add_idx_sql := IF(@has_unique_idx = 0,
    'ALTER TABLE invoices ADD UNIQUE INDEX idx_invoice_workorder (workorder_id)',
    'SELECT 1');
PREPARE add_idx_stmt FROM @add_idx_sql;
EXECUTE add_idx_stmt;
DEALLOCATE PREPARE add_idx_stmt;
