CREATE TABLE IF NOT EXISTS invoice_public_payment_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice_public_payment_invoice (invoice_id),
    UNIQUE KEY uniq_invoice_public_payment_token (token_hash),
    CONSTRAINT fk_invoice_public_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
