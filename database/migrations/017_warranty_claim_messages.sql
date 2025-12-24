CREATE TABLE IF NOT EXISTS warranty_claim_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    claim_id INT UNSIGNED NOT NULL,
    actor_type VARCHAR(40) NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX idx_warranty_message_claim (claim_id),
    CONSTRAINT fk_warranty_message_claim FOREIGN KEY (claim_id) REFERENCES warranty_claims (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed initial customer messages from claim descriptions so history is preserved
INSERT INTO warranty_claim_messages (claim_id, actor_type, actor_id, message, created_at, updated_at)
SELECT id, 'customer', customer_id, description, created_at, updated_at FROM warranty_claims;
