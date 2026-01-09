-- Add last activity tracking to users
SET @has_user_last_activity := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'last_activity_at'
);
SET @user_last_activity_sql := IF(@has_user_last_activity = 0,
    'ALTER TABLE users ADD COLUMN last_activity_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at',
    'SELECT 1'
);
PREPARE user_last_activity_stmt FROM @user_last_activity_sql;
EXECUTE user_last_activity_stmt;
DEALLOCATE PREPARE user_last_activity_stmt;

SET @has_user_last_activity_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_last_activity'
);
SET @user_last_activity_index_sql := IF(@has_user_last_activity_index = 0,
    'CREATE INDEX idx_users_last_activity ON users (last_activity_at)',
    'SELECT 1'
);
PREPARE user_last_activity_index_stmt FROM @user_last_activity_index_sql;
EXECUTE user_last_activity_index_stmt;
DEALLOCATE PREPARE user_last_activity_index_stmt;
