-- Add split billing support for invoices

SET @has_invoice_split_billing := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'invoices'
      AND column_name = 'split_billing'
);
SET @invoice_split_billing_sql := IF(@has_invoice_split_billing = 0,
    'ALTER TABLE invoices ADD COLUMN split_billing TINYINT(1) NOT NULL DEFAULT 0 AFTER due_date',
    'SELECT 1');
PREPARE invoice_split_billing_stmt FROM @invoice_split_billing_sql;
EXECUTE invoice_split_billing_stmt;
DEALLOCATE PREPARE invoice_split_billing_stmt;

CREATE TABLE IF NOT EXISTS invoice_payer_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    payer_role ENUM('primary', 'secondary') NOT NULL,
    payer_name VARCHAR(160) NULL,
    allocated_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_payer_allocations_invoice (invoice_id),
    INDEX idx_invoice_payer_allocations_role (payer_role),
    CONSTRAINT fk_invoice_payer_allocations_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
