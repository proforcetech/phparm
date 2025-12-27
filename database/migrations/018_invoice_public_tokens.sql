-- Check and add public_token column
SET @has_public_token := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'public_token'
);
SET @public_token_sql := IF(@has_public_token = 0,
    'ALTER TABLE invoices ADD COLUMN public_token VARCHAR(64) NULL', 'SELECT 1');
PREPARE public_token_stmt FROM @public_token_sql;
EXECUTE public_token_stmt;
DEALLOCATE PREPARE public_token_stmt;

-- Check and add public_token_expires_at column
SET @has_expires_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'public_token_expires_at'
);
SET @expires_at_sql := IF(@has_expires_at = 0,
    'ALTER TABLE invoices ADD COLUMN public_token_expires_at DATETIME NULL', 'SELECT 1');
PREPARE expires_at_stmt FROM @expires_at_sql;
EXECUTE expires_at_stmt;
DEALLOCATE PREPARE expires_at_stmt;

-- Check and add index
SET @has_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND index_name = 'idx_invoice_public_token'
);
SET @index_sql := IF(@has_index = 0,
    'ALTER TABLE invoices ADD UNIQUE KEY idx_invoice_public_token (public_token)', 'SELECT 1');
PREPARE index_stmt FROM @index_sql;
EXECUTE index_stmt;
DEALLOCATE PREPARE index_stmt;

-- Seed existing invoices with tokens valid for 30 days to enable public access immediately
UPDATE invoices
SET public_token = LOWER(REPLACE(UUID(), '-', '')),
    public_token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
WHERE public_token IS NULL;
