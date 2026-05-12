<?php

declare(strict_types=1);

/**
 * Voice-note upload pipeline configuration.
 *
 * Sized for field-tech recordings, not music files:
 *   * 25 MB hard cap (covers a continuous 30-minute m4a recording at
 *     128 kbps with comfortable headroom).
 *   * MIME allowlist constrained to what mobile recorders typically
 *     emit. The validator MIME-sniffs the first 2 KiB via finfo, so a
 *     `.exe` renamed to `.mp3` will fail when finfo reports
 *     application/x-dosexec.
 *
 * The mime → extension map drives the on-disk filename suffix. We do
 * NOT trust the client's claimed extension or MIME — both are derived
 * from finfo's verdict.
 *
 * Override paths (any can be set in .env):
 *   VOICE_NOTES_MAX_UPLOAD_BYTES   — bump the size cap
 *   VOICE_NOTES_STORAGE_ROOT       — relocate the on-disk storage root
 */

$root = $_ENV['VOICE_NOTES_STORAGE_ROOT']
    ?? (dirname(__DIR__) . '/storage/private/voice_notes');

return [
    'max_upload_bytes' => (int) ($_ENV['VOICE_NOTES_MAX_UPLOAD_BYTES'] ?? 25 * 1024 * 1024),

    'storage_root' => $root,

    /*
     * mime => filename extension.
     *
     * Multiple MIMEs may map to the same extension (e.g., audio/mpeg
     * and audio/mp3 both → mp3) because different recording stacks
     * emit different MIME spellings for the same encoded bytes.
     *
     * audio/mp4 + audio/x-m4a both map to .m4a (the de-facto Apple
     * codec container). audio/aac (raw AAC stream) gets its own
     * .aac extension because some browsers refuse to play it inside
     * an .m4a container.
     */
    'allowed_mime_types' => [
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
    ],
];
