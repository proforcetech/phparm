ALTER TABLE notification_logs
    ADD COLUMN meta JSON NULL AFTER status,
    ADD COLUMN error_message TEXT NULL AFTER meta;
