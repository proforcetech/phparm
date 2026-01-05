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
    INDEX idx_partner_dispatch_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS partner_request_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_dispatch_request_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT UNSIGNED NULL,
    content LONGBLOB NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_partner_attachment_request (partner_dispatch_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_fk_partner_dispatch_partner := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'partner_dispatch_requests' AND constraint_name = 'fk_partner_dispatch_partner'
);
SET @has_partner_dispatch_partner_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'partner_accounts'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'partner_dispatch_requests'
      AND c.column_name = 'partner_account_id'
);
SET @fk_partner_dispatch_partner_sql := IF(@has_fk_partner_dispatch_partner = 0 AND @has_partner_dispatch_partner_match = 1,
    'ALTER TABLE partner_dispatch_requests ADD CONSTRAINT fk_partner_dispatch_partner FOREIGN KEY (partner_account_id) REFERENCES partner_accounts (id)',
    'SELECT 1');
PREPARE fk_partner_dispatch_partner_stmt FROM @fk_partner_dispatch_partner_sql;
EXECUTE fk_partner_dispatch_partner_stmt;
DEALLOCATE PREPARE fk_partner_dispatch_partner_stmt;

SET @has_fk_partner_attachment_request := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'partner_request_attachments' AND constraint_name = 'fk_partner_attachment_request'
);
SET @has_partner_attachment_request_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'partner_dispatch_requests'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'partner_request_attachments'
      AND c.column_name = 'partner_dispatch_request_id'
);
SET @fk_partner_attachment_request_sql := IF(@has_fk_partner_attachment_request = 0 AND @has_partner_attachment_request_match = 1,
    'ALTER TABLE partner_request_attachments ADD CONSTRAINT fk_partner_attachment_request FOREIGN KEY (partner_dispatch_request_id) REFERENCES partner_dispatch_requests (id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE fk_partner_attachment_request_stmt FROM @fk_partner_attachment_request_sql;
EXECUTE fk_partner_attachment_request_stmt;
DEALLOCATE PREPARE fk_partner_attachment_request_stmt;
