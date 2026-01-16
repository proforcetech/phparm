CREATE TABLE IF NOT EXISTS cash_deposits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deposit_date DATE NOT NULL,
    bank_account VARCHAR(120) NOT NULL,
    reference VARCHAR(120) NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'posted',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cash_deposits_date (deposit_date),
    INDEX idx_cash_deposits_status (status),
    INDEX idx_cash_deposits_created_by (created_by),
    CONSTRAINT fk_cash_deposits_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_deposit_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    deposit_id INT UNSIGNED NOT NULL,
    payment_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_cash_deposit_payment (deposit_id, payment_id),
    INDEX idx_cash_deposit_items_payment (payment_id),
    CONSTRAINT fk_cash_deposit_items_deposit FOREIGN KEY (deposit_id) REFERENCES cash_deposits (id) ON DELETE CASCADE,
    CONSTRAINT fk_cash_deposit_items_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
