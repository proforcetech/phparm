-- Migration: 084_inventory_transactions.sql
-- Description: Add inventory transaction ledger table

CREATE TABLE IF NOT EXISTS inventory_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    quantity_before INT NOT NULL,
    quantity_after INT NOT NULL,
    quantity_change INT NOT NULL,
    source VARCHAR(60) NOT NULL,
    reference VARCHAR(120) NULL,
    reason VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_transactions_item (inventory_item_id),
    INDEX idx_inventory_transactions_source (source),
    INDEX idx_inventory_transactions_reference (reference),
    CONSTRAINT fk_inventory_transactions_item FOREIGN KEY (inventory_item_id)
        REFERENCES inventory_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_transactions_user FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
