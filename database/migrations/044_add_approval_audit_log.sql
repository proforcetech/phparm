-- Migration: Enhanced audit trail for e-signing and approval workflow
-- This migration adds comprehensive audit logging for legal compliance

-- Create approval audit log for e-signing compliance
CREATE TABLE IF NOT EXISTS approval_audit_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(40) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR(60) NOT NULL,
    job_id INT UNSIGNED NULL,
    signer_name VARCHAR(160) NULL,
    signer_email VARCHAR(160) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    device_fingerprint VARCHAR(255) NULL,
    geo_location VARCHAR(255) NULL,
    signature_hash VARCHAR(64) NULL,
    document_hash VARCHAR(64) NULL,
    comment TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_approval_audit_entity (entity_type, entity_id),
    INDEX idx_approval_audit_action (action),
    INDEX idx_approval_audit_created (created_at),
    INDEX idx_approval_audit_signer (signer_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create estimate_signatures table for e-signing legal compliance
CREATE TABLE IF NOT EXISTS estimate_signatures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
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
    INDEX idx_estimate_signature_estimate (estimate_id),
    CONSTRAINT fk_estimate_signature_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create workorder_signatures table for workorder completions
CREATE TABLE IF NOT EXISTS workorder_signatures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NOT NULL,
    signature_type VARCHAR(40) NOT NULL DEFAULT 'completion',
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
    INDEX idx_workorder_signature_workorder (workorder_id),
    INDEX idx_workorder_signature_type (signature_type),
    CONSTRAINT fk_workorder_signature_workorder FOREIGN KEY (workorder_id) REFERENCES workorders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create estimate_job_rejection_reasons for detailed rejection tracking
CREATE TABLE IF NOT EXISTS estimate_job_rejections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    estimate_job_id INT UNSIGNED NOT NULL,
    rejection_reason VARCHAR(120) NULL,
    rejection_details TEXT NULL,
    rejected_by_name VARCHAR(160) NULL,
    rejected_by_email VARCHAR(160) NULL,
    ip_address VARCHAR(45) NULL,
    rejected_at DATETIME NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_job_rejection_estimate (estimate_id),
    INDEX idx_job_rejection_job (estimate_job_id),
    CONSTRAINT fk_job_rejection_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id),
    CONSTRAINT fk_job_rejection_job FOREIGN KEY (estimate_job_id) REFERENCES estimate_jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
