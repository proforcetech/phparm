-- R-01 / AUD-063 — voice-note upload pipeline metadata.
--
-- Replaces the original "client passes audio_path as a JSON string" flow
-- (closed at the service layer in v2 by AUD-063's partial fix, but the
-- architectural cleanup was deferred to R-01) with a server-managed
-- multipart upload. The server now generates the storage path, sniffs
-- the MIME with finfo, caps the size, and hashes the bytes. The new
-- columns persist what the server observed so they can be audited later
-- and so the streaming endpoint can serve the file back with a
-- trustworthy Content-Type.
--
-- Column choices:
--   audio_mime          VARCHAR(64) — RFC 6838 form `type/subtype`. The
--                                     longest practical value (`audio/x-matroska`)
--                                     is well under 64; 64 leaves room for
--                                     vendor MIMEs without forcing a future
--                                     widening migration.
--   audio_sha256_hash   CHAR(64)   — hex sha256 (64 chars). Lets us
--                                     detect duplicate uploads + audit
--                                     integrity drift in storage.
--
-- We deliberately KEEP the existing `audio_format` column (extension
-- token like `mp3`) alongside `audio_mime` (sniffed content type). The
-- extension drives the on-disk filename suffix and the React audio tag's
-- format hint; the MIME is the authoritative content-type the server
-- saw. They're related but not redundant — extension can be `mp3` while
-- MIME is `audio/mpeg` (matching the IANA registration) or `audio/mp3`
-- (the de-facto value some browsers emit), and we want the actually-
-- observed string preserved verbatim.
--
-- audio_size_bytes is the existing column from migration 146; we reuse
-- it rather than introduce a near-duplicate `audio_bytes_size` so
-- existing read paths continue to work without renames.
--
-- Both new columns are NULLABLE so legacy rows (pre-R-01) continue to
-- load — the upload service will only populate them for new inserts.
-- The streaming endpoint tolerates NULL audio_mime by falling back to
-- application/octet-stream (which forces a download rather than inline
-- playback — acceptable for the small pre-R-01 backlog).

SET @col_mime := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND column_name = 'audio_mime');
SET @sql := IF(@col_mime = 0,
    'ALTER TABLE voice_notes ADD COLUMN audio_mime VARCHAR(64) NULL AFTER audio_format',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col_sha := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND column_name = 'audio_sha256_hash');
SET @sql := IF(@col_sha = 0,
    'ALTER TABLE voice_notes ADD COLUMN audio_sha256_hash CHAR(64) NULL AFTER audio_size_bytes',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Find existing uploads with the same bytes" — used by the dedupe
-- check in the upload service so a re-recorded file doesn't double-
-- charge storage. Not unique (different authors may legitimately upload
-- byte-identical "all clear" recordings).
SET @idx_sha := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'voice_notes'
      AND index_name = 'idx_vn_audio_sha');
SET @sql := IF(@idx_sha = 0,
    'CREATE INDEX idx_vn_audio_sha ON voice_notes (audio_sha256_hash)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
