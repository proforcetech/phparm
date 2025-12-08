-- Add reminder_sent_at column to appointments table
-- This tracks when appointment reminders were sent to avoid duplicates

ALTER TABLE appointments ADD COLUMN reminder_sent_at TIMESTAMP NULL AFTER notes;
CREATE INDEX idx_appointments_reminder ON appointments (status, scheduled_at, reminder_sent_at);
