-- Add branch support for workorders

SET @has_branch_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'branch_id'
);

SET @branch_sql := IF(@has_branch_id = 0,
    'ALTER TABLE workorders ADD COLUMN branch_id INT UNSIGNED NULL AFTER vehicle_id',
    'SELECT 1');

PREPARE branch_stmt FROM @branch_sql;
EXECUTE branch_stmt;
DEALLOCATE PREPARE branch_stmt;

SET @has_branch_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND index_name = 'idx_workorder_branch'
);

SET @branch_idx_sql := IF(@has_branch_idx = 0,
    'ALTER TABLE workorders ADD INDEX idx_workorder_branch (branch_id)',
    'SELECT 1');

PREPARE branch_idx_stmt FROM @branch_idx_sql;
EXECUTE branch_idx_stmt;
DEALLOCATE PREPARE branch_idx_stmt;
