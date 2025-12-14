-- CMS templates, settings, and cache tables
-- Adds the templates, settings, and cache tables for CMS functionality

CREATE TABLE IF NOT EXISTS IF NOT EXISTS cms_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    structure LONGTEXT NULL COMMENT 'Template structure/layout definition',
    default_css TEXT NULL COMMENT 'Default CSS styles for this template',
    default_js TEXT NULL COMMENT 'Default JavaScript for this template',
    is_active TINYINT(1) DEFAULT 1,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_templates_slug (slug),
    INDEX idx_cms_templates_is_active (is_active),
    INDEX idx_cms_templates_created_by (created_by),
    INDEX idx_cms_templates_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CMS settings table for configuration
CREATE TABLE IF NOT EXISTS IF NOT EXISTS cms_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cms_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CMS cache table for page/component caching
CREATE TABLE IF NOT EXISTS IF NOT EXISTS cms_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(255) NOT NULL UNIQUE,
    type VARCHAR(50) NULL COMMENT 'Cache type: page, component, template, etc.',
    cache_value LONGTEXT NULL,
    expires_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cms_cache_key (cache_key),
    INDEX idx_cms_cache_type (type),
    INDEX idx_cms_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert a default template
INSERT INTO cms_templates (name, slug, description, structure, is_active) VALUES
('Default', 'default', 'Default page template', '<div class="container">{{content}}</div>', 1);

-- Insert default settings
INSERT INTO cms_settings (setting_key, setting_value, setting_type, description) VALUES
('site_name', 'My Website', 'string', 'The name of the website'),
('site_description', 'Welcome to our website', 'string', 'Site meta description'),
('cache_enabled', 'true', 'boolean', 'Enable page caching'),
('cache_ttl', '3600', 'number', 'Default cache TTL in seconds');
