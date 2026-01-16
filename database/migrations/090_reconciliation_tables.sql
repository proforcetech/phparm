CREATE TABLE IF NOT EXISTS reconciliation_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reconciliation_sessions_dates (start_date, end_date),
    INDEX idx_reconciliation_sessions_status (status)
);

CREATE TABLE IF NOT EXISTS reconciliation_bank_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    transaction_date DATE NOT NULL,
    description VARCHAR(255) NOT NULL,
    reference VARCHAR(255) NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recon_bank_session (session_id),
    INDEX idx_recon_bank_date (transaction_date),
    CONSTRAINT fk_recon_bank_session
        FOREIGN KEY (session_id)
        REFERENCES reconciliation_sessions (id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS reconciliation_matches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    bank_transaction_id INT UNSIGNED NULL,
    ledger_entry_id INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'matched',
    amount_difference DECIMAL(12,2) NOT NULL DEFAULT 0,
    discrepancy_reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recon_matches_session (session_id),
    INDEX idx_recon_matches_status (status),
    UNIQUE INDEX uniq_recon_bank_match (bank_transaction_id),
    UNIQUE INDEX uniq_recon_ledger_match (ledger_entry_id),
    CONSTRAINT fk_recon_match_session
        FOREIGN KEY (session_id)
        REFERENCES reconciliation_sessions (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_recon_match_bank
        FOREIGN KEY (bank_transaction_id)
        REFERENCES reconciliation_bank_transactions (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_recon_match_ledger
        FOREIGN KEY (ledger_entry_id)
        REFERENCES financial_entries (id)
        ON DELETE SET NULL
);
