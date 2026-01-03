-- Add ledger tables for credit accounts
ALTER TABLE credit_accounts
    ADD COLUMN available_credit DECIMAL(12,2) DEFAULT 0 AFTER balance,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

UPDATE credit_accounts
SET available_credit = GREATEST(0, credit_limit - balance),
    created_at = IFNULL(created_at, NOW()),
    updated_at = IFNULL(updated_at, NOW());

CREATE TABLE IF NOT EXISTS credit_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_account_id UNSIGNED INT NOT NULL,
    customer_id UNSIGNED INT NOT NULL,
    transaction_type VARCHAR(20) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    reference_type VARCHAR(50) NULL,
    reference_id INT UNSIGNED NULL,
    description TEXT NULL,
    created_by INT UNSIGNED NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_credit_transactions_account (credit_account_id),
    INDEX idx_credit_transactions_customer (customer_id),
    INDEX idx_credit_transactions_occurred (occurred_at),
    INDEX idx_credit_transactions_reference (reference_type, reference_id),
    CONSTRAINT fk_credit_transactions_account FOREIGN KEY (credit_account_id) REFERENCES credit_accounts (id),
    CONSTRAINT fk_credit_transactions_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    credit_account_id INT NOT NULL,
    customer_id INT NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATETIME NOT NULL,
    reference_number VARCHAR(100) NULL,
    notes TEXT NULL,
    processed_by INT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_credit_payments_account (credit_account_id),
    INDEX idx_credit_payments_customer (customer_id),
    INDEX idx_credit_payments_payment_date (payment_date),
    INDEX idx_credit_payments_status (status),
    CONSTRAINT fk_credit_payments_account FOREIGN KEY (credit_account_id) REFERENCES credit_accounts (id),
    CONSTRAINT fk_credit_payments_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_payment_reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    credit_account_id INT NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    reminder_type VARCHAR(20) NOT NULL,
    days_before_due INT UNSIGNED NULL,
    days_past_due INT UNSIGNED NULL,
    sent_at DATETIME NOT NULL,
    sent_via VARCHAR(20) NOT NULL,
    message TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_credit_reminders_account (credit_account_id),
    INDEX idx_credit_reminders_customer (customer_id),
    INDEX idx_credit_reminders_sent (sent_at),
    INDEX idx_credit_reminders_type (reminder_type),
    CONSTRAINT fk_credit_reminders_account FOREIGN KEY (credit_account_id) REFERENCES credit_accounts (id),
    CONSTRAINT fk_credit_reminders_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
