-- Payment Sessions and Refunds Tables
-- These tables support the payment gateway integration

CREATE TABLE IF NOT EXISTS payment_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    session_id VARCHAR(255) NOT NULL,
    checkout_url TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payment_session_invoice (invoice_id),
    INDEX idx_payment_session_session (session_id),
    UNIQUE KEY unique_invoice_provider (invoice_id, provider),
    CONSTRAINT fk_payment_session_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    payment_reference VARCHAR(255) NOT NULL,
    refund_id VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason TEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    metadata JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refund_invoice (invoice_id),
    INDEX idx_refund_payment (payment_reference),
    INDEX idx_refund_id (refund_id),
    CONSTRAINT fk_refund_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update existing payments table to match documentation
-- Adding method/reference columns if they don't exist
ALTER TABLE payments
    MODIFY COLUMN gateway VARCHAR(40) NULL,
    ADD COLUMN method VARCHAR(40) NULL AFTER gateway,
    ADD COLUMN reference VARCHAR(255) NULL AFTER amount,
    ADD COLUMN metadata JSON NULL AFTER status;

-- Migrate existing data: copy gateway to method if method is null
UPDATE payments SET method = gateway WHERE method IS NULL;
