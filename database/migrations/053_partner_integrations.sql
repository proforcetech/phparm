-- Partner integrations tables for inbound dispatch requests

CREATE TABLE IF NOT EXISTS partner_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    metadata JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_partner_accounts_key (partner_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_dispatch_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_account_id INT UNSIGNED NOT NULL,
    external_reference VARCHAR(120) NULL,
    dispatch_reference VARCHAR(120) NULL,
    source VARCHAR(40) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'received',
    payload JSON NULL,
    raw_payload LONGTEXT NULL,
    error_message TEXT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_partner_dispatch_partner (partner_account_id),
    INDEX idx_partner_dispatch_status (status),
    INDEX idx_partner_dispatch_created (created_at),
    CONSTRAINT fk_partner_dispatch_partner FOREIGN KEY (partner_account_id) REFERENCES partner_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_request_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_dispatch_request_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT UNSIGNED NULL,
    content LONGBLOB NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_partner_attachment_request (partner_dispatch_request_id),
    CONSTRAINT fk_partner_attachment_request FOREIGN KEY (partner_dispatch_request_id) REFERENCES partner_dispatch_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
