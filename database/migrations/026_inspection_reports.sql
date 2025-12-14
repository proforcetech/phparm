-- Inspection reports tables with media and signatures
CREATE TABLE IF NOT EXISTS inspection_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    customer_id INT NOT NULL,
    vehicle_id INT NULL,
    estimate_id INT NULL,
    appointment_id INT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    summary TEXT NULL,
    pdf_path VARCHAR(255) NULL,
    completed_by INT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inspection_report_customer (customer_id),
    INDEX idx_inspection_report_template (template_id)
);

CREATE TABLE IF NOT EXISTS inspection_report_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    template_item_id INT NOT NULL,
    label VARCHAR(160) NOT NULL,
    response TEXT NOT NULL,
    note TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inspection_item_report (report_id)
);

CREATE TABLE IF NOT EXISTS inspection_report_signatures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    signature_data LONGTEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inspection_signature_report (report_id)
);

CREATE TABLE IF NOT EXISTS inspection_report_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    type ENUM('image', 'video') NOT NULL,
    path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(160) NOT NULL,
    uploaded_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inspection_media_report (report_id)
);
