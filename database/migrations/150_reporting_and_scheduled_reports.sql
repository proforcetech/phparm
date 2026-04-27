-- Cross-cutting: reporting/BI dashboards (saved configurations, schedules,
-- execution audit).
--
-- Three tables, each addressing a separate need that has been solved
-- ad-hoc across the codebase up to this point:
--
--   saved_reports
--     A user-saved configuration of an existing report type. Reports
--     are not stored — only the *configuration* (the report key, the
--     filter/parameter JSON, the column visibility, the optional
--     drill-down preset). The catalog lookup of "what report types
--     exist" lives in code (ReportCatalogService) so adding a new
--     report doesn't require a migration. This table just lets a
--     manager say "save this aging-asset filter as 'Branch 4 - 90d+'"
--     and reload it on demand.
--
--   scheduled_reports
--     A saved_report can optionally be put on a cron-like schedule
--     ("monthly on the 1st at 7am, email it to ops@") with a CSV
--     of recipients and a delivery format (csv/json). last_run_at /
--     next_run_at are kept so the runner can pick up due rows
--     without re-parsing the cron expression on every tick. Output
--     is dispatched via the existing notification infrastructure.
--
--   report_executions
--     Every execution (manual or scheduled) writes a row: which
--     report key, what filters, the requesting user, started_at,
--     finished_at, status (succeeded/failed/running), output rows,
--     duration_ms, optional error message. This is BOTH an audit
--     trail and the data backing "execution history" UI. Rows are
--     kept until the retention policy reaps them (see Phase 10).
--
-- All three tables are idempotent under re-run via information_schema
-- guards on indexes and foreign keys.

CREATE TABLE IF NOT EXISTS saved_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    parameters JSON NULL,
    columns_visible JSON NULL,
    drill_down JSON NULL,
    owner_user_id INT UNSIGNED NULL,
    is_shared TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- "List a user's saved reports" — the user dashboard's primary read.
SET @idx_saved_owner := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'saved_reports'
      AND index_name = 'idx_saved_reports_owner');
SET @sql := IF(@idx_saved_owner = 0,
    'CREATE INDEX idx_saved_reports_owner ON saved_reports (owner_user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "List shared saved reports of type X" — the catalog page.
SET @idx_saved_key := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'saved_reports'
      AND index_name = 'idx_saved_reports_key');
SET @sql := IF(@idx_saved_key = 0,
    'CREATE INDEX idx_saved_reports_key ON saved_reports (report_key)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_users := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'saved_reports'
      AND constraint_name = 'fk_saved_reports_owner');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE saved_reports ADD CONSTRAINT fk_saved_reports_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


CREATE TABLE IF NOT EXISTS scheduled_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    saved_report_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    cron_expression VARCHAR(80) NOT NULL,
    timezone VARCHAR(60) NOT NULL DEFAULT 'UTC',
    output_format VARCHAR(16) NOT NULL DEFAULT 'csv',
    recipients TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL,
    last_status VARCHAR(20) NULL,
    last_error TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- "Find due schedules" — the scheduled-reports cron's primary scan.
SET @idx_sched_next := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'scheduled_reports'
      AND index_name = 'idx_scheduled_reports_next');
SET @sql := IF(@idx_sched_next = 0,
    'CREATE INDEX idx_scheduled_reports_next ON scheduled_reports (is_active, next_run_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "All schedules for a saved report" — the saved-report detail view.
SET @idx_sched_saved := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'scheduled_reports'
      AND index_name = 'idx_scheduled_reports_saved');
SET @sql := IF(@idx_sched_saved = 0,
    'CREATE INDEX idx_scheduled_reports_saved ON scheduled_reports (saved_report_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_saved := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'saved_reports');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'scheduled_reports'
      AND constraint_name = 'fk_scheduled_reports_saved');
SET @sql := IF(@has_saved = 1 AND @has_fk = 0,
    'ALTER TABLE scheduled_reports ADD CONSTRAINT fk_scheduled_reports_saved FOREIGN KEY (saved_report_id) REFERENCES saved_reports(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'scheduled_reports'
      AND constraint_name = 'fk_scheduled_reports_creator');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE scheduled_reports ADD CONSTRAINT fk_scheduled_reports_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


CREATE TABLE IF NOT EXISTS report_executions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_key VARCHAR(80) NOT NULL,
    saved_report_id INT UNSIGNED NULL,
    scheduled_report_id INT UNSIGNED NULL,
    triggered_by VARCHAR(20) NOT NULL DEFAULT 'manual',
    user_id INT UNSIGNED NULL,
    parameters JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    row_count INT NULL,
    duration_ms INT NULL,
    error_message TEXT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL
);

-- "Recent executions of a saved report" — execution-history UI.
SET @idx_exec_saved := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND index_name = 'idx_report_executions_saved');
SET @sql := IF(@idx_exec_saved = 0,
    'CREATE INDEX idx_report_executions_saved ON report_executions (saved_report_id, started_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Recent executions of a schedule" — schedule-history UI.
SET @idx_exec_sched := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND index_name = 'idx_report_executions_schedule');
SET @sql := IF(@idx_exec_sched = 0,
    'CREATE INDEX idx_report_executions_schedule ON report_executions (scheduled_report_id, started_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Recent executions across the system" — admin oversight.
SET @idx_exec_started := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND index_name = 'idx_report_executions_started');
SET @sql := IF(@idx_exec_started = 0,
    'CREATE INDEX idx_report_executions_started ON report_executions (started_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Find still-running executions" — used by the scheduled runner to
-- spot stuck rows (e.g. process crashed mid-execution).
SET @idx_exec_status := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND index_name = 'idx_report_executions_status');
SET @sql := IF(@idx_exec_status = 0,
    'CREATE INDEX idx_report_executions_status ON report_executions (status, started_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND constraint_name = 'fk_report_executions_saved');
SET @sql := IF(@has_saved = 1 AND @has_fk = 0,
    'ALTER TABLE report_executions ADD CONSTRAINT fk_report_executions_saved FOREIGN KEY (saved_report_id) REFERENCES saved_reports(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_sched := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'scheduled_reports');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND constraint_name = 'fk_report_executions_schedule');
SET @sql := IF(@has_sched = 1 AND @has_fk = 0,
    'ALTER TABLE report_executions ADD CONSTRAINT fk_report_executions_schedule FOREIGN KEY (scheduled_report_id) REFERENCES scheduled_reports(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'report_executions'
      AND constraint_name = 'fk_report_executions_user');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE report_executions ADD CONSTRAINT fk_report_executions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
