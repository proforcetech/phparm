-- Repair migration: fix signed-vs-unsigned FK columns left behind by the
-- buggy first version of migrations 142–151.
--
-- WHY THIS EXISTS
-- The original 142–151 declared FK columns as plain signed `INT` while
-- their referent tables (users, workorders, customers, …) all use
-- `INT UNSIGNED` for their primary keys. MySQL/MariaDB requires foreign
-- key column types to match exactly including signedness, so when those
-- migrations first ran they partially applied: the first table in each
-- file was created with the wrong (signed) types, then the FK ALTER
-- failed with errno 150 and aborted the rest of the file. The newer
-- versions of 142–151 declare these columns correctly, but on databases
-- where the broken tables already exist `CREATE TABLE IF NOT EXISTS`
-- no-ops and the FK ALTER still fails because the existing columns are
-- still signed.
--
-- This migration converts every known broken column to `INT UNSIGNED`
-- in place. Each ALTER is guarded on table existence so the file is a
-- no-op on a fresh install (where 142–151 will create the columns
-- correctly to begin with). Numbered `141a_` so it sorts between 141
-- and 142 — i.e. it runs immediately before the 142–151 retries on
-- existing deployments.
--
-- All target columns are NULLABLE in the deployed schema except
-- `sso_user_links.user_id`, which is NOT NULL.

-- helper: inline the table-existence guard for every ALTER MODIFY.

-- workorder_tech_requests --------------------------------------------------
SET @t := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorder_tech_requests');
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_tech_requests MODIFY COLUMN approved_by_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- workorder_reassignment_requests -----------------------------------------
SET @t := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorder_reassignment_requests');
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_reassignment_requests MODIFY COLUMN current_assignee_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_reassignment_requests MODIFY COLUMN proposed_assignee_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_reassignment_requests MODIFY COLUMN approved_by_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_reassignment_requests MODIFY COLUMN declined_by_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_reassignment_requests MODIFY COLUMN cancelled_by_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_reassignment_requests MODIFY COLUMN fulfilled_by_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_reassignment_requests MODIFY COLUMN new_assignee_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ticket_triage_suggestions -----------------------------------------------
SET @t := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'ticket_triage_suggestions');
SET @sql := IF(@t = 1, 'ALTER TABLE ticket_triage_suggestions MODIFY COLUMN suggested_category_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE ticket_triage_suggestions MODIFY COLUMN suggested_assignee_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE ticket_triage_suggestions MODIFY COLUMN accepted_by_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE ticket_triage_suggestions MODIFY COLUMN rejected_by_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- geo_fences --------------------------------------------------------------
SET @t := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'geo_fences');
SET @sql := IF(@t = 1, 'ALTER TABLE geo_fences MODIFY COLUMN customer_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE geo_fences MODIFY COLUMN workorder_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE geo_fences MODIFY COLUMN asset_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- voice_notes -------------------------------------------------------------
SET @t := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'voice_notes');
SET @sql := IF(@t = 1, 'ALTER TABLE voice_notes MODIFY COLUMN workorder_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE voice_notes MODIFY COLUMN ticket_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE voice_notes MODIFY COLUMN vehicle_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE voice_notes MODIFY COLUMN author_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- workorder_kit_installs --------------------------------------------------
SET @t := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorder_kit_installs');
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_kit_installs MODIFY COLUMN workorder_job_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_kit_installs MODIFY COLUMN bundle_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@t = 1, 'ALTER TABLE workorder_kit_installs MODIFY COLUMN installed_by_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- data_retention_runs -----------------------------------------------------
SET @t := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'data_retention_runs');
SET @sql := IF(@t = 1, 'ALTER TABLE data_retention_runs MODIFY COLUMN triggered_by_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- sso_user_links (user_id is NOT NULL — preserve that) --------------------
SET @t := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sso_user_links');
SET @sql := IF(@t = 1, 'ALTER TABLE sso_user_links MODIFY COLUMN user_id INT UNSIGNED NOT NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- saved_reports -----------------------------------------------------------
SET @t := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'saved_reports');
SET @sql := IF(@t = 1, 'ALTER TABLE saved_reports MODIFY COLUMN owner_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- third_party_integrations ------------------------------------------------
SET @t := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'third_party_integrations');
SET @sql := IF(@t = 1, 'ALTER TABLE third_party_integrations MODIFY COLUMN owner_user_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
