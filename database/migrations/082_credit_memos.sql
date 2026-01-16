-- Add credit memo support for invoices

SET @has_invoice_is_credit_memo := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'invoices'
      AND column_name = 'is_credit_memo'
);
SET @invoice_is_credit_memo_sql := IF(@has_invoice_is_credit_memo = 0,
    'ALTER TABLE invoices ADD COLUMN is_credit_memo TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT 1');
PREPARE invoice_is_credit_memo_stmt FROM @invoice_is_credit_memo_sql;
EXECUTE invoice_is_credit_memo_stmt;
DEALLOCATE PREPARE invoice_is_credit_memo_stmt;

SET @has_invoice_original_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'invoices'
      AND column_name = 'original_invoice_id'
);
SET @invoice_original_id_sql := IF(@has_invoice_original_id = 0,
    'ALTER TABLE invoices ADD COLUMN original_invoice_id INT UNSIGNED NULL AFTER estimate_id',
    'SELECT 1');
PREPARE invoice_original_id_stmt FROM @invoice_original_id_sql;
EXECUTE invoice_original_id_stmt;
DEALLOCATE PREPARE invoice_original_id_stmt;

SET @has_invoice_original_idx := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'invoices'
      AND index_name = 'idx_invoice_original'
);
SET @invoice_original_idx_sql := IF(@has_invoice_original_idx = 0,
    'ALTER TABLE invoices ADD INDEX idx_invoice_original (original_invoice_id)',
    'SELECT 1');
PREPARE invoice_original_idx_stmt FROM @invoice_original_idx_sql;
EXECUTE invoice_original_idx_stmt;
DEALLOCATE PREPARE invoice_original_idx_stmt;

SET @has_invoice_original_fk := (
    SELECT COUNT(*)
    FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'invoices'
      AND constraint_name = 'fk_invoice_original_invoice'
);
SET @invoice_original_fk_sql := IF(@has_invoice_original_fk = 0,
    'ALTER TABLE invoices ADD CONSTRAINT fk_invoice_original_invoice FOREIGN KEY (original_invoice_id) REFERENCES invoices (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE invoice_original_fk_stmt FROM @invoice_original_fk_sql;
EXECUTE invoice_original_fk_stmt;
DEALLOCATE PREPARE invoice_original_fk_stmt;
