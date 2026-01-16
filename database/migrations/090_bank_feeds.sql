-- Add bank feed transactions and matching table

CREATE TABLE IF NOT EXISTS bank_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(40) NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    account_name VARCHAR(120) NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    description VARCHAR(255) NULL,
    transaction_date DATE NOT NULL,
    posted_at DATETIME NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'posted',
    raw_payload JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bank_transactions_provider_external (provider, external_id),
    INDEX idx_bank_transactions_date (transaction_date),
    INDEX idx_bank_transactions_amount (amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_transaction_matches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bank_transaction_id INT UNSIGNED NOT NULL,
    reference_type VARCHAR(40) NOT NULL,
    reference_id INT UNSIGNED NOT NULL,
    match_reason VARCHAR(120) NULL,
    matched_by INT UNSIGNED NULL,
    matched_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bank_transaction_reference (bank_transaction_id, reference_type, reference_id),
    INDEX idx_bank_transaction_matches_reference (reference_type, reference_id),
    CONSTRAINT fk_bank_transaction_matches_transaction
        FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
