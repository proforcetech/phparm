-- Add branch support to core tables

-- Users
SET @has_branch_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'branch_id'
);
SET @branch_sql := IF(@has_branch_id = 0,
    'ALTER TABLE users ADD COLUMN branch_id INT UNSIGNED NULL AFTER customer_id',
    'SELECT 1'
);
PREPARE branch_stmt FROM @branch_sql;
EXECUTE branch_stmt;
DEALLOCATE PREPARE branch_stmt;

SET @has_branch_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_branch'
);
SET @branch_idx_sql := IF(@has_branch_idx = 0,
    'ALTER TABLE users ADD INDEX idx_users_branch (branch_id)',
    'SELECT 1'
);
PREPARE branch_idx_stmt FROM @branch_idx_sql;
EXECUTE branch_idx_stmt;
DEALLOCATE PREPARE branch_idx_stmt;

-- Inventory items
SET @has_branch_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND column_name = 'branch_id'
);
SET @branch_sql := IF(@has_branch_id = 0,
    'ALTER TABLE inventory_items ADD COLUMN branch_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE branch_stmt FROM @branch_sql;
EXECUTE branch_stmt;
DEALLOCATE PREPARE branch_stmt;

SET @has_branch_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND index_name = 'idx_inventory_branch'
);
SET @branch_idx_sql := IF(@has_branch_idx = 0,
    'ALTER TABLE inventory_items ADD INDEX idx_inventory_branch (branch_id)',
    'SELECT 1'
);
PREPARE branch_idx_stmt FROM @branch_idx_sql;
EXECUTE branch_idx_stmt;
DEALLOCATE PREPARE branch_idx_stmt;

-- Inventory stock orders
SET @has_branch_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_stock_orders' AND column_name = 'branch_id'
);
SET @branch_sql := IF(@has_branch_id = 0,
    'ALTER TABLE inventory_stock_orders ADD COLUMN branch_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE branch_stmt FROM @branch_sql;
EXECUTE branch_stmt;
DEALLOCATE PREPARE branch_stmt;

SET @has_branch_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'inventory_stock_orders' AND index_name = 'idx_inventory_stock_orders_branch'
);
SET @branch_idx_sql := IF(@has_branch_idx = 0,
    'ALTER TABLE inventory_stock_orders ADD INDEX idx_inventory_stock_orders_branch (branch_id)',
    'SELECT 1'
);
PREPARE branch_idx_stmt FROM @branch_idx_sql;
EXECUTE branch_idx_stmt;
DEALLOCATE PREPARE branch_idx_stmt;

-- Inventory pull requests
SET @has_branch_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_pull_requests' AND column_name = 'branch_id'
);
SET @branch_sql := IF(@has_branch_id = 0,
    'ALTER TABLE inventory_pull_requests ADD COLUMN branch_id INT UNSIGNED NULL AFTER id',
    'SELECT 1'
);
PREPARE branch_stmt FROM @branch_sql;
EXECUTE branch_stmt;
DEALLOCATE PREPARE branch_stmt;

SET @has_branch_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'inventory_pull_requests' AND index_name = 'idx_inventory_pull_requests_branch'
);
SET @branch_idx_sql := IF(@has_branch_idx = 0,
    'ALTER TABLE inventory_pull_requests ADD INDEX idx_inventory_pull_requests_branch (branch_id)',
    'SELECT 1'
);
PREPARE branch_idx_stmt FROM @branch_idx_sql;
EXECUTE branch_idx_stmt;
DEALLOCATE PREPARE branch_idx_stmt;

-- Inventory transactions
SET @has_branch_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_transactions' AND column_name = 'branch_id'
);
SET @branch_sql := IF(@has_branch_id = 0,
    'ALTER TABLE inventory_transactions ADD COLUMN branch_id INT UNSIGNED NULL AFTER inventory_item_id',
    'SELECT 1'
);
PREPARE branch_stmt FROM @branch_sql;
EXECUTE branch_stmt;
DEALLOCATE PREPARE branch_stmt;

SET @has_branch_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'inventory_transactions' AND index_name = 'idx_inventory_transactions_branch'
);
SET @branch_idx_sql := IF(@has_branch_idx = 0,
    'ALTER TABLE inventory_transactions ADD INDEX idx_inventory_transactions_branch (branch_id)',
    'SELECT 1'
);
PREPARE branch_idx_stmt FROM @branch_idx_sql;
EXECUTE branch_idx_stmt;
DEALLOCATE PREPARE branch_idx_stmt;

-- Invoices
SET @has_branch_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'branch_id'
);
SET @branch_sql := IF(@has_branch_id = 0,
    'ALTER TABLE invoices ADD COLUMN branch_id INT UNSIGNED NULL AFTER workorder_id',
    'SELECT 1'
);
PREPARE branch_stmt FROM @branch_sql;
EXECUTE branch_stmt;
DEALLOCATE PREPARE branch_stmt;

SET @has_branch_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND index_name = 'idx_invoices_branch'
);
SET @branch_idx_sql := IF(@has_branch_idx = 0,
    'ALTER TABLE invoices ADD INDEX idx_invoices_branch (branch_id)',
    'SELECT 1'
);
PREPARE branch_idx_stmt FROM @branch_idx_sql;
EXECUTE branch_idx_stmt;
DEALLOCATE PREPARE branch_idx_stmt;

-- Invoice items
SET @has_branch_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoice_items' AND column_name = 'branch_id'
);
SET @branch_sql := IF(@has_branch_id = 0,
    'ALTER TABLE invoice_items ADD COLUMN branch_id INT UNSIGNED NULL AFTER invoice_id',
    'SELECT 1'
);
PREPARE branch_stmt FROM @branch_sql;
EXECUTE branch_stmt;
DEALLOCATE PREPARE branch_stmt;

SET @has_branch_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'invoice_items' AND index_name = 'idx_invoice_items_branch'
);
SET @branch_idx_sql := IF(@has_branch_idx = 0,
    'ALTER TABLE invoice_items ADD INDEX idx_invoice_items_branch (branch_id)',
    'SELECT 1'
);
PREPARE branch_idx_stmt FROM @branch_idx_sql;
EXECUTE branch_idx_stmt;
DEALLOCATE PREPARE branch_idx_stmt;

-- Workorder jobs
SET @has_branch_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorder_jobs' AND column_name = 'branch_id'
);
SET @branch_sql := IF(@has_branch_id = 0,
    'ALTER TABLE workorder_jobs ADD COLUMN branch_id INT UNSIGNED NULL AFTER workorder_id',
    'SELECT 1'
);
PREPARE branch_stmt FROM @branch_sql;
EXECUTE branch_stmt;
DEALLOCATE PREPARE branch_stmt;

SET @has_branch_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorder_jobs' AND index_name = 'idx_workorder_jobs_branch'
);
SET @branch_idx_sql := IF(@has_branch_idx = 0,
    'ALTER TABLE workorder_jobs ADD INDEX idx_workorder_jobs_branch (branch_id)',
    'SELECT 1'
);
PREPARE branch_idx_stmt FROM @branch_idx_sql;
EXECUTE branch_idx_stmt;
DEALLOCATE PREPARE branch_idx_stmt;

-- Workorder items
SET @has_branch_id := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorder_items' AND column_name = 'branch_id'
);
SET @branch_sql := IF(@has_branch_id = 0,
    'ALTER TABLE workorder_items ADD COLUMN branch_id INT UNSIGNED NULL AFTER workorder_job_id',
    'SELECT 1'
);
PREPARE branch_stmt FROM @branch_sql;
EXECUTE branch_stmt;
DEALLOCATE PREPARE branch_stmt;

SET @has_branch_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorder_items' AND index_name = 'idx_workorder_items_branch'
);
SET @branch_idx_sql := IF(@has_branch_idx = 0,
    'ALTER TABLE workorder_items ADD INDEX idx_workorder_items_branch (branch_id)',
    'SELECT 1'
);
PREPARE branch_idx_stmt FROM @branch_idx_sql;
EXECUTE branch_idx_stmt;
DEALLOCATE PREPARE branch_idx_stmt;
