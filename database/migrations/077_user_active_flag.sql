-- Add active flag to users for soft deletes
SET @has_user_active := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'active'
);
SET @user_active_sql := IF(@has_user_active = 0,
    'ALTER TABLE users ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER remember_token',
    'SELECT 1'
);
PREPARE user_active_stmt FROM @user_active_sql;
EXECUTE user_active_stmt;
DEALLOCATE PREPARE user_active_stmt;

SET @has_user_active_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_active'
);
SET @user_active_index_sql := IF(@has_user_active_index = 0,
    'CREATE INDEX idx_users_active ON users (active)',
    'SELECT 1'
);
PREPARE user_active_index_stmt FROM @user_active_index_sql;
EXECUTE user_active_index_stmt;
DEALLOCATE PREPARE user_active_index_stmt;
