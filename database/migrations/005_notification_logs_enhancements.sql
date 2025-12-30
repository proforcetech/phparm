-- Add meta column if it doesn't exist
SET @has_meta := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'notification_logs'
      AND column_name = 'meta'
);

SET @meta_sql := IF(
    @has_meta = 0,
    'ALTER TABLE notification_logs ADD COLUMN meta JSON NULL AFTER status',
    'SELECT 1'
);

PREPARE meta_stmt FROM @meta_sql;
EXECUTE meta_stmt;
DEALLOCATE PREPARE meta_stmt;

-- Add error_message column if it doesn't exist
SET @has_error_message := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'notification_logs'
      AND column_name = 'error_message'
);

SET @error_message_sql := IF(
    @has_error_message = 0,
    'ALTER TABLE notification_logs ADD COLUMN error_message TEXT NULL AFTER meta',
    'SELECT 1'
);

PREPARE error_message_stmt FROM @error_message_sql;
EXECUTE error_message_stmt;
DEALLOCATE PREPARE error_message_stmt;
