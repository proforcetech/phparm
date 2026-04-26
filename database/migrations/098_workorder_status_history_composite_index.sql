ALTER TABLE workorder_status_history
    ADD INDEX idx_workorder_status_history_workorder_status_created (workorder_id, to_status, created_at);
