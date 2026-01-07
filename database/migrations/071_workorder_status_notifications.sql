-- Migration: Workorder Status-Driven Notifications
-- Allows configurable notification rules when workorder status changes

CREATE TABLE IF NOT EXISTS workorder_notification_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_status VARCHAR(50) NOT NULL COMMENT 'Target status that triggers notification',
    from_status VARCHAR(50) NULL COMMENT 'Optional: only trigger if coming from this status',
    recipient_type VARCHAR(50) NOT NULL COMMENT 'customer, customer_sms, assigned_technician, role:manager, etc.',
    template_key VARCHAR(100) NOT NULL COMMENT 'Notification template key',
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wnr_status (to_status),
    INDEX idx_wnr_active (active),
    UNIQUE KEY uk_status_recipient (to_status, from_status, recipient_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add additional workorder statuses to support more granular workflow
-- Note: These are additive - they extend the existing status options
-- The Workorder model's ALLOWED_STATUSES constant should be updated to include these

-- Insert default notification templates for workorder status changes
INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at) VALUES
-- Parts Pending notifications
('workorder_parts_pending', 'Workorder Parts Pending', 'email',
 'Parts Required for Workorder {workorder_number}',
 'Hello {recipient_name},\n\nWorkorder {workorder_number} for {vehicle_info} is now waiting for parts.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\n\nPlease review and order necessary parts.\n\nView workorder: {workorder_link}',
 NOW(), NOW()),

('workorder_parts_pending_manager', 'Workorder Parts Pending - Manager', 'email',
 'Parts Pending Alert: {workorder_number}',
 'Manager Alert: Workorder {workorder_number} is waiting for parts.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\nTotal: ${grand_total}',
 NOW(), NOW()),

-- In Progress notifications
('workorder_in_progress', 'Workorder In Progress - Customer', 'email',
 'Work Has Started on Your Vehicle - {workorder_number}',
 'Hello {customer_name},\n\nGreat news! Work has begun on your {vehicle_info}.\n\nWorkorder: {workorder_number}\nTechnician: {technician_name}\n\nWe will notify you when the work is complete.\n\nThank you for your business!',
 NOW(), NOW()),

-- On Hold notifications
('workorder_on_hold', 'Workorder On Hold - Manager', 'email',
 'Workorder {workorder_number} Placed On Hold',
 'Alert: Workorder {workorder_number} has been placed on hold.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\nPrevious Status: {from_status}\n\nPlease review and take action.',
 NOW(), NOW()),

('workorder_on_hold_tech', 'Workorder On Hold - Technician', 'email',
 'Your Workorder {workorder_number} Is On Hold',
 'Hello {technician_name},\n\nWorkorder {workorder_number} that you were assigned to has been placed on hold.\n\nVehicle: {vehicle_info}\nCustomer: {customer_name}',
 NOW(), NOW()),

-- Completed notifications
('workorder_completed', 'Workorder Completed - Customer', 'email',
 'Your Vehicle Service Is Complete - {workorder_number}',
 'Hello {customer_name},\n\nThe service on your {vehicle_info} has been completed!\n\nWorkorder: {workorder_number}\nTotal: ${grand_total}\n\nPlease contact us to schedule pickup.\n\nThank you for choosing us!',
 NOW(), NOW()),

('workorder_completed_manager', 'Workorder Completed - Manager', 'email',
 'Workorder Completed: {workorder_number}',
 'Workorder {workorder_number} has been marked as complete.\n\nCustomer: {customer_name}\nVehicle: {vehicle_info}\nTotal: ${grand_total}',
 NOW(), NOW()),

-- Ready for Pickup notifications
('workorder_ready_pickup', 'Ready for Pickup - Customer Email', 'email',
 'Your Vehicle Is Ready for Pickup! - {workorder_number}',
 'Hello {customer_name},\n\nYour {vehicle_info} is ready for pickup!\n\nWorkorder: {workorder_number}\nTotal Due: ${grand_total}\n\nOur business hours are:\nMonday - Friday: 8:00 AM - 6:00 PM\nSaturday: 9:00 AM - 3:00 PM\n\nPlease bring a valid ID for vehicle release.\n\nThank you for your business!',
 NOW(), NOW()),

('workorder_ready_pickup_sms', 'Ready for Pickup - Customer SMS', 'sms',
 'Vehicle Ready',
 'Your {vehicle_info} is ready for pickup! Total: ${grand_total}. Workorder #{workorder_number}',
 NOW(), NOW()),

-- Awaiting Authorization notifications
('workorder_awaiting_auth', 'Awaiting Authorization - Customer Email', 'email',
 'Authorization Required for Additional Work - {workorder_number}',
 'Hello {customer_name},\n\nDuring service on your {vehicle_info}, we discovered additional work that requires your authorization.\n\nWorkorder: {workorder_number}\nEstimated Additional Cost: ${grand_total}\n\nPlease review and approve the estimate to proceed.\n\nView and approve: {portal_link}',
 NOW(), NOW()),

('workorder_awaiting_auth_sms', 'Awaiting Authorization - Customer SMS', 'sms',
 'Authorization Needed',
 'Additional work needed on your {vehicle_info}. Please review estimate for Workorder #{workorder_number}. Total: ${grand_total}',
 NOW(), NOW()),

-- Cancelled notifications
('workorder_cancelled', 'Workorder Cancelled - Customer', 'email',
 'Workorder {workorder_number} Has Been Cancelled',
 'Hello {customer_name},\n\nWorkorder {workorder_number} for your {vehicle_info} has been cancelled.\n\nIf you have any questions, please contact us.\n\nThank you.',
 NOW(), NOW()),

('workorder_cancelled_tech', 'Workorder Cancelled - Technician', 'email',
 'Workorder {workorder_number} Cancelled',
 'Hello {technician_name},\n\nWorkorder {workorder_number} that you were assigned to has been cancelled.\n\nVehicle: {vehicle_info}\nCustomer: {customer_name}',
 NOW(), NOW())

ON DUPLICATE KEY UPDATE updated_at = NOW();

-- Add extended status options to support more granular workflows
-- These would be added to Workorder::ALLOWED_STATUSES in the model
-- 'parts_pending', 'ready_for_pickup', 'awaiting_authorization', 'qc_required'
