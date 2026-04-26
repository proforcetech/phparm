-- Phase 6.4 of docs/expansion-plan.md — saved tokenized payment method
-- references for portal users. We never store PAN / CVV / full card data;
-- the frontend tokenizes with the gateway's client SDK (Stripe Elements,
-- Square Web SDK, PayPal JS SDK) and POSTs only the opaque token + the
-- gateway's customer ID + display metadata (brand, last4, exp). This row
-- is just a pointer back into the gateway's vault.
--
-- Scope is (portal_account_id, gateway, external_method_id) UNIQUE so a
-- method can't be double-saved under the same account on the same gateway.
-- Cascaded off portal_accounts so revocation clears the vault mapping too.

CREATE TABLE IF NOT EXISTS portal_payment_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    portal_account_id INT UNSIGNED NOT NULL,
    gateway ENUM('stripe', 'square', 'paypal') NOT NULL,
    external_customer_id VARCHAR(128) NULL,
    external_method_id VARCHAR(128) NOT NULL,
    brand VARCHAR(32) NULL,
    last4 VARCHAR(4) NULL,
    exp_month TINYINT UNSIGNED NULL,
    exp_year SMALLINT UNSIGNED NULL,
    label VARCHAR(80) NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_portal_payment_methods_account
        FOREIGN KEY (portal_account_id) REFERENCES portal_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY uq_portal_payment_method (portal_account_id, gateway, external_method_id),
    INDEX idx_portal_payment_methods_account (portal_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
