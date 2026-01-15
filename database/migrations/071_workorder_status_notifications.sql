-- Migration: Workorder Status-Driven Notifications
-- Allows configurable notification rules when workorder status changes

-- 1. Create notification_templates table
CREATE TABLE IF NOT EXISTS notification_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_key VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NULL,
    channel VARCHAR(50) NOT NULL DEFAULT 'email',
    subject VARCHAR(255) NULL,
    body TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nt_key (template_key),
    INDEX idx_nt_channel (channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create workorder_notification_rules table
CREATE TABLE IF NOT EXISTS workorder_notification_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_status VARCHAR(50) NOT NULL,
    from_status VARCHAR(50) NULL,
    recipient_type VARCHAR(50) NOT NULL,
    template_key VARCHAR(100) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wnr_status (to_status),
    INDEX idx_wnr_active (active),
    UNIQUE KEY uk_status_recipient (to_status, from_status, recipient_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Insert templates (One by one for stability)

-- Parts Pending
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_parts_pending', 'Workorder Parts Pending', 'email', 'Parts Required for Workorder {workorder_number}', 'Hello {recipient_name},\n\nWorkorder {workorder_number} for {vehicle_info} is now waiting for parts.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\n\nPlease review and order necessary parts.\n\nView workorder: {workorder_link}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_parts_pending_manager', 'Workorder Parts Pending - Manager', 'email', 'Parts Pending Alert: {workorder_number}', 'Manager Alert: Workorder {workorder_number} is waiting for parts.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\nTotal: ${grand_total}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- In Progress
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_in_progress', 'Workorder In Progress - Customer', 'email', 'Work Has Started on Your Vehicle - {workorder_number}', 'Hello {customer_name},\n\nGreat news! Work has begun on your {vehicle_info}.\n\nWorkorder: {workorder_number}\nTechnician: {technician_name}\n\nWe will notify you when the work is complete.\n\nThank you for your business!', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- On Hold
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_on_hold', 'Workorder On Hold - Manager', 'email', 'Workorder {workorder_number} Placed On Hold', 'Alert: Workorder {workorder_number} has been placed on hold.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\nPrevious Status: {from_status}\n\nPlease review and take action.', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_on_hold_tech', 'Workorder On Hold - Technician', 'email', 'Your Workorder {workorder_number} Is On Hold', 'Hello {technician_name},\n\nWorkorder {workorder_number} that you were assigned to has been placed on hold.\n\nVehicle: {vehicle_info}\nCustomer: {customer_name}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- Completed
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_completed', 'Workorder Completed - Customer', 'email', 'Your Vehicle Service Is Complete - {workorder_number}', 'Hello {customer_name},\n\nThe service on your {vehicle_info} has been completed!\n\nWorkorder: {workorder_number}\nTotal: ${grand_total}\n\nPlease contact us to schedule pickup.\n\nThank you for choosing us!', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_completed_manager', 'Workorder Completed - Manager', 'email', 'Workorder Completed: {workorder_number}', 'Workorder {workorder_number} has been marked as complete.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\nTotal: ${grand_total}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- Ready for Pickup
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_ready_pickup', 'Ready for Pickup - Customer Email', 'email', 'Your Vehicle Is Ready for Pickup! - {workorder_number}', 'Hello {customer_name},\n\nYour {vehicle_info} is ready for pickup!\n\nWorkorder: {workorder_number}\nTotal Due: ${grand_total}\n\nOur business hours are:\nMonday - Friday: 8:00 AM - 6:00 PM\nSaturday: 9:00 AM - 3:00 PM\n\nPlease bring a valid ID for vehicle release.\n\nThank you for your business!', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_ready_pickup_sms', 'Ready for Pickup - Customer SMS', 'sms', 'Vehicle Ready', 'Your {vehicle_info} is ready for pickup! Total: ${grand_total}. Workorder #{workorder_number}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- Awaiting Authorization
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_awaiting_auth', 'Awaiting Authorization - Customer Email', 'email', 'Authorization Required for Additional Work - {workorder_number}', 'Hello {customer_name},\n\nDuring service on your {vehicle_info}, we discovered additional work that requires your authorization.\n\nWorkorder: {workorder_number}\nEstimated Additional Cost: ${grand_total}\n\nPlease review and approve the estimate to proceed.\n\nView and approve: {portal_link}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_awaiting_auth_sms', 'Awaiting Authorization - Customer SMS', 'sms', 'Authorization Needed', 'Additional work needed on your {vehicle_info}. Please review estimate for Workorder #{workorder_number}. Total: ${grand_total}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- Cancelled
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_cancelled', 'Workorder Cancelled - Customer', 'email', 'Workorder {workorder_number} Has Been Cancelled', 'Hello {customer_name},\n\nWorkorder {workorder_number} for your {vehicle_info} has been cancelled.\n\nIf you have any questions, please contact us.\n\nThank you.', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES ('workorder_cancelled_tech', 'Workorder Cancelled - Technician', 'email', 'Workorder {workorder_number} Cancelled', 'Hello {technician_name},\n\nWorkorder {workorder_number} that you were assigned to has been cancelled.\n\nVehicle: {vehicle_info}\nCustomer: {customer_name}', NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body=VALUES(body), updated_at=NOW();

-- 4. Insert Rules
INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('parts_pending', NULL, 'role:manager', 'workorder_parts_pending_manager', 1);
INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('in_progress', NULL, 'customer', 'workorder_in_progress', 1);
INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('on_hold', NULL, 'role:manager', 'workorder_on_hold', 1);
INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('completed', NULL, 'customer', 'workorder_completed', 1);
INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('ready_for_pickup', NULL, 'customer', 'workorder_ready_pickup', 1);
INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('ready_for_pickup', NULL, 'customer_sms', 'workorder_ready_pickup_sms', 1);
INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('awaiting_authorization', NULL, 'customer', 'workorder_awaiting_auth', 1);
INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('awaiting_authorization', NULL, 'customer_sms', 'workorder_awaiting_auth_sms', 1);
INSERT IGNORE INTO workorder_notification_rules (to_status, from_status, recipient_type, template_key, active) VALUES ('cancelled', NULL, 'customer', 'workorder_cancelled', 1);
