-- Migration: Job evidence checkpoints, damage reports, and signatures

CREATE TABLE IF NOT EXISTS job_checkpoint_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    checkpoint_type VARCHAR(30) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_checkpoint_job (workorder_job_id),
    INDEX idx_job_checkpoint_type (checkpoint_type),
    CONSTRAINT fk_job_checkpoint_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_checkpoint_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_damage_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    diagram_points JSON NOT NULL,
    notes TEXT NULL,
    reported_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_damage_job (workorder_job_id),
    CONSTRAINT fk_job_damage_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_job_damage_reported_by FOREIGN KEY (reported_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_signatures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_job_id INT UNSIGNED NOT NULL,
    signature_type VARCHAR(40) NOT NULL DEFAULT 'authorization',
    signer_name VARCHAR(160) NOT NULL,
    signer_email VARCHAR(160) NULL,
    signature_data MEDIUMTEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_fingerprint VARCHAR(255) NULL,
    document_hash VARCHAR(64) NULL,
    legal_consent TINYINT(1) NOT NULL DEFAULT 0,
    consent_text TEXT NULL,
    comment TEXT NULL,
    signed_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_job_signature_job (workorder_job_id),
    INDEX idx_job_signature_type (signature_type),
    CONSTRAINT fk_job_signature_job FOREIGN KEY (workorder_job_id) REFERENCES workorder_jobs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
