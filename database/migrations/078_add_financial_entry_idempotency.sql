ALTER TABLE financial_entries
    ADD COLUMN idempotency_key VARCHAR(120) NULL;

CREATE UNIQUE INDEX uniq_financial_entries_idempotency
    ON financial_entries (idempotency_key);
