ALTER TABLE inspection_report_media
    ADD COLUMN client_token VARCHAR(64) NULL AFTER uploaded_by,
    ADD UNIQUE INDEX idx_inspection_media_client_token (report_id, client_token);

ALTER TABLE workorder_status_history
    ADD COLUMN client_event_id VARCHAR(64) NULL AFTER notes,
    ADD UNIQUE INDEX idx_workorder_status_event (workorder_id, client_event_id);
