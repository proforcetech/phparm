-- Add billing address fields for commercial customers
ALTER TABLE customers
ADD COLUMN billing_street VARCHAR(255) NULL AFTER country,
ADD COLUMN billing_city VARCHAR(120) NULL AFTER billing_street,
ADD COLUMN billing_state VARCHAR(120) NULL AFTER billing_city,
ADD COLUMN billing_postal_code VARCHAR(20) NULL AFTER billing_state,
ADD COLUMN billing_country VARCHAR(120) NULL AFTER billing_postal_code;
