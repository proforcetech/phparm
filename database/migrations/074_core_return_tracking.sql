-- Core Return Tracking
-- Tracks core charges, returns, and credits for parts like alternators, batteries, etc.

-- Add core tracking fields to inventory_items
ALTER TABLE inventory_items
ADD COLUMN IF NOT EXISTS core_cost DECIMAL(10, 2) NULL AFTER cost;

ALTER TABLE inventory_items
ADD COLUMN IF NOT EXISTS core_price DECIMAL(10, 2) NULL AFTER core_cost;

ALTER TABLE inventory_items
ADD COLUMN IF NOT EXISTS is_core_eligible BOOLEAN DEFAULT FALSE AFTER core_price;

-- Create core_returns table to track individual core transactions
CREATE TABLE IF NOT EXISTS core_returns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Link to source transaction
    workorder_id INT UNSIGNED NULL,
    workorder_item_id INT UNSIGNED NULL,
    invoice_id INT UNSIGNED NULL,
    invoice_item_id INT UNSIGNED NULL,

    -- Part info
    inventory_item_id INT UNSIGNED NULL,
    part_description VARCHAR(255) NOT NULL,
    sku VARCHAR(120) NULL,

    -- Core amounts
    core_cost DECIMAL(10, 2) NOT NULL DEFAULT 0,
    core_price DECIMAL(10, 2) NOT NULL DEFAULT 0,

    -- Status tracking
    status ENUM('pending_from_customer', 'received_from_customer', 'pending_to_vendor', 'returned_to_vendor', 'credit_received', 'expired', 'waived') NOT NULL DEFAULT 'pending_from_customer',

    -- Customer tracking
    customer_id INT UNSIGNED NULL,
    customer_core_due_date DATE NULL,
    customer_core_received_at TIMESTAMP NULL,
    customer_credited_at TIMESTAMP NULL,

    -- Vendor tracking
    vendor VARCHAR(255) NULL,
    vendor_core_due_date DATE NULL,
    vendor_return_sent_at TIMESTAMP NULL,
    vendor_credit_received_at TIMESTAMP NULL,
    vendor_credit_amount DECIMAL(10, 2) NULL,

    -- Tracking info
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_core_workorder (workorder_id),
    INDEX idx_core_invoice (invoice_id),
    INDEX idx_core_inventory (inventory_item_id),
    INDEX idx_core_customer (customer_id),
    INDEX idx_core_status (status),
    INDEX idx_core_customer_due (customer_core_due_date),
    INDEX idx_core_vendor_due (vendor_core_due_date),

    CONSTRAINT fk_core_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id) ON DELETE SET NULL,
    CONSTRAINT fk_core_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE SET NULL,
    CONSTRAINT fk_core_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items (id) ON DELETE SET NULL,
    CONSTRAINT fk_core_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT fk_core_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_core_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,

    
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create core_return_history table for audit trail
CREATE TABLE IF NOT EXISTS core_return_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    core_return_id INT UNSIGNED NOT NULL,
    old_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NOT NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_core_history_return (core_return_id),
    CONSTRAINT fk_core_history_return FOREIGN KEY (core_return_id)
        REFERENCES core_returns (id) ON DELETE CASCADE,
    CONSTRAINT fk_core_history_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add core_return_id to invoice_items and workorder_items for linking
ALTER TABLE invoice_items
ADD COLUMN IF NOT EXISTS core_return_id INT UNSIGNED NULL;

ALTER TABLE invoice_items
ADD COLUMN IF NOT EXISTS core_price DECIMAL(10, 2) NULL;

ALTER TABLE invoice_items
ADD INDEX IF NOT EXISTS idx_invoice_item_core (core_return_id);

ALTER TABLE workorder_items
ADD COLUMN IF NOT EXISTS core_return_id INT UNSIGNED NULL;

ALTER TABLE workorder_items
ADD COLUMN IF NOT EXISTS core_price DECIMAL(10, 2) NULL;

ALTER TABLE workorder_items
ADD INDEX IF NOT EXISTS idx_workorder_item_core (core_return_id);

-- Insert default settings for core tracking
INSERT IGNORE INTO settings (key_name, value, category, type, description) VALUES
('inventory.core_tracking.enabled', 'true', 'inventory', 'boolean', 'Enable core return tracking'),
('inventory.core_tracking.customer_return_days', '30', 'inventory', 'integer', 'Days allowed for customer to return core'),
('inventory.core_tracking.vendor_return_days', '45', 'inventory', 'integer', 'Days allowed to return core to vendor'),
('inventory.core_tracking.alert_days_before_due', '7', 'inventory', 'integer', 'Days before due date to show alert');
