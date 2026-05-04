-- =============================================================================
-- Migration 169 — Consolidated monthly statements (Phase 17 / M11)
--
-- Lets a customer who runs many small WOs (typical for a property-management
-- chain or a multi-site retailer) receive ONE bundled monthly statement
-- instead of N invoices in their inbox.
--
-- Design:
--   consolidated_statements              header — period, totals, status
--   consolidated_statement_invoices      m:n join to the underlying invoices
--   customers.monthly_consolidated_billing  per-customer opt-in flag
--   invoices.consolidated_statement_id   reverse pointer for fast lookup
--
-- The child invoices are NOT modified beyond setting consolidated_statement_id;
-- they keep their own status, public token, and audit history. The statement
-- is purely a roll-up envelope.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PART A: consolidated_statements header
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consolidated_statements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(12,2) NOT NULL DEFAULT 0,
    invoice_count INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    sent_at DATETIME NULL,
    paid_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    generated_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_consol_statements_customer (customer_id),
    INDEX idx_consol_statements_period (period_start, period_end),
    INDEX idx_consol_statements_status (status),
    UNIQUE KEY uk_consol_statements_period (customer_id, period_start, period_end),
    CONSTRAINT fk_consol_statements_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_consol_statements_user
        FOREIGN KEY (generated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART B: m:n join — which invoices roll up into which statement
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consolidated_statement_invoices (
    statement_id BIGINT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED NOT NULL,
    invoice_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (statement_id, invoice_id),
    INDEX idx_csi_invoice (invoice_id),
    CONSTRAINT fk_csi_statement
        FOREIGN KEY (statement_id) REFERENCES consolidated_statements(id) ON DELETE CASCADE,
    CONSTRAINT fk_csi_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART C: customer opt-in flag
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'monthly_consolidated_billing');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE customers ADD COLUMN monthly_consolidated_billing TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'customers' AND index_name = 'idx_customers_monthly_consolidated_billing');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE customers ADD INDEX idx_customers_monthly_consolidated_billing (monthly_consolidated_billing)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- PART D: reverse pointer on invoices for fast lookup ("is this invoice on a
-- consolidated statement?")
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'consolidated_statement_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE invoices ADD COLUMN consolidated_statement_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND index_name = 'idx_invoices_consolidated_statement');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE invoices ADD INDEX idx_invoices_consolidated_statement (consolidated_statement_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND constraint_name = 'fk_invoices_consolidated_statement');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE invoices ADD CONSTRAINT fk_invoices_consolidated_statement FOREIGN KEY (consolidated_statement_id) REFERENCES consolidated_statements(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
