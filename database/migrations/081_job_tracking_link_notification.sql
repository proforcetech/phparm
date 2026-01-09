-- Migration: Job tracking link notification template

INSERT INTO notification_templates (template_key, name, channel, subject, body, created_at, updated_at)
VALUES (
    'job_tracking_link',
    'Job Tracking Link - Customer SMS',
    'sms',
    'Tracking Link',
    'Hi {customer_name}, track your service for workorder {workorder_number} here: {job_tracking_link}',
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    channel = VALUES(channel),
    subject = VALUES(subject),
    body = VALUES(body),
    updated_at = NOW();
