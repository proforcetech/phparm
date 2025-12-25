SET @has_rejection_reason := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'estimates'
      AND column_name = 'rejection_reason'
);

SET @rejection_reason_sql := IF(
    @has_rejection_reason = 0,
    'ALTER TABLE estimates ADD COLUMN rejection_reason TEXT NULL AFTER customer_notes',
    'SELECT 1'
);

PREPARE rejection_reason_stmt FROM @rejection_reason_sql;
EXECUTE rejection_reason_stmt;
DEALLOCATE PREPARE rejection_reason_stmt;

SET @has_parent_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'estimates'
      AND column_name = 'parent_id'
);

SET @parent_id_sql := IF(
    @has_parent_id = 0,
    'ALTER TABLE estimates ADD COLUMN parent_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);

PREPARE parent_id_stmt FROM @parent_id_sql;
EXECUTE parent_id_stmt;
DEALLOCATE PREPARE parent_id_stmt;

SET @has_parent_index := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'estimates'
      AND index_name = 'idx_estimates_parent_id'
);

SET @parent_index_sql := IF(
    @has_parent_index = 0,
    'CREATE INDEX idx_estimates_parent_id ON estimates (parent_id)',
    'SELECT 1'
);

PREPARE parent_index_stmt FROM @parent_index_sql;
EXECUTE parent_index_stmt;
DEALLOCATE PREPARE parent_index_stmt;

SET @has_item_status := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'estimate_items'
      AND column_name = 'status'
);

SET @item_status_sql := IF(
    @has_item_status = 0,
    'ALTER TABLE estimate_items ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT ''pending'' AFTER line_total',
    'SELECT 1'
);

PREPARE item_status_stmt FROM @item_status_sql;
EXECUTE item_status_stmt;
DEALLOCATE PREPARE item_status_stmt;
