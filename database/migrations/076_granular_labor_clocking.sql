-- Migration: Granular Labor Clocking
-- Allows technicians to clock into specific tasks within a job for efficiency reporting

-- Labor tasks table - predefined tasks with flat-rate times
CREATE TABLE IF NOT EXISTS labor_tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    flat_rate_minutes DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Standard/expected time for this task',
    labor_rate DECIMAL(10,2) NULL COMMENT 'Optional override labor rate per hour',
    service_type_id INT UNSIGNED NULL COMMENT 'Optional link to service type',
    is_active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_labor_tasks_service_type (service_type_id),
    INDEX idx_labor_tasks_active (is_active),
    CONSTRAINT fk_labor_tasks_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add task_id and flat_rate_minutes to time_entries
ALTER TABLE time_entries
    ADD COLUMN task_id INT UNSIGNED NULL AFTER estimate_job_id,
    ADD COLUMN task_name VARCHAR(160) NULL AFTER task_id COMMENT 'Snapshot of task name at clock-in time',
    ADD COLUMN flat_rate_minutes DECIMAL(10,2) NULL AFTER task_name COMMENT 'Snapshot of expected time for efficiency calculation',
    ADD INDEX idx_time_entries_task (task_id),
    ADD CONSTRAINT fk_time_entries_task FOREIGN KEY (task_id) REFERENCES labor_tasks (id) ON DELETE SET NULL;

-- Add task tracking to time_adjustments for audit trail
ALTER TABLE time_adjustments
    ADD COLUMN previous_task_id INT UNSIGNED NULL AFTER previous_estimate_job_id,
    ADD COLUMN previous_task_name VARCHAR(160) NULL AFTER previous_task_id,
    ADD COLUMN previous_flat_rate_minutes DECIMAL(10,2) NULL AFTER previous_task_name,
    ADD COLUMN new_task_id INT UNSIGNED NULL AFTER new_estimate_job_id,
    ADD COLUMN new_task_name VARCHAR(160) NULL AFTER new_task_id,
    ADD COLUMN new_flat_rate_minutes DECIMAL(10,2) NULL AFTER new_task_name;

-- Insert common labor tasks with industry-standard flat rates
INSERT INTO labor_tasks (name, description, flat_rate_minutes, display_order) VALUES
('Oil Change', 'Standard engine oil and filter replacement', 30, 1),
('Brake Pad Replacement - Front', 'Front brake pad replacement', 60, 2),
('Brake Pad Replacement - Rear', 'Rear brake pad replacement', 60, 3),
('Brake Rotor Replacement - Front', 'Front brake rotor replacement (includes pads)', 90, 4),
('Brake Rotor Replacement - Rear', 'Rear brake rotor replacement (includes pads)', 90, 5),
('Tire Rotation', 'Rotate all four tires', 20, 6),
('Tire Balance', 'Balance all four tires', 30, 7),
('Tire Replacement', 'Mount and balance single tire', 20, 8),
('Battery Replacement', 'Replace vehicle battery', 15, 9),
('Spark Plug Replacement', 'Replace spark plugs (standard 4-cyl)', 60, 10),
('Air Filter Replacement', 'Replace engine air filter', 10, 11),
('Cabin Filter Replacement', 'Replace cabin air filter', 15, 12),
('Coolant Flush', 'Flush and refill cooling system', 45, 13),
('Transmission Fluid Change', 'Drain and refill transmission fluid', 45, 14),
('Brake Fluid Flush', 'Flush and refill brake fluid', 45, 15),
('Power Steering Flush', 'Flush and refill power steering fluid', 30, 16),
('Wheel Alignment', 'Four-wheel alignment', 60, 17),
('Diagnostic Scan', 'Computer diagnostic and code reading', 30, 18),
('Check Engine Light Diagnosis', 'Full diagnosis of check engine light issue', 60, 19),
('A/C Recharge', 'Recharge air conditioning system', 45, 20),
('Belt Replacement', 'Replace serpentine or drive belt', 45, 21),
('Alternator Replacement', 'Replace alternator', 90, 22),
('Starter Replacement', 'Replace starter motor', 90, 23),
('Water Pump Replacement', 'Replace water pump', 180, 24),
('Thermostat Replacement', 'Replace engine thermostat', 60, 25),
('Fuel Filter Replacement', 'Replace fuel filter', 30, 26),
('Wiper Blade Replacement', 'Replace windshield wiper blades', 5, 27),
('Light Bulb Replacement', 'Replace headlight/taillight bulb', 15, 28),
('Multi-Point Inspection', 'Comprehensive vehicle inspection', 30, 29),
('State Inspection', 'State safety and/or emissions inspection', 30, 30);

-- Create view for technician efficiency reporting
CREATE OR REPLACE VIEW technician_efficiency AS
SELECT
    te.technician_id,
    u.name AS technician_name,
    DATE(te.started_at) AS work_date,
    COUNT(te.id) AS tasks_completed,
    SUM(te.duration_minutes) AS actual_minutes,
    SUM(te.flat_rate_minutes) AS flat_rate_minutes,
    CASE
        WHEN SUM(te.duration_minutes) > 0 THEN
            ROUND((SUM(te.flat_rate_minutes) / SUM(te.duration_minutes)) * 100, 2)
        ELSE 0
    END AS efficiency_percentage,
    SUM(CASE WHEN te.duration_minutes <= te.flat_rate_minutes THEN 1 ELSE 0 END) AS under_time_count,
    SUM(CASE WHEN te.duration_minutes > te.flat_rate_minutes THEN 1 ELSE 0 END) AS over_time_count
FROM time_entries te
JOIN users u ON u.id = te.technician_id
WHERE te.ended_at IS NOT NULL
    AND te.status = 'approved'
    AND te.flat_rate_minutes IS NOT NULL
    AND te.flat_rate_minutes > 0
GROUP BY te.technician_id, u.name, DATE(te.started_at);
