-- Migration: Create public estimate requests table
-- Stores estimate requests submitted through public-facing form

CREATE TABLE IF NOT EXISTS estimate_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Contact Information
    name VARCHAR(160) NOT NULL,
    email VARCHAR(160) NOT NULL,
    phone VARCHAR(30) NOT NULL,

    -- Customer Address
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(50) NOT NULL,
    zip VARCHAR(20) NOT NULL,

    -- Service Address
    service_address_same_as_customer TINYINT(1) DEFAULT 1,
    service_address VARCHAR(255) NULL,
    service_city VARCHAR(100) NULL,
    service_state VARCHAR(50) NULL,
    service_zip VARCHAR(20) NULL,

    -- Vehicle Information
    vehicle_year SMALLINT NULL,
    vehicle_make VARCHAR(120) NULL,
    vehicle_model VARCHAR(120) NULL,
    vin VARCHAR(30) NULL,
    license_plate VARCHAR(30) NULL,

    -- Service Request
    service_type_id INT UNSIGNED NULL, -- Fixed typo
    service_type_name VARCHAR(120) NULL COMMENT 'Stored in case service type is deleted',
    description TEXT NULL,

    -- Status and Processing
    status ENUM('pending', 'contacted', 'estimated', 'declined', 'converted') NOT NULL DEFAULT 'pending',
    estimate_id INT UNSIGNED NULL COMMENT 'Link to created estimate', -- Fixed: added UNSIGNED
    customer_id INT UNSIGNED NULL COMMENT 'Link if customer is created/matched', -- Recommended for consistency
    vehicle_id INT UNSIGNED NULL COMMENT 'Link if vehicle is created', -- Recommended for consistency

    -- Metadata
    source VARCHAR(50) DEFAULT 'website' COMMENT 'Form submission source',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,

    -- Staff Notes
    internal_notes TEXT NULL,
    contacted_at DATETIME NULL,
    contacted_by INT UNSIGNED NULL, -- Fixed: added UNSIGNED

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_estimate_request_status (status),
    INDEX idx_estimate_request_created (created_at),
    INDEX idx_estimate_request_email (email),
    INDEX idx_estimate_request_estimate (estimate_id),
    
    -- Constraints will now work because types match
    CONSTRAINT fk_est_req_service_type FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE SET NULL,
    CONSTRAINT fk_est_req_estimate FOREIGN KEY (estimate_id) REFERENCES estimates(id) ON DELETE SET NULL,
    CONSTRAINT fk_est_req_contacted_by FOREIGN KEY (contacted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table for storing photos uploaded with estimate requests
CREATE TABLE IF NOT EXISTS estimate_request_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_estimate_request_media_request (request_id),
    FOREIGN KEY (request_id) REFERENCES estimate_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
