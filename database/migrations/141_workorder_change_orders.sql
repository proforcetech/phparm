-- Phase 10.2 of docs/expansion-plan.md: Change order workflow.
--
-- A change order (CO) is a customer-approved revision to an in-flight work
-- order. Real-world trigger: tech opens up the equipment, finds additional
-- work needed (or finds a part is more expensive than estimated), needs
-- written customer approval before billing extra. Without this, shops either
-- absorb the cost or risk billing disputes.
--
-- Two tables:
--   1) workorder_change_orders        — one row per CO, scoped to a WO,
--                                        with its own approval lifecycle and
--                                        rolled-up totals.
--   2) workorder_change_order_items   — line items (labor / part / fee /
--                                        discount), summing to the CO total.
--
-- All DDL idempotent. FKs to workorders / users are guarded on the referenced
-- table existing so this migration runs cleanly on a partially-set-up DB.

CREATE TABLE IF NOT EXISTS workorder_change_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    sequence_number INT UNSIGNED NOT NULL DEFAULT 1,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    reason_code VARCHAR(40) NOT NULL DEFAULT 'other',
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    subtotal_cents INT NOT NULL DEFAULT 0,
    tax_cents INT NOT NULL DEFAULT 0,
    total_cents INT NOT NULL DEFAULT 0,
    requested_by_user_id INT UNSIGNED NULL,
    requested_at DATETIME NULL,
    approved_by_user_id INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    rejected_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    applied_at DATETIME NULL,
    approval_method VARCHAR(40) NULL,
    rejection_reason TEXT NULL,
    customer_signature_name VARCHAR(160) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_change_orders_wo_seq (workorder_id, sequence_number),
    INDEX idx_change_orders_workorder (workorder_id),
    INDEX idx_change_orders_status (status),
    INDEX idx_change_orders_requested_by (requested_by_user_id),
    INDEX idx_change_orders_approved_by (approved_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: workorder_change_orders.workorder_id -> workorders(id) CASCADE
SET @wo_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorders');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'workorder_change_orders'
    AND constraint_name = 'fk_change_orders_workorder');
SET @sql := IF(@wo_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE workorder_change_orders ADD CONSTRAINT fk_change_orders_workorder FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: workorder_change_orders.requested_by_user_id -> users(id) SET NULL
SET @users_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'workorder_change_orders'
    AND constraint_name = 'fk_change_orders_requested_by');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE workorder_change_orders ADD CONSTRAINT fk_change_orders_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: workorder_change_orders.approved_by_user_id -> users(id) SET NULL
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'workorder_change_orders'
    AND constraint_name = 'fk_change_orders_approved_by');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE workorder_change_orders ADD CONSTRAINT fk_change_orders_approved_by FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS workorder_change_order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_order_id INT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'labor',
    description VARCHAR(500) NOT NULL,
    quantity DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
    unit_price_cents INT NOT NULL DEFAULT 0,
    line_total_cents INT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_co_items_change_order (change_order_id),
    CONSTRAINT fk_co_items_change_order FOREIGN KEY (change_order_id)
        REFERENCES workorder_change_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
