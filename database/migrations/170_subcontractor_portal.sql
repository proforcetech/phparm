-- =============================================================================
-- Migration 170 — Subcontractor self-service portal (Phase 18 / C2)
--
-- Subcontractors are external vendors. We don't want to provision JWT staff
-- accounts for every electrician or plumber we hire occasionally. Instead,
-- dispatch issues a long-lived bearer token (random 32-byte secret, stored
-- hashed) which the sub uses from a dedicated public-facing portal page.
--
-- That single token authenticates them to:
--   - see only their own pending/active/recent assignments
--   - accept / decline / start / complete those assignments
--   - upload proof-of-delivery (POD) photos / signed paperwork
--
-- A token is bound to ONE subcontractor and is opaque to the rest of the
-- staff JWT stack. Revoke at any time without impacting other access.
--
-- Two tables:
--   1) subcontractor_portal_tokens — issued tokens (hashed) per sub
--   2) subcontractor_assignment_pods — uploaded POD/photo/signature blobs
--      keyed to a single subcontractor_assignments row
-- =============================================================================

CREATE TABLE IF NOT EXISTS subcontractor_portal_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subcontractor_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    label VARCHAR(120) NULL,
    expires_at DATETIME NULL,
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    revoked_at DATETIME NULL,
    revoked_reason VARCHAR(255) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sub_portal_token_hash (token_hash),
    INDEX idx_sub_portal_token_sub (subcontractor_id),
    INDEX idx_sub_portal_token_active (subcontractor_id, revoked_at, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: subcontractor_portal_tokens.subcontractor_id -> subcontractors(id) CASCADE
SET @sub_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'subcontractors');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_portal_tokens'
    AND constraint_name = 'fk_sub_portal_token_sub');
SET @sql := IF(@sub_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE subcontractor_portal_tokens ADD CONSTRAINT fk_sub_portal_token_sub FOREIGN KEY (subcontractor_id) REFERENCES subcontractors(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: subcontractor_portal_tokens.created_by_user_id -> users(id) SET NULL
SET @users_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_portal_tokens'
    AND constraint_name = 'fk_sub_portal_token_user');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE subcontractor_portal_tokens ADD CONSTRAINT fk_sub_portal_token_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- subcontractor_assignment_pods — uploaded proof-of-delivery / photos
-- -----------------------------------------------------------------------------
-- kind:
--   pod        — completed-work paperwork (signed work order, etc.)
--   photo      — before/after job photo
--   signature  — captured customer signature
--   note       — text-only note attached to a POD bundle
CREATE TABLE IF NOT EXISTS subcontractor_assignment_pods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    subcontractor_id INT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'pod',
    original_name VARCHAR(255) NULL,
    stored_path VARCHAR(512) NULL,
    mime_type VARCHAR(120) NULL,
    size_bytes INT UNSIGNED NULL,
    sha256 CHAR(64) NULL,
    notes TEXT NULL,
    uploaded_via_token_id BIGINT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    INDEX idx_sub_pod_assignment (assignment_id, deleted_at),
    INDEX idx_sub_pod_sub (subcontractor_id, deleted_at),
    INDEX idx_sub_pod_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: subcontractor_assignment_pods.assignment_id -> subcontractor_assignments(id) CASCADE
SET @assign_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_assignments');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_assignment_pods'
    AND constraint_name = 'fk_sub_pod_assignment');
SET @sql := IF(@assign_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE subcontractor_assignment_pods ADD CONSTRAINT fk_sub_pod_assignment FOREIGN KEY (assignment_id) REFERENCES subcontractor_assignments(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: subcontractor_assignment_pods.subcontractor_id -> subcontractors(id) CASCADE
SET @sub_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'subcontractors');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_assignment_pods'
    AND constraint_name = 'fk_sub_pod_sub');
SET @sql := IF(@sub_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE subcontractor_assignment_pods ADD CONSTRAINT fk_sub_pod_sub FOREIGN KEY (subcontractor_id) REFERENCES subcontractors(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: subcontractor_assignment_pods.uploaded_via_token_id -> subcontractor_portal_tokens(id) SET NULL
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_assignment_pods'
    AND constraint_name = 'fk_sub_pod_token');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE subcontractor_assignment_pods ADD CONSTRAINT fk_sub_pod_token FOREIGN KEY (uploaded_via_token_id) REFERENCES subcontractor_portal_tokens(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
