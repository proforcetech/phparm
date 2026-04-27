-- Phase 10.7 of docs/expansion-plan.md — Voice-to-text notes for techs in
-- the field. Captures recorded audio + a transcript produced by a pluggable
-- TranscriberInterface implementation (HeuristicTranscriber ships with the
-- platform; production deployments swap in WhisperTranscriber/Deepgram/etc.).
--
-- Two tables:
--
--   voice_notes
--     The recording metadata + transcript. Audio file lives on the
--     filesystem at audio_path; the row carries enough metadata to render
--     the note inline in a workorder/ticket timeline without re-reading
--     the file.
--
--   voice_note_tags
--     Optional free-form taxonomy applied by the recorder or a reviewer.
--     Joined via voice_note_id; UNIQUE so duplicate adds collapse cleanly.
--
-- Lifecycle:
--   pending      → just created, audio uploaded, no transcript yet
--   transcribing → a worker (cron or manual trigger) is running the
--                  transcriber against the audio
--   completed    → transcript persisted; the note is fully realised
--   failed       → the transcriber raised; failure_reason captures why
--
-- Re-trying a failed note moves it back to pending; the cron picks it up
-- again. Completed notes are terminal — re-transcription is treated as a
-- separate operation that overwrites the transcript fields with the new
-- run's output (handled at the service layer rather than via a status
-- move, since the row is still "completed" before and after).
--
-- All FKs guarded on referenced-table existence so the migration is safe
-- on partially-set-up databases.

CREATE TABLE IF NOT EXISTS voice_notes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workorder_id INT UNSIGNED NULL,
    ticket_id INT UNSIGNED NULL,
    vehicle_id INT UNSIGNED NULL,
    author_user_id INT UNSIGNED NULL,
    audio_path VARCHAR(500) NOT NULL,
    audio_format VARCHAR(20) NOT NULL DEFAULT 'mp3',
    audio_size_bytes BIGINT NULL,
    duration_seconds DECIMAL(8,2) NULL,
    transcript TEXT NULL,
    transcript_language VARCHAR(20) NULL,
    transcription_provider VARCHAR(80) NOT NULL DEFAULT 'heuristic_v1',
    transcription_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    transcription_started_at DATETIME NULL,
    transcription_completed_at DATETIME NULL,
    transcription_failure_reason TEXT NULL,
    confidence DECIMAL(4,3) NULL,
    visibility VARCHAR(40) NOT NULL DEFAULT 'internal',
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- "All voice notes attached to this workorder, pinned first" — the WO
-- timeline render path. Pinned notes float because techs use them for
-- "this customer is hostile, two adults present" style operational notes
-- that subsequent visits need to see immediately.
SET @idx_wo := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND index_name = 'idx_vn_workorder');
SET @sql := IF(@idx_wo = 0,
    'CREATE INDEX idx_vn_workorder ON voice_notes (workorder_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Per-ticket attachment query (support-desk side mirrors the WO timeline).
SET @idx_tk := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND index_name = 'idx_vn_ticket');
SET @sql := IF(@idx_tk = 0,
    'CREATE INDEX idx_vn_ticket ON voice_notes (ticket_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "My voice notes" — the per-user notebook view.
SET @idx_au := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND index_name = 'idx_vn_author');
SET @sql := IF(@idx_au = 0,
    'CREATE INDEX idx_vn_author ON voice_notes (author_user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Pending transcription queue" — the cron worker scan path. Hot index;
-- without it the worker would table-scan every minute.
SET @idx_st := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND index_name = 'idx_vn_status');
SET @sql := IF(@idx_st = 0,
    'CREATE INDEX idx_vn_status ON voice_notes (transcription_status)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to workorders — CASCADE so deleting a WO sweeps its voice notes.
SET @wo_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorders');
SET @fk_wo := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND constraint_name = 'fk_vn_workorder');
SET @sql := IF(@wo_table > 0 AND @fk_wo = 0,
    'ALTER TABLE voice_notes ADD CONSTRAINT fk_vn_workorder
        FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to tickets — CASCADE so deleting a ticket sweeps its voice notes.
SET @tk_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'tickets');
SET @fk_tk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND constraint_name = 'fk_vn_ticket');
SET @sql := IF(@tk_table > 0 AND @fk_tk = 0,
    'ALTER TABLE voice_notes ADD CONSTRAINT fk_vn_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to vehicles — SET NULL because voice notes can outlive the vehicle
-- (e.g., notes about a totalled fleet unit are still useful retrospectively).
SET @veh_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'vehicles');
SET @fk_veh := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND constraint_name = 'fk_vn_vehicle');
SET @sql := IF(@veh_table > 0 AND @fk_veh = 0,
    'ALTER TABLE voice_notes ADD CONSTRAINT fk_vn_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users — SET NULL so deleting an author preserves the note record
-- with an orphaned author pointer (audit trail keeps the note text).
SET @users_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_au := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND constraint_name = 'fk_vn_author');
SET @sql := IF(@users_table > 0 AND @fk_au = 0,
    'ALTER TABLE voice_notes ADD CONSTRAINT fk_vn_author
        FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ─────────────────────────────────────────── voice_note_tags ────

CREATE TABLE IF NOT EXISTS voice_note_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    voice_note_id INT UNSIGNED NOT NULL,
    tag VARCHAR(80) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Per-note tag fetch (timeline render needs all tags for each note).
SET @idx_tg_vn := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_note_tags'
      AND index_name = 'idx_vnt_note');
SET @sql := IF(@idx_tg_vn = 0,
    'CREATE INDEX idx_vnt_note ON voice_note_tags (voice_note_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "All notes tagged X" — the tag-filtered search path.
SET @idx_tg_t := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_note_tags'
      AND index_name = 'idx_vnt_tag');
SET @sql := IF(@idx_tg_t = 0,
    'CREATE INDEX idx_vnt_tag ON voice_note_tags (tag)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- UNIQUE so duplicate tag inserts collapse.
SET @uq_tg := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_note_tags'
      AND index_name = 'uq_vnt_note_tag');
SET @sql := IF(@uq_tg = 0,
    'CREATE UNIQUE INDEX uq_vnt_note_tag ON voice_note_tags (voice_note_id, tag)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to voice_notes — CASCADE so deleting a note sweeps its tags.
SET @fk_tg := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_note_tags'
      AND constraint_name = 'fk_vnt_note');
SET @sql := IF(@fk_tg = 0,
    'ALTER TABLE voice_note_tags ADD CONSTRAINT fk_vnt_note
        FOREIGN KEY (voice_note_id) REFERENCES voice_notes(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
