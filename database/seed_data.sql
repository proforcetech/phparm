-- Seed data for development and demo environments
-- Run with: mysql -u phparm_user -p phparm < database/seed_data.sql

START TRANSACTION;

-- Role seeds are handled by migrations but are repeated here for idempotency
INSERT INTO roles (name, description) VALUES
    ('admin', 'Full control across all modules'),
    ('manager', 'Manage shop operations and reporting'),
    ('technician', 'Work estimates, inspections, and time entries'),
    ('customer', 'Customer portal access')
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- Core users
INSERT INTO users (id, name, email, password, role, email_verified, created_at, updated_at) VALUES
    (1, 'Admin User', 'admin@phparm.local', '$2y$12$zxd14vBpGjir9eta3bJUx.zwPVp3xoKXABPUaIQRotwg6dXsBcYcO', 'admin', 1, NOW(), NOW()),
    (2, 'Shop Manager', 'manager@phparm.local', '$2y$12$zxd14vBpGjir9eta3bJUx.zwPVp3xoKXABPUaIQRotwg6dXsBcYcO', 'manager', 1, NOW(), NOW()),
    (3, 'Terry Technician', 'tech@phparm.local', '$2y$12$zxd14vBpGjir9eta3bJUx.zwPVp3xoKXABPUaIQRotwg6dXsBcYcO', 'technician', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    password = VALUES(password),
    role = VALUES(role),
    email_verified = VALUES(email_verified),
    updated_at = NOW();

-- Service types with visual metadata
INSERT INTO service_types (name, alias, color, icon, description, active, display_order, created_at, updated_at) VALUES
    ('General Service', 'general', '#2563eb', 'wrench', 'Default bucket for records without an assigned service type', 1, 1, NOW(), NOW()),
    ('Oil Change', 'oil_change', '#f59e0b', 'oil', 'Engine oil and filter changes', 1, 2, NOW(), NOW()),
    ('Brake Service', 'brake_service', '#ef4444', 'car-brake', 'Brake pad and rotor service', 1, 3, NOW(), NOW()),
    ('Diagnostics', 'diagnostics', '#10b981', 'stethoscope', 'Electrical and check-engine diagnostics', 1, 4, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    color = VALUES(color),
    icon = VALUES(icon),
    active = VALUES(active),
    display_order = VALUES(display_order),
    updated_at = NOW();

SET @general_service_type_id := (SELECT id FROM service_types WHERE alias = 'general' LIMIT 1);
SET @oil_change_type_id := (SELECT id FROM service_types WHERE alias = 'oil_change' LIMIT 1);
SET @brake_service_type_id := (SELECT id FROM service_types WHERE alias = 'brake_service' LIMIT 1);

-- Application settings
INSERT INTO settings (`key`, `group`, `type`, `value`, description, created_at, updated_at) VALUES
    ('shop.name', 'shop', 'string', 'Demo Auto Shop', 'Display name used across communications and PDFs', NOW(), NOW()),
    ('shop.currency', 'shop', 'string', 'USD', 'Base currency for pricing and payments', NOW(), NOW()),
    ('pricing.tax_rate', 'pricing', 'decimal', '0.07', 'Default tax rate applied to taxable line items', NOW(), NOW()),
    ('notifications.from_email', 'notifications', 'string', 'noreply@example.com', 'Sender address for outbound emails', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    `value` = VALUES(`value`),
    description = VALUES(description),
    updated_at = NOW();

-- Demo customers
DELETE FROM customers WHERE id IN (1001, 1002);
INSERT INTO customers (id, first_name, last_name, business_name, email, phone, street, city, state, postal_code, country, is_commercial, tax_exempt, notes, external_reference, created_at, updated_at) VALUES
    (1001, 'Jane', 'Driver', NULL, 'jane.driver@example.com', '+155555501', '123 Maple St', 'Springfield', 'IL', '62701', 'USA', 0, 0, 'Prefers Saturday appointments', 'CUST-JANE', NOW(), NOW()),
    (1002, 'Contoso', 'Logistics', 'Contoso Logistics LLC', 'fleet@contoso.test', '+155555502', '500 Freight Ave', 'Columbus', 'OH', '43004', 'USA', 1, 0, 'Fleet customer', 'CUST-CONTOSO', NOW(), NOW());

-- Vehicles tied to demo customers
DELETE FROM customer_vehicles WHERE id IN (2001, 2002);
INSERT INTO customer_vehicles (id, customer_id, vehicle_master_id, year, make, model, engine, transmission, drive, trim, vin, license_plate, notes, created_at, updated_at) VALUES
    (2001, 1001, NULL, 2018, 'Subaru', 'Forester', '2.5L H4', 'Automatic', 'AWD', 'Premium', 'JF2SJAAC7JH123456', 'JDN-1234', 'Oil seep at valve cover noted on intake', NOW(), NOW()),
    (2002, 1002, NULL, 2020, 'Ford', 'Transit', '3.5L V6', 'Automatic', 'RWD', '250 Cargo', '1FTBR1C8XLKA98765', 'FLEET-42', 'Fleet vehicle with installed telematics', NOW(), NOW());

-- Estimates with jobs and line items
DELETE FROM estimates WHERE id IN (3001, 3002);
INSERT INTO estimates (id, number, customer_id, vehicle_id, status, technician_id, expiration_date, subtotal, tax, call_out_fee, mileage_total, discounts, grand_total, internal_notes, customer_notes, created_at, updated_at) VALUES
    (3001, 'EST-0001', 1001, 2001, 'draft', 3, DATE_ADD(CURDATE(), INTERVAL 14 DAY), 180.00, 12.60, 0, 0, 0, 192.60, 'Check rear brakes during visit', 'Please confirm OEM pads', NOW(), NOW()),
    (3002, 'EST-0002', 1002, 2002, 'sent', 3, DATE_ADD(CURDATE(), INTERVAL 30 DAY), 95.00, 6.65, 25.00, 0, 0, 126.65, 'Mobile service request', 'Submit PO before scheduling', NOW(), NOW());

DELETE FROM estimate_jobs WHERE id IN (3101, 3102, 3103);
INSERT INTO estimate_jobs (id, estimate_id, service_type_id, title, notes, reference, customer_status, subtotal, tax, total) VALUES
    (3101, 3001, @brake_service_type_id, 'Rear Brake Service', 'Inspect calipers and hardware', 'JOB-BRK-1', 'pending', 150.00, 10.50, 160.50),
    (3102, 3002, @oil_change_type_id, 'Mobile Oil Change', 'Include multi-point inspection', 'JOB-OIL-1', 'approved', 95.00, 6.65, 101.65),
    (3103, 3001, @general_service_type_id, 'Diagnostic Time', 'Investigate intermittent squeal', 'JOB-DIAG-1', 'pending', 30.00, 2.10, 32.10);

DELETE FROM estimate_items WHERE id IN (3201, 3202, 3203, 3204);
INSERT INTO estimate_items (id, estimate_job_id, type, description, quantity, unit_price, taxable, line_total) VALUES
    (3201, 3101, 'labor', 'Replace rear brake pads', 1, 120.00, 1, 120.00),
    (3202, 3101, 'part', 'Ceramic brake pad set', 1, 30.00, 1, 30.00),
    (3203, 3102, 'labor', 'Perform oil and filter change', 1, 70.00, 1, 70.00),
    (3204, 3102, 'part', 'Full synthetic oil and filter', 1, 25.00, 1, 25.00);

-- Invoices mapped to estimates
DELETE FROM invoices WHERE id IN (4001);
INSERT INTO invoices (id, number, customer_id, vehicle_id, estimate_id, service_type_id, status, issue_date, due_date, subtotal, tax, total, amount_paid, balance_due, created_at, updated_at) VALUES
    (4001, 'INV-0001', 1002, 2002, 3002, @oil_change_type_id, 'open', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 95.00, 6.65, 101.65, 0.00, 101.65, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    status = VALUES(status),
    subtotal = VALUES(subtotal),
    tax = VALUES(tax),
    total = VALUES(total),
    balance_due = VALUES(balance_due),
    updated_at = NOW();

DELETE FROM invoice_items WHERE id IN (4101, 4102);
INSERT INTO invoice_items (id, invoice_id, type, description, quantity, unit_price, taxable, line_total) VALUES
    (4101, 4001, 'labor', 'Perform oil and filter change', 1, 70.00, 1, 70.00),
    (4102, 4001, 'part', 'Full synthetic oil and filter', 1, 25.00, 1, 25.00);

-- Example time entry for technician
DELETE FROM time_entries WHERE id IN (5001);
INSERT INTO time_entries (id, technician_id, estimate_job_id, started_at, ended_at, duration_minutes, start_latitude, start_longitude, end_latitude, end_longitude, manual_override, notes) VALUES
    (5001, 3, 3102, DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 90 MINUTE), 30.0, 40.7128, -74.0060, 40.7128, -74.0060, 0, 'Travel and setup for mobile oil change');

COMMIT;
