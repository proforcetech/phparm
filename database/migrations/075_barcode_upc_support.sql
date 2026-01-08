-- Barcode/UPC Support for Inventory
-- Adds barcode and UPC fields to inventory items for scanner support

-- Add barcode fields to inventory_items
ALTER TABLE inventory_items
ADD COLUMN upc VARCHAR(50) NULL AFTER manufacturer_part_number COMMENT 'Universal Product Code',
ADD COLUMN barcode VARCHAR(100) NULL AFTER upc COMMENT 'Internal or external barcode',
ADD COLUMN barcode_type ENUM('UPC-A', 'UPC-E', 'EAN-13', 'EAN-8', 'CODE-39', 'CODE-128', 'QR', 'custom') NULL AFTER barcode,
ADD INDEX idx_inventory_upc (upc),
ADD INDEX idx_inventory_barcode (barcode);

-- Create barcode scan log table for audit purposes
CREATE TABLE IF NOT EXISTS barcode_scan_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barcode_value VARCHAR(255) NOT NULL,
    scan_type ENUM('inventory_lookup', 'workorder_add', 'invoice_add', 'stock_count', 'receive', 'other') NOT NULL,
    inventory_item_id INT UNSIGNED NULL,
    workorder_id INT UNSIGNED NULL,
    invoice_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    success BOOLEAN DEFAULT TRUE,
    error_message VARCHAR(255) NULL,
    scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_scan_barcode (barcode_value),
    INDEX idx_scan_item (inventory_item_id),
    INDEX idx_scan_user (user_id),
    INDEX idx_scan_date (scanned_at),
    CONSTRAINT fk_scan_inventory FOREIGN KEY (inventory_item_id)
        REFERENCES inventory_items (id) ON DELETE SET NULL,
    CONSTRAINT fk_scan_workorder FOREIGN KEY (workorder_id)
        REFERENCES workorders (id) ON DELETE SET NULL,
    CONSTRAINT fk_scan_invoice FOREIGN KEY (invoice_id)
        REFERENCES invoices (id) ON DELETE SET NULL,
    CONSTRAINT fk_scan_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert settings for barcode scanning
INSERT IGNORE INTO settings (key_name, value, category, type, description) VALUES
('inventory.barcode.enabled', 'true', 'inventory', 'boolean', 'Enable barcode scanning'),
('inventory.barcode.auto_add_to_cart', 'true', 'inventory', 'boolean', 'Automatically add scanned item to cart/workorder'),
('inventory.barcode.beep_on_success', 'true', 'inventory', 'boolean', 'Play sound on successful scan'),
('inventory.barcode.preferred_camera', 'environment', 'inventory', 'string', 'Preferred camera for scanning (user/environment)');
