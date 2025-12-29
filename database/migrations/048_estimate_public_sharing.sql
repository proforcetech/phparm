-- Migration: Add tables for public estimate sharing functionality
-- These tables support the share via email/SMS feature

-- Create estimate_public_links table for shareable estimate links
CREATE TABLE IF NOT EXISTS estimate_public_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    short_code VARCHAR(20) NOT NULL,
    expires_at DATETIME NULL,
    last_accessed_at DATETIME NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE KEY uk_estimate_public_links_hash (token_hash),
    UNIQUE KEY uk_estimate_public_links_short (short_code),
    INDEX idx_estimate_public_links_estimate (estimate_id),
    CONSTRAINT fk_estimate_public_links_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create estimate_public_comments table for customer comments via public link
CREATE TABLE IF NOT EXISTS estimate_public_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_estimate_public_comments_estimate (estimate_id),
    CONSTRAINT fk_estimate_public_comments_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create estimate_job_feedback table for job-level customer feedback
CREATE TABLE IF NOT EXISTS estimate_job_feedback (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    job_id INT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NULL,
    INDEX idx_estimate_job_feedback_estimate (estimate_id),
    INDEX idx_estimate_job_feedback_job (job_id),
    CONSTRAINT fk_estimate_job_feedback_estimate FOREIGN KEY (estimate_id) REFERENCES estimates (id),
    CONSTRAINT fk_estimate_job_feedback_job FOREIGN KEY (job_id) REFERENCES estimate_jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
