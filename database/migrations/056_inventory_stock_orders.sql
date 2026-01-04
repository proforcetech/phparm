-- Migration: 056_inventory_stock_orders.sql
-- Description: Add inventory stock order tracking for replenishment

CREATE TABLE IF NOT EXISTS inventory_stock_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NULL,
    sku VARCHAR(120) NULL,
    description VARCHAR(255) NOT NULL,
    quantity_ordered INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('backorder', 'on_order', 'received', 'cancelled') NOT NULL DEFAULT 'on_order',
    expected_arrival_date DATE NULL,
    notes TEXT NULL,
    vendor VARCHAR(160) NULL,
    order_reference VARCHAR(120) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_stock_order_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_stock_order_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_stock_order_inventory (inventory_item_id),
    INDEX idx_stock_order_status (status),
    INDEX idx_stock_order_expected_arrival (expected_arrival_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
