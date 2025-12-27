-- Check and add internal_notes column
SET @has_internal_notes := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'bundles' AND column_name = 'internal_notes'
);
SET @internal_notes_sql := IF(@has_internal_notes = 0,
    'ALTER TABLE bundles ADD COLUMN internal_notes TEXT NULL AFTER description', 'SELECT 1');
PREPARE internal_notes_stmt FROM @internal_notes_sql;
EXECUTE internal_notes_stmt;
DEALLOCATE PREPARE internal_notes_stmt;

-- Check and add discount_type column
SET @has_discount_type := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'bundles' AND column_name = 'discount_type'
);
SET @discount_type_sql := IF(@has_discount_type = 0,
    'ALTER TABLE bundles ADD COLUMN discount_type VARCHAR(20) NULL AFTER internal_notes', 'SELECT 1');
PREPARE discount_type_stmt FROM @discount_type_sql;
EXECUTE discount_type_stmt;
DEALLOCATE PREPARE discount_type_stmt;

-- Check and add discount_value column
SET @has_discount_value := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'bundles' AND column_name = 'discount_value'
);
SET @discount_value_sql := IF(@has_discount_value = 0,
    'ALTER TABLE bundles ADD COLUMN discount_value DECIMAL(12,2) NULL AFTER discount_type', 'SELECT 1');
PREPARE discount_value_stmt FROM @discount_value_sql;
EXECUTE discount_value_stmt;
DEALLOCATE PREPARE discount_value_stmt;
