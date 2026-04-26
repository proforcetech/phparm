-- Phase 4.2 of docs/expansion-plan.md: contract e-sign.
--
-- Mirrors the estimate pattern (migrations 044/048):
--   * contract_public_links   — short-code + token-hash shareable links
--   * contract_signatures     — append-only signature audit trail
--
-- The primary "this contract is signed" state lives on contracts.signed_at
-- (set by the service at signature capture). The signatures table is the
-- full audit trail — IP, user agent, device fingerprint, document hash,
-- legal consent flag — which is what matters in a compliance dispute.

CREATE TABLE IF NOT EXISTS contract_public_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    short_code VARCHAR(20) NOT NULL,
    expires_at DATETIME NULL,
    last_accessed_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contract_public_links_hash (token_hash),
    UNIQUE KEY uq_contract_public_links_short (short_code),
    INDEX idx_contract_public_links_contract (contract_id),
    CONSTRAINT fk_contract_public_links_contract FOREIGN KEY (contract_id)
        REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contract_signatures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    signer_name VARCHAR(160) NOT NULL,
    signer_email VARCHAR(160) NULL,
    signer_title VARCHAR(120) NULL,
    signature_data MEDIUMTEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_fingerprint VARCHAR(255) NULL,
    document_hash VARCHAR(64) NULL,
    signature_hash VARCHAR(64) NULL,
    legal_consent TINYINT(1) NOT NULL DEFAULT 0,
    consent_text TEXT NULL,
    comment TEXT NULL,
    signed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contract_signatures_contract (contract_id),
    INDEX idx_contract_signatures_signed_at (signed_at),
    CONSTRAINT fk_contract_signatures_contract FOREIGN KEY (contract_id)
        REFERENCES contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
