-- Add protocol and sync tracking to partner dispatch requests

SET @has_partner_dispatch_protocol := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'partner_dispatch_requests' AND column_name = 'protocol'
);
SET @partner_dispatch_protocol_sql := IF(@has_partner_dispatch_protocol = 0,
    'ALTER TABLE partner_dispatch_requests ADD COLUMN protocol VARCHAR(40) NULL AFTER dispatch_reference',
    'SELECT 1');
PREPARE partner_dispatch_protocol_stmt FROM @partner_dispatch_protocol_sql;
EXECUTE partner_dispatch_protocol_stmt;
DEALLOCATE PREPARE partner_dispatch_protocol_stmt;

SET @has_partner_dispatch_accepted_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'partner_dispatch_requests' AND column_name = 'accepted_at'
);
SET @partner_dispatch_accepted_at_sql := IF(@has_partner_dispatch_accepted_at = 0,
    'ALTER TABLE partner_dispatch_requests ADD COLUMN accepted_at TIMESTAMP NULL AFTER processed_at',
    'SELECT 1');
PREPARE partner_dispatch_accepted_at_stmt FROM @partner_dispatch_accepted_at_sql;
EXECUTE partner_dispatch_accepted_at_stmt;
DEALLOCATE PREPARE partner_dispatch_accepted_at_stmt;

SET @has_partner_dispatch_accepted_by := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'partner_dispatch_requests' AND column_name = 'accepted_by'
);
SET @partner_dispatch_accepted_by_sql := IF(@has_partner_dispatch_accepted_by = 0,
    'ALTER TABLE partner_dispatch_requests ADD COLUMN accepted_by INT UNSIGNED NULL AFTER accepted_at',
    'SELECT 1');
PREPARE partner_dispatch_accepted_by_stmt FROM @partner_dispatch_accepted_by_sql;
EXECUTE partner_dispatch_accepted_by_stmt;
DEALLOCATE PREPARE partner_dispatch_accepted_by_stmt;

SET @has_partner_dispatch_last_status := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'partner_dispatch_requests' AND column_name = 'last_partner_status'
);
SET @partner_dispatch_last_status_sql := IF(@has_partner_dispatch_last_status = 0,
    'ALTER TABLE partner_dispatch_requests ADD COLUMN last_partner_status VARCHAR(60) NULL AFTER accepted_by',
    'SELECT 1');
PREPARE partner_dispatch_last_status_stmt FROM @partner_dispatch_last_status_sql;
EXECUTE partner_dispatch_last_status_stmt;
DEALLOCATE PREPARE partner_dispatch_last_status_stmt;

SET @has_partner_dispatch_last_synced := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'partner_dispatch_requests' AND column_name = 'last_synced_at'
);
SET @partner_dispatch_last_synced_sql := IF(@has_partner_dispatch_last_synced = 0,
    'ALTER TABLE partner_dispatch_requests ADD COLUMN last_synced_at TIMESTAMP NULL AFTER last_partner_status',
    'SELECT 1');
PREPARE partner_dispatch_last_synced_stmt FROM @partner_dispatch_last_synced_sql;
EXECUTE partner_dispatch_last_synced_stmt;
DEALLOCATE PREPARE partner_dispatch_last_synced_stmt;

SET @has_partner_dispatch_sync_attempts := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'partner_dispatch_requests' AND column_name = 'sync_attempts'
);
SET @partner_dispatch_sync_attempts_sql := IF(@has_partner_dispatch_sync_attempts = 0,
    'ALTER TABLE partner_dispatch_requests ADD COLUMN sync_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_synced_at',
    'SELECT 1');
PREPARE partner_dispatch_sync_attempts_stmt FROM @partner_dispatch_sync_attempts_sql;
EXECUTE partner_dispatch_sync_attempts_stmt;
DEALLOCATE PREPARE partner_dispatch_sync_attempts_stmt;

SET @has_partner_dispatch_sync_error := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'partner_dispatch_requests' AND column_name = 'sync_error'
);
SET @partner_dispatch_sync_error_sql := IF(@has_partner_dispatch_sync_error = 0,
    'ALTER TABLE partner_dispatch_requests ADD COLUMN sync_error TEXT NULL AFTER sync_attempts',
    'SELECT 1');
PREPARE partner_dispatch_sync_error_stmt FROM @partner_dispatch_sync_error_sql;
EXECUTE partner_dispatch_sync_error_stmt;
DEALLOCATE PREPARE partner_dispatch_sync_error_stmt;

SET @has_fk_partner_dispatch_accepted_by := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'partner_dispatch_requests' AND constraint_name = 'fk_partner_dispatch_accepted_by'
);
SET @has_partner_dispatch_accepted_by_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'users'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'partner_dispatch_requests'
      AND c.column_name = 'accepted_by'
);
SET @fk_partner_dispatch_accepted_by_sql := IF(@has_fk_partner_dispatch_accepted_by = 0 AND @has_partner_dispatch_accepted_by_match = 1,
    'ALTER TABLE partner_dispatch_requests ADD CONSTRAINT fk_partner_dispatch_accepted_by FOREIGN KEY (accepted_by) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE fk_partner_dispatch_accepted_by_stmt FROM @fk_partner_dispatch_accepted_by_sql;
EXECUTE fk_partner_dispatch_accepted_by_stmt;
DEALLOCATE PREPARE fk_partner_dispatch_accepted_by_stmt;
