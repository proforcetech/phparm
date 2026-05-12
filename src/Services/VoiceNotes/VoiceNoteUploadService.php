<?php

declare(strict_types=1);

namespace App\Services\VoiceNotes;

use App\Models\User;
use App\Support\Ulid;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * R-01 / AUD-063 — server-managed voice-note upload pipeline.
 *
 * This service is the ONLY supported way to land an audio file into the
 * voice-notes storage tree. The old "client sends `audio_path` JSON
 * field" flow is gone — VoiceNoteService::record() now rejects any
 * payload that includes a string `audio_path` so nothing can sneak past.
 *
 * Pipeline:
 *   1. Validate the uploaded $_FILES-style array (presence, error code,
 *      readable tmp_name, non-empty, under size cap).
 *   2. Sniff MIME from the first 2 KiB via finfo. The client's
 *      `$file['type']` is entirely ignored — browsers lie and curl
 *      users lie harder. A `.exe` renamed `.mp3` will fail because
 *      finfo reads the magic bytes and returns something like
 *      application/x-dosexec, which is not in the allowlist.
 *   3. Compute sha256 of the bytes (used for audit + dedupe).
 *   4. Generate a stable server-side path:
 *         voice_notes/{yyyy}/{mm}/{user_id}/{ulid}.{ext}
 *      where {ext} is derived from the sniffed MIME (NOT from the
 *      uploaded filename). The ULID makes the filename
 *      cryptographically unguessable and naturally time-sortable.
 *   5. Atomically move the tmp file into place via
 *      move_uploaded_file() (or rename + copy in the testing variant).
 *
 * Returned struct carries everything VoiceNoteService::record() needs
 * to populate the row: relative path, mime, size, extension, sha256.
 *
 * Why this is a separate service rather than a method on
 * VoiceNoteService: the upload-side concerns (filesystem moves, finfo,
 * directory creation) are orthogonal to the metadata-row lifecycle
 * (status transitions, tagging, transcription). Keeping them apart lets
 * the upload service be tested without touching the database and lets a
 * future "re-upload audio for an existing note" flow reuse the same
 * pipeline.
 */
class VoiceNoteUploadService
{
    public const DEFAULT_MAX_BYTES = 25 * 1024 * 1024;

    /** Read at MOST this many bytes from the tmp file for MIME sniffing. */
    private const MIME_SNIFF_BYTES = 2048;

    /** @var array<string, string> sniffed-MIME => filename extension */
    private array $allowedMimeMap;

    public function __construct(
        private readonly string $storageRoot,
        ?array $allowedMimeMap = null,
        private readonly int $maxUploadBytes = self::DEFAULT_MAX_BYTES,
        private readonly bool $requireUploadedFile = true,
    ) {
        if ($this->storageRoot === '') {
            throw new RuntimeException(
                'VoiceNoteUploadService: storageRoot must be non-empty'
            );
        }
        if ($this->maxUploadBytes <= 0) {
            throw new RuntimeException(
                'VoiceNoteUploadService: maxUploadBytes must be positive'
            );
        }
        $this->allowedMimeMap = $allowedMimeMap ?? self::defaultMimeMap();
    }

    /**
     * Mime → extension map covering what mobile recorders typically
     * emit. The validator uses the sniffed MIME (not the uploaded
     * filename) as the key, so anything outside this list is rejected.
     *
     * @return array<string, string>
     */
    public static function defaultMimeMap(): array
    {
        return [
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            'audio/wave' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'webm',
            'audio/mp4' => 'm4a',
            'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac',
            'audio/flac' => 'flac',
            'audio/x-flac' => 'flac',
        ];
    }

    /**
     * Process an uploaded audio file end-to-end. The actor is needed for
     * the per-user storage subdirectory; the caller is responsible for
     * the actor's voice_notes.create permission gate (the upload service
     * does NOT re-check it — VoiceNoteService::record() does).
     *
     * @param array<string, mixed> $file $_FILES-style entry
     * @return array{
     *     audio_path: string,
     *     audio_format: string,
     *     audio_mime: string,
     *     audio_size_bytes: int,
     *     audio_sha256_hash: string,
     *     original_name: string
     * }
     */
    public function ingest(User $actor, array $file, ?DateTimeImmutable $now = null): array
    {
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '') {
            throw new InvalidArgumentException('audio: missing uploaded file');
        }
        if ($this->requireUploadedFile && !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('audio: not a valid uploaded file');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(
                'audio: upload failed with error code ' . $error
            );
        }
        if (!is_file($tmpName) || !is_readable($tmpName)) {
            throw new InvalidArgumentException('audio: uploaded file is not readable');
        }

        $size = (int) ($file['size'] ?? filesize($tmpName));
        if ($size <= 0) {
            throw new InvalidArgumentException('audio: uploaded file is empty');
        }
        if ($size > $this->maxUploadBytes) {
            throw new InvalidArgumentException(
                'audio: file exceeds the ' . $this->maxUploadBytes . '-byte limit'
            );
        }

        $mime = $this->sniffMime($tmpName);
        if (!isset($this->allowedMimeMap[$mime])) {
            throw new InvalidArgumentException(
                "audio: detected MIME type `{$mime}` is not in the allowlist"
            );
        }
        $extension = $this->allowedMimeMap[$mime];

        $sha256 = @hash_file('sha256', $tmpName);
        if (!is_string($sha256) || strlen($sha256) !== 64) {
            throw new RuntimeException('audio: could not hash uploaded bytes');
        }

        $authorId = (int) ($actor->id ?? 0);
        if ($authorId <= 0) {
            // The route gate requires auth, so this should never fire in
            // production — but if it does, fail loud rather than write a
            // file into a `0/` directory and pretend the upload was
            // attributed.
            throw new RuntimeException('audio: cannot ingest without an authenticated actor');
        }

        $relativePath = $this->buildRelativePath($authorId, $extension, $now);
        $absolutePath = $this->ensureDirAndAbsolutePath($relativePath);

        $this->persist($tmpName, $absolutePath);

        $originalName = $this->sanitizeOriginalName(
            (string) ($file['name'] ?? 'recording')
        );

        return [
            'audio_path' => $relativePath,
            'audio_format' => $extension,
            'audio_mime' => $mime,
            'audio_size_bytes' => $size,
            'audio_sha256_hash' => $sha256,
            'original_name' => $originalName,
        ];
    }

    /**
     * Resolve a stored audio_path back to an absolute filesystem path,
     * enforcing the same containment check the transcriber uses
     * (AUD-063 belt-and-braces).
     *
     * Returns null if the resolved file does not exist — the streaming
     * endpoint maps that to a 404. RuntimeException is reserved for
     * paths that try to escape the root (those are abuse, not "missing").
     */
    public function resolveStoredFile(string $relativePath): ?string
    {
        if ($relativePath === '') {
            return null;
        }
        if (str_contains($relativePath, '..') || str_contains($relativePath, "\0")) {
            throw new RuntimeException('audio: refusing to resolve unsafe path');
        }
        if ($relativePath[0] === '/' || $relativePath[0] === '\\'
            || preg_match('#^[A-Za-z]:[\\\\/]#', $relativePath)
        ) {
            throw new RuntimeException('audio: audio_path must be relative');
        }

        $root = rtrim($this->storageRoot, "/\\");
        $rel = ltrim($relativePath, "/\\");
        $candidate = $root . DIRECTORY_SEPARATOR . $rel;

        $rootReal = realpath($root);
        if ($rootReal === false) {
            throw new RuntimeException('audio: storage root does not exist');
        }
        $candidateReal = realpath($candidate);
        if ($candidateReal === false) {
            return null;
        }
        $rootNormalised = rtrim($rootReal, "/\\") . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidateReal . DIRECTORY_SEPARATOR, $rootNormalised)) {
            throw new RuntimeException('audio: resolved path escapes storage root');
        }
        return $candidateReal;
    }

    // ─────────────────────────────────────────────── internals ────

    /**
     * Sniff MIME from the first 2 KiB. Reading only the head is faster
     * than letting finfo open the whole file, and the magic bytes for
     * every audio container we accept live in the first ~12 bytes.
     */
    private function sniffMime(string $tmpName): string
    {
        $handle = @fopen($tmpName, 'rb');
        if ($handle === false) {
            throw new RuntimeException('audio: could not read uploaded file for MIME sniffing');
        }
        $head = fread($handle, self::MIME_SNIFF_BYTES);
        fclose($handle);
        if ($head === false || $head === '') {
            throw new RuntimeException('audio: empty MIME-sniff buffer');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException(
                'audio: finfo extension is required for MIME sniffing'
            );
        }
        $mime = finfo_buffer($finfo, $head);
        finfo_close($finfo);

        if (!is_string($mime) || $mime === '') {
            throw new RuntimeException('audio: could not detect MIME type');
        }
        return strtolower($mime);
    }

    private function buildRelativePath(
        int $authorId,
        string $extension,
        ?DateTimeImmutable $now
    ): string {
        $now = $now ?? new DateTimeImmutable();
        $year = $now->format('Y');
        $month = $now->format('m');
        $ulid = Ulid::generate();
        return "{$year}/{$month}/{$authorId}/{$ulid}.{$extension}";
    }

    /**
     * mkdir -p the year/month/user_id directory and return the absolute
     * path the upload should land at. 0750 follows the existing
     * voice-notes storage root permissions (owner full, group read+exec
     * so the transcription cron worker can reach sidecars).
     */
    private function ensureDirAndAbsolutePath(string $relativePath): string
    {
        $root = rtrim($this->storageRoot, "/\\");
        if (!is_dir($root)) {
            if (!@mkdir($root, 0750, true) && !is_dir($root)) {
                throw new RuntimeException(
                    "audio: could not create storage root: {$root}"
                );
            }
        }
        $absolute = $root . DIRECTORY_SEPARATOR . $relativePath;
        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException(
                    "audio: could not create storage directory: {$dir}"
                );
            }
        }
        return $absolute;
    }

    /**
     * move_uploaded_file is the canonical mover — it's the only move
     * variant that PHP guarantees came from an HTTP upload (defends
     * against a bug where a caller passes a server-local path masquerad-
     * ing as $_FILES). Tests subclass this method to bypass the check
     * since they fabricate $_FILES-style arrays from tempnam().
     */
    protected function persist(string $tmpName, string $absoluteDestination): void
    {
        if ($this->requireUploadedFile) {
            if (!@move_uploaded_file($tmpName, $absoluteDestination)) {
                throw new RuntimeException(
                    "audio: move_uploaded_file failed: {$tmpName} -> {$absoluteDestination}"
                );
            }
            return;
        }
        // Test path — rename if the same fs, fall back to copy+unlink.
        if (!@rename($tmpName, $absoluteDestination)) {
            if (!@copy($tmpName, $absoluteDestination)) {
                throw new RuntimeException(
                    "audio: could not persist upload to {$absoluteDestination}"
                );
            }
            @unlink($tmpName);
        }
    }

    /**
     * Sanitize the client-supplied original_name for informational use.
     * It's NOT used to construct any filesystem path — the server
     * generates the storage path from the ULID + sniffed extension. We
     * preserve a cleaned copy purely so the operator can see "what the
     * client called it" in audit logs.
     */
    private function sanitizeOriginalName(string $name): string
    {
        $name = trim($name);
        $name = str_replace(["\\", '/'], '_', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'recording';
        }
        if (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }
        return $name;
    }
}
