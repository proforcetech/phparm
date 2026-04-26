<?php

namespace App\Services\VoiceNotes;

/**
 * Phase 10.7 — DTO returned by a TranscriberInterface implementation.
 *
 * A TranscriptionResult is the analytical output of running a transcriber
 * against an audio file. The VoiceNoteService wraps the result into the
 * persisted VoiceNote row by writing transcript + language + confidence +
 * duration_seconds and stamping transcription_completed_at.
 *
 * All fields except `transcript` are nullable because not every transcriber
 * surfaces every signal — the heuristic produces a transcript with no
 * confidence score, while a Whisper-backed transcriber returns confidence
 * and detected language but may omit duration when streaming.
 *
 * Score conventions:
 *   confidence  0.0 (no confidence) → 1.0 (very confident)
 *   language    BCP-47 tag (e.g., "en-US", "es-MX") or null when undetected
 */
final class TranscriptionResult
{
    public function __construct(
        public readonly string $transcript,
        public readonly ?string $language = null,
        public readonly ?float $confidence = null,
        public readonly ?float $durationSeconds = null,
    ) {
    }
}
