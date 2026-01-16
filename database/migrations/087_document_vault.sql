CREATE TABLE IF NOT EXISTS document_vault_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    document_type VARCHAR(40) NOT NULL,
    category VARCHAR(120) NULL,
    issuing_authority VARCHAR(160) NULL,
    document_number VARCHAR(120) NULL,
    issued_date DATE NULL,
    expiration_date DATE NULL,
    notes TEXT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT UNSIGNED NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_document_vault_type (document_type),
    INDEX idx_document_vault_expiration (expiration_date),
    INDEX idx_document_vault_uploaded_by (uploaded_by),
    CONSTRAINT fk_document_vault_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE custom_roles
SET permissions = JSON_ARRAY_APPEND(permissions, '$', 'documents.*')
WHERE name = 'manager'
  AND is_system = 1
  AND JSON_CONTAINS(permissions, JSON_QUOTE('documents.*')) = 0;
