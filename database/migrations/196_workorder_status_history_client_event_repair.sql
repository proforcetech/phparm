-- Repair older installs where migration 055 was marked executed before the
-- workorder_status_history client event column was added.

SET @has_workorder_status_history := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_status_history'
);

SET @has_workorder_status_client_event_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_status_history'
      AND column_name = 'client_event_id'
);

SET @workorder_status_client_event_column_sql := IF(
    @has_workorder_status_history > 0 AND @has_workorder_status_client_event_id = 0,
    'ALTER TABLE workorder_status_history ADD COLUMN client_event_id VARCHAR(64) NULL AFTER notes',
    'SELECT 1'
);
PREPARE workorder_status_client_event_column_stmt FROM @workorder_status_client_event_column_sql;
EXECUTE workorder_status_client_event_column_stmt;
DEALLOCATE PREPARE workorder_status_client_event_column_stmt;

SET @has_workorder_status_client_event_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_status_history'
      AND column_name = 'client_event_id'
);

SET @has_workorder_status_client_event_index := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_status_history'
      AND index_name = 'idx_workorder_status_event'
);

SET @workorder_status_client_event_index_sql := IF(
    @has_workorder_status_history > 0
        AND @has_workorder_status_client_event_id > 0
        AND @has_workorder_status_client_event_index = 0,
    'ALTER TABLE workorder_status_history ADD UNIQUE INDEX idx_workorder_status_event (workorder_id, client_event_id)',
    'SELECT 1'
);
PREPARE workorder_status_client_event_index_stmt FROM @workorder_status_client_event_index_sql;
EXECUTE workorder_status_client_event_index_stmt;
DEALLOCATE PREPARE workorder_status_client_event_index_stmt;
