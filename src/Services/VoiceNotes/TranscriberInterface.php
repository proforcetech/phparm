<?php

namespace App\Services\VoiceNotes;

/**
 * Phase 10.7 — Pluggable transcriber that turns an audio file into text.
 *
 * Two implementations are envisioned:
 *
 *   HeuristicTranscriber (shipped) — reads a sidecar `.txt` file next to
 *     the audio (e.g., `note.mp3` + `note.mp3.txt`) and returns its
 *     contents as the transcript. This is a degraded-mode default for
 *     environments without an AI provider configured: it lets the rest of
 *     the workflow (upload → status moves → display in the WO timeline)
 *     run end-to-end while delegating the actual speech-recognition step
 *     to whatever the operator put in the sidecar (a manual transcript a
 *     dispatcher typed, a third-party tool's output, etc.).
 *
 *   AI-backed transcriber (not in this codebase) — wraps a call to OpenAI
 *     Whisper / Deepgram / AssemblyAI and parses the structured response
 *     into a TranscriptionResult. Replaceable at the DI container level
 *     by editing routes/modules/voice_notes.php.
 *
 * Implementations should throw a TranscriptionException (or any Throwable
 * — the service catches Throwable) when the audio is unreadable or the
 * provider rejects the request. The service catches the exception, marks
 * the row 'failed', and persists the throwable's message into
 * transcription_failure_reason for the operator to investigate.
 *
 * The label() method returns a stable identifier that the service writes
 * into transcription_provider on the row (e.g., "heuristic_v1",
 * "whisper_v1", "deepgram_v3"). This gives downstream model-quality
 * reports a join key for slicing accuracy by provider/version.
 */
interface TranscriberInterface
{
    /**
     * Transcribe the audio file at $audioPath. The path is the same value
     * persisted on the VoiceNote row — implementations MUST resolve it
     * against whatever filesystem root they were configured with rather
     * than treating it as an absolute path on the host (the service layer
     * stores paths relative to a storage root for portability).
     *
     * $languageHint is an optional BCP-47 tag the recorder may have
     * supplied (e.g., the mobile UI knows the tech's locale). Implementations
     * may use it to bias the model toward a language; ignore it freely if
     * the model is language-agnostic.
     */
    public function transcribe(string $audioPath, ?string $languageHint = null): TranscriptionResult;

    public function label(): string;
}
