-- Add display_order column to estimate_jobs table
-- This column is used to maintain the order of jobs within an estimate

-- Check and add display_order column
SET @has_display_order := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estimate_jobs' AND column_name = 'display_order'
);

SET @display_order_sql := IF(@has_display_order = 0,
    'ALTER TABLE estimate_jobs ADD COLUMN display_order INT NOT NULL DEFAULT 0 AFTER total', 'SELECT 1');

PREPARE display_order_stmt FROM @display_order_sql;
EXECUTE display_order_stmt;
DEALLOCATE PREPARE display_order_stmt;

-- Add index for efficient ordering queries
SET @has_display_order_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'estimate_jobs' AND index_name = 'idx_estimate_jobs_display_order'
);

SET @display_order_idx_sql := IF(@has_display_order_idx = 0,
    'CREATE INDEX idx_estimate_jobs_display_order ON estimate_jobs(estimate_id, display_order)', 'SELECT 1');

PREPARE display_order_idx_stmt FROM @display_order_idx_sql;
EXECUTE display_order_idx_stmt;
DEALLOCATE PREPARE display_order_idx_stmt;
