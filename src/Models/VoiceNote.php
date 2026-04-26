<?php

namespace App\Models;

/**
 * Phase 10.7 — A recorded voice note attached to a workorder, ticket, or
 * vehicle, with an optional transcript produced by a pluggable transcriber.
 *
 * Lifecycle (transcription_status):
 *   pending      → audio uploaded, no transcript yet; the cron worker
 *                  scans for this status and runs the active transcriber
 *   transcribing → worker has claimed the row and is mid-run
 *   completed    → transcript persisted with confidence and (optionally)
 *                  detected language; the row is fully realised
 *   failed       → the transcriber raised; transcription_failure_reason
 *                  captures the human-readable cause. Re-trying moves the
 *                  row back to pending so the worker picks it up again.
 *
 * Re-transcribing a completed note (e.g., upgrading from heuristic to
 * Whisper) is handled outside the lifecycle table — the service overwrites
 * the transcript fields in place and bumps transcription_provider, but the
 * status remains 'completed' before and after. This avoids polluting the
 * state machine with a "re-running an already-completed note" state.
 */
class VoiceNote extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_TRANSCRIBING = 'transcribing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_TRANSCRIBING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * Forward path: pending → transcribing → completed/failed.
     * Recovery path: failed → pending (manual retry).
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_TRANSCRIBING => [self::STATUS_PENDING, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [self::STATUS_TRANSCRIBING],
        self::STATUS_FAILED => [self::STATUS_TRANSCRIBING],
        self::STATUS_PENDING => [self::STATUS_FAILED],
    ];

    public const VISIBILITY_INTERNAL = 'internal';
    public const VISIBILITY_CUSTOMER_VISIBLE = 'customer_visible';

    public const VISIBILITIES = [
        self::VISIBILITY_INTERNAL,
        self::VISIBILITY_CUSTOMER_VISIBLE,
    ];

    /**
     * Audio formats the upload pipeline accepts. Constrained at the service
     * layer so an upload of `.exe` masquerading as `.mp3` doesn't make it
     * into the row. This list reflects what the typical mobile client
     * (React Native + AVAudioRecorder / android.media.MediaRecorder) emits
     * by default.
     */
    public const SUPPORTED_FORMATS = [
        'mp3', 'wav', 'm4a', 'mp4', 'ogg', 'webm', 'aac', 'flac',
    ];

    public int $id = 0;
    public ?int $workorder_id = null;
    public ?int $ticket_id = null;
    public ?int $vehicle_id = null;
    public ?int $author_user_id = null;
    public string $audio_path = '';
    public string $audio_format = 'mp3';
    public ?int $audio_size_bytes = null;
    public ?float $duration_seconds = null;
    public ?string $transcript = null;
    public ?string $transcript_language = null;
    public string $transcription_provider = 'heuristic_v1';
    public string $transcription_status = self::STATUS_PENDING;
    public ?string $transcription_started_at = null;
    public ?string $transcription_completed_at = null;
    public ?string $transcription_failure_reason = null;
    public ?float $confidence = null;
    public string $visibility = self::VISIBILITY_INTERNAL;
    public bool $pinned = false;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
