-- Check and add is_active column
SET @has_is_active := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'customer_vehicles' AND column_name = 'is_active'
);
SET @is_active_sql := IF(@has_is_active = 0,
    'ALTER TABLE customer_vehicles ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes', 'SELECT 1');
PREPARE is_active_stmt FROM @is_active_sql;
EXECUTE is_active_stmt;
DEALLOCATE PREPARE is_active_stmt;
