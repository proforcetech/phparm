-- Add GOA fee tracking fields to workorders

SET @has_goa_fee := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorders'
      AND column_name = 'goa_fee'
);
SET @goa_fee_sql := IF(@has_goa_fee = 0,
    'ALTER TABLE workorders ADD COLUMN goa_fee DECIMAL(12,2) DEFAULT 0 AFTER hazmat_disposal_fee',
    'SELECT 1'
);
PREPARE goa_fee_stmt FROM @goa_fee_sql;
EXECUTE goa_fee_stmt;
DEALLOCATE PREPARE goa_fee_stmt;

SET @has_goa_billing_party := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorders'
      AND column_name = 'goa_billing_party'
);
SET @goa_billing_party_sql := IF(@has_goa_billing_party = 0,
    'ALTER TABLE workorders ADD COLUMN goa_billing_party VARCHAR(40) NULL AFTER goa_fee',
    'SELECT 1'
);
PREPARE goa_billing_party_stmt FROM @goa_billing_party_sql;
EXECUTE goa_billing_party_stmt;
DEALLOCATE PREPARE goa_billing_party_stmt;
