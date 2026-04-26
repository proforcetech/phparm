-- Cross-cutting: third-party platform integrations (QuickBooks/Xero,
-- mapping, IoT telematics, telecom monitoring, access control).
--
-- Three tables, idempotent, separate from the existing partner_*
-- dispatch tables (which model inbound dispatch requests from
-- AAA/Geico/Agero — a different concern that happens to share the
-- word "integration"):
--
--   third_party_integrations
--     One row per registered provider connection. The catalog of
--     supported providers lives in code (IntegrationAdapterRegistry),
--     so adding a new vendor is a code change, not a migration.
--     `credentials` holds the encrypted-at-rest secret blob (sodium
--     secretbox, base64-wrapped) — never queried, only fetched whole
--     when an adapter call is being prepared. `settings` is plaintext
--     JSON for non-secret tunables (env=sandbox|production, region,
--     default account ids, etc.). `status` is the connection lifecycle
--     state (pending|connected|error|disabled). `last_sync_*` is denorm
--     so the admin UI can render a fleet view without hitting the log
--     table for every row.
--
--   integration_sync_logs
--     Append-only history of each sync attempt (manual, scheduled, or
--     webhook-triggered). Mirrors report_executions in spirit: status,
--     row counts, duration, error message, started_at/finished_at.
--     Reaped by retention.
--
--   integration_webhook_events
--     Inbound webhook receipts. Many providers (Xero, accounting,
--     access-control gates) push events instead of being polled. We
--     store the raw payload + a sha256 dedup hash so a re-delivered
--     webhook is processed exactly once. Status flows
--     received → processed → failed; failed rows can be retried.

CREATE TABLE IF NOT EXISTS third_party_integrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider_key VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    credentials TEXT NULL,
    settings JSON NULL,
    sync_cadence_minutes INT NULL,
    last_sync_at DATETIME NULL,
    last_sync_status VARCHAR(20) NULL,
    last_sync_error TEXT NULL,
    next_sync_at DATETIME NULL,
    owner_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- "List all integrations of category X" — admin filtering by accounting/mapping/etc.
SET @idx_int_cat := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'third_party_integrations'
      AND index_name = 'idx_third_party_integrations_category');
SET @sql := IF(@idx_int_cat = 0,
    'CREATE INDEX idx_third_party_integrations_category ON third_party_integrations (category, status)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Provider lookup by key" — used during connect / webhook routing.
SET @idx_int_key := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'third_party_integrations'
      AND index_name = 'idx_third_party_integrations_provider');
SET @sql := IF(@idx_int_key = 0,
    'CREATE INDEX idx_third_party_integrations_provider ON third_party_integrations (provider_key)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Find integrations due for periodic sync" — the integration-sync cron's primary scan.
SET @idx_int_next := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'third_party_integrations'
      AND index_name = 'idx_third_party_integrations_next_sync');
SET @sql := IF(@idx_int_next = 0,
    'CREATE INDEX idx_third_party_integrations_next_sync ON third_party_integrations (status, next_sync_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_users := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'third_party_integrations'
      AND constraint_name = 'fk_third_party_integrations_owner');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE third_party_integrations ADD CONSTRAINT fk_third_party_integrations_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


CREATE TABLE IF NOT EXISTS integration_sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    integration_id INT NOT NULL,
    triggered_by VARCHAR(20) NOT NULL DEFAULT 'manual',
    user_id INT NULL,
    direction VARCHAR(16) NOT NULL DEFAULT 'pull',
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    records_in INT NULL,
    records_out INT NULL,
    duration_ms INT NULL,
    error_message TEXT NULL,
    summary JSON NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL
);

-- "Recent syncs of an integration" — the admin detail page.
SET @idx_log_int := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'integration_sync_logs'
      AND index_name = 'idx_integration_sync_logs_integration');
SET @sql := IF(@idx_log_int = 0,
    'CREATE INDEX idx_integration_sync_logs_integration ON integration_sync_logs (integration_id, started_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Recent failures across the system" — alerting/dashboard view.
SET @idx_log_status := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'integration_sync_logs'
      AND index_name = 'idx_integration_sync_logs_status');
SET @sql := IF(@idx_log_status = 0,
    'CREATE INDEX idx_integration_sync_logs_status ON integration_sync_logs (status, started_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_int := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'third_party_integrations');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'integration_sync_logs'
      AND constraint_name = 'fk_integration_sync_logs_integration');
SET @sql := IF(@has_int = 1 AND @has_fk = 0,
    'ALTER TABLE integration_sync_logs ADD CONSTRAINT fk_integration_sync_logs_integration FOREIGN KEY (integration_id) REFERENCES third_party_integrations(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'integration_sync_logs'
      AND constraint_name = 'fk_integration_sync_logs_user');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE integration_sync_logs ADD CONSTRAINT fk_integration_sync_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


CREATE TABLE IF NOT EXISTS integration_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    integration_id INT NULL,
    provider_key VARCHAR(80) NOT NULL,
    event_type VARCHAR(120) NULL,
    external_id VARCHAR(160) NULL,
    payload_hash CHAR(64) NOT NULL,
    raw_payload LONGTEXT NULL,
    headers JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'received',
    error_message TEXT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL
);

-- Webhook redelivery dedup — same payload twice should land once.
SET @idx_evt_dedup := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'integration_webhook_events'
      AND index_name = 'idx_integration_webhook_events_dedup');
SET @sql := IF(@idx_evt_dedup = 0,
    'CREATE UNIQUE INDEX idx_integration_webhook_events_dedup ON integration_webhook_events (provider_key, payload_hash)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Recent events for an integration" — admin detail page.
SET @idx_evt_int := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'integration_webhook_events'
      AND index_name = 'idx_integration_webhook_events_integration');
SET @sql := IF(@idx_evt_int = 0,
    'CREATE INDEX idx_integration_webhook_events_integration ON integration_webhook_events (integration_id, received_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Find still-unprocessed events" — the webhook processor cron.
SET @idx_evt_status := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'integration_webhook_events'
      AND index_name = 'idx_integration_webhook_events_status');
SET @sql := IF(@idx_evt_status = 0,
    'CREATE INDEX idx_integration_webhook_events_status ON integration_webhook_events (status, received_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'integration_webhook_events'
      AND constraint_name = 'fk_integration_webhook_events_integration');
SET @sql := IF(@has_int = 1 AND @has_fk = 0,
    'ALTER TABLE integration_webhook_events ADD CONSTRAINT fk_integration_webhook_events_integration FOREIGN KEY (integration_id) REFERENCES third_party_integrations(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
