ALTER TABLE invoices
    ADD COLUMN public_token VARCHAR(64) NULL,
    ADD COLUMN public_token_expires_at DATETIME NULL,
    ADD UNIQUE KEY idx_invoice_public_token (public_token);

-- Seed existing invoices with tokens valid for 30 days to enable public access immediately
UPDATE invoices
SET public_token = LOWER(REPLACE(UUID(), '-', '')),
    public_token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
WHERE public_token IS NULL;
