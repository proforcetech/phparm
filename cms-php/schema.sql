-- FixItForUs PHP CMS Database Schema
-- MySQL 5.7+ / MariaDB 10.2+
--
-- This script will drop existing tables and recreate them.
-- Run with: mysql -u root -p < schema.sql

SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `fixitforus_cms`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `fixitforus_cms`;

-- Drop existing tables (in reverse dependency order)
DROP TABLE IF EXISTS `activity_log`;
DROP TABLE IF EXISTS `page_components`;
DROP TABLE IF EXISTS `pages`;
DROP TABLE IF EXISTS `templates`;
DROP TABLE IF EXISTS `components`;
DROP TABLE IF EXISTS `cache`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================
-- Users table for admin authentication
-- =============================================
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'editor', 'viewer') DEFAULT 'editor',
    `is_active` TINYINT(1) DEFAULT 1,
    `last_login` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_email` (`email`),
    INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Components table for reusable content blocks
-- (header, footer, navigation, sidebar, etc.)
-- =============================================
CREATE TABLE `components` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `type` ENUM('header', 'footer', 'navigation', 'sidebar', 'widget', 'custom') NOT NULL DEFAULT 'custom',
    `description` TEXT NULL,
    `content` LONGTEXT NOT NULL,
    `css` TEXT NULL,
    `javascript` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `cache_ttl` INT UNSIGNED DEFAULT 3600 COMMENT 'Cache time-to-live in seconds',
    `created_by` INT UNSIGNED NULL,
    `updated_by` INT UNSIGNED NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_type` (`type`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Templates table for page layouts
-- =============================================
CREATE TABLE `templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `structure` LONGTEXT NOT NULL COMMENT 'HTML structure with placeholders like {{header}}, {{content}}, {{footer}}',
    `default_css` TEXT NULL,
    `default_js` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `updated_by` INT UNSIGNED NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Pages table for site content
-- =============================================
CREATE TABLE `pages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `title` VARCHAR(255) NOT NULL,
    `meta_description` VARCHAR(500) NULL,
    `meta_keywords` VARCHAR(255) NULL,
    `template_id` INT UNSIGNED NULL,
    `content` LONGTEXT NOT NULL,
    `custom_css` TEXT NULL,
    `custom_js` TEXT NULL,
    `header_component_id` INT UNSIGNED NULL COMMENT 'Override default header',
    `footer_component_id` INT UNSIGNED NULL COMMENT 'Override default footer',
    `breadcrumbs` JSON NULL,
    `is_published` TINYINT(1) DEFAULT 0,
    `publish_date` DATETIME NULL,
    `cache_ttl` INT UNSIGNED DEFAULT 3600 COMMENT 'Cache time-to-live in seconds',
    `sort_order` INT DEFAULT 0,
    `parent_id` INT UNSIGNED NULL COMMENT 'For hierarchical pages',
    `created_by` INT UNSIGNED NULL,
    `updated_by` INT UNSIGNED NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_slug` (`slug`),
    INDEX `idx_is_published` (`is_published`),
    INDEX `idx_template` (`template_id`),
    INDEX `idx_parent` (`parent_id`),
    INDEX `idx_sort_order` (`sort_order`),
    INDEX `idx_header_component` (`header_component_id`),
    INDEX `idx_footer_component` (`footer_component_id`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Page-Component relationships (many-to-many)
-- For embedding additional components in pages
-- =============================================
CREATE TABLE `page_components` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `page_id` INT UNSIGNED NOT NULL,
    `component_id` INT UNSIGNED NOT NULL,
    `position` VARCHAR(50) NOT NULL DEFAULT 'content' COMMENT 'Position in template: sidebar, before_content, after_content, etc.',
    `sort_order` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_page_component_position` (`page_id`, `component_id`, `position`),
    INDEX `idx_page` (`page_id`),
    INDEX `idx_component` (`component_id`),
    INDEX `idx_position` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Cache table for database-level caching
-- =============================================
CREATE TABLE `cache` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cache_key` VARCHAR(255) NOT NULL UNIQUE,
    `cache_value` LONGTEXT NOT NULL,
    `cache_type` ENUM('component', 'page', 'template', 'full', 'query') DEFAULT 'page',
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cache_key` (`cache_key`),
    INDEX `idx_cache_type` (`cache_type`),
    INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Settings table for site configuration
-- =============================================
CREATE TABLE `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NULL,
    `setting_type` ENUM('string', 'integer', 'boolean', 'json', 'html') DEFAULT 'string',
    `description` VARCHAR(255) NULL,
    `is_public` TINYINT(1) DEFAULT 0 COMMENT 'Can be accessed without authentication',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_setting_key` (`setting_key`),
    INDEX `idx_is_public` (`is_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Activity log for audit trail
-- =============================================
CREATE TABLE `activity_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `action` VARCHAR(50) NOT NULL COMMENT 'create, update, delete, login, logout, etc.',
    `entity_type` VARCHAR(50) NOT NULL COMMENT 'page, component, template, user, etc.',
    `entity_id` INT UNSIGNED NULL,
    `old_values` JSON NULL,
    `new_values` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(500) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_entity` (`entity_type`, `entity_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- Add Foreign Keys (after all tables exist)
-- =============================================

-- Components foreign keys
ALTER TABLE `components`
    ADD CONSTRAINT `fk_components_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_components_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Templates foreign keys
ALTER TABLE `templates`
    ADD CONSTRAINT `fk_templates_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_templates_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Pages foreign keys
ALTER TABLE `pages`
    ADD CONSTRAINT `fk_pages_template` FOREIGN KEY (`template_id`) REFERENCES `templates`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_pages_header` FOREIGN KEY (`header_component_id`) REFERENCES `components`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_pages_footer` FOREIGN KEY (`footer_component_id`) REFERENCES `components`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_pages_parent` FOREIGN KEY (`parent_id`) REFERENCES `pages`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_pages_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_pages_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Page components foreign keys
ALTER TABLE `page_components`
    ADD CONSTRAINT `fk_page_components_page` FOREIGN KEY (`page_id`) REFERENCES `pages`(`id`) ON DELETE CASCADE,
    ADD CONSTRAINT `fk_page_components_component` FOREIGN KEY (`component_id`) REFERENCES `components`(`id`) ON DELETE CASCADE;

-- Activity log foreign keys
ALTER TABLE `activity_log`
    ADD CONSTRAINT `fk_activity_log_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- =============================================
-- Insert default admin user
-- Password: admin123 (CHANGE IN PRODUCTION!)
-- =============================================
INSERT INTO `users` (`username`, `email`, `password_hash`, `role`, `is_active`) VALUES
('admin', 'admin@fixitforus.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1);

-- =============================================
-- Insert default settings
-- =============================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `description`, `is_public`) VALUES
('site_name', 'FixItForUs', 'string', 'Site name displayed in header and title', 1),
('site_tagline', 'Mobile Auto Repair Services', 'string', 'Site tagline/description', 1),
('default_header_component', 'main-header', 'string', 'Default header component slug', 0),
('default_footer_component', 'main-footer', 'string', 'Default footer component slug', 0),
('default_template', 'default', 'string', 'Default page template slug', 0),
('cache_enabled', '1', 'boolean', 'Enable/disable caching', 0),
('cache_ttl', '3600', 'integer', 'Default cache TTL in seconds', 0),
('maintenance_mode', '0', 'boolean', 'Enable maintenance mode', 0),
('google_analytics_id', '', 'string', 'Google Analytics tracking ID', 0),
('contact_phone', '(616) 200-7121', 'string', 'Contact phone number', 1),
('contact_email', 'info@fixitforus.com', 'string', 'Contact email address', 1);

-- =============================================
-- Insert default template
-- =============================================
INSERT INTO `templates` (`name`, `slug`, `description`, `structure`, `is_active`) VALUES
('Default', 'default', 'Default page template with header, content, and footer', '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{meta_description}}">
    <meta name="keywords" content="{{meta_keywords}}">
    <title>{{title}} | FixItForUs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>{{default_css}}</style>
    <style>{{custom_css}}</style>
</head>
<body>
    {{header}}
    <main>
        {{breadcrumbs}}
        {{content}}
    </main>
    {{footer}}
    <script>{{default_js}}</script>
    <script>{{custom_js}}</script>
</body>
</html>', 1);

-- =============================================
-- Create event to clean expired cache (optional)
-- Note: This requires EVENT privilege
-- =============================================
-- DELIMITER //
-- CREATE EVENT IF NOT EXISTS `clean_expired_cache`
-- ON SCHEDULE EVERY 1 DAY
-- DO
-- BEGIN
--     DELETE FROM `cache` WHERE `expires_at` < NOW();
-- END//
-- DELIMITER ;
-- SET GLOBAL event_scheduler = ON;

SELECT 'Schema created successfully!' AS status;
