ALTER TABLE warranty_claims
    ADD COLUMN financial_impact DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN credit_received_amount DECIMAL(12,2) NULL AFTER financial_impact;
