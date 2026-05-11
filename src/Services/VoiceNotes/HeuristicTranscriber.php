<?php

namespace App\Services\VoiceNotes;

use RuntimeException;

/**
 * Phase 10.7 — Default sidecar-file transcriber.
 *
 * Strategy: when asked to transcribe `path/to/note.mp3`, look for a
 * companion file `path/to/note.mp3.txt`. If present, return its trimmed
 * contents as the transcript. If absent, fall back to a placeholder
 * transcript indicating no transcription provider is wired up.
 *
 * Why not actually decode audio in PHP? Because PHP-native speech
 * recognition is not realistic — every viable provider is either an HTTP
 * API (OpenAI Whisper, Deepgram, AssemblyAI) or a native binary
 * (whisper.cpp, vosk-cli) that the platform should shell out to in a
 * dedicated worker process, not inline in the request lifecycle. The
 * sidecar pattern keeps the wiring testable without forcing an external
 * dependency: tests can write a `.txt` next to the audio and exercise
 * the full pipeline; production swaps in a real provider via the DI
 * binding in routes/modules/voice_notes.php.
 *
 * The sidecar pattern also doubles as a manual-transcript backdoor: a
 * dispatcher who can't trust the AI on a particular note can drop a
 * hand-typed `.txt` and re-trigger transcription to get an authoritative
 * version into the row.
 *
 * The label() returns a versioned identifier so downstream model-quality
 * reports can distinguish heuristic-derived transcripts from AI-derived
 * ones at the row level.
 */
final class HeuristicTranscriber implements TranscriberInterface
{
    /**
     * Filesystem root the audio paths are resolved against. The service
     * stores paths relative to this root for portability; the transcriber
     * needs the absolute prefix to read the sidecar.
     *
     * AUD-063 — production wiring MUST supply a non-empty storage root.
     * The empty default existed historically to make the service usable
     * without filesystem setup, but that mode let any audio_path resolve
     * relative to the PHP CWD, which combined with the relative-path
     * gate at the service layer is still unsafe (e.g., `tests/fixtures/`
     * could be read at runtime). We retain `$allowEmptyRootForTests` as
     * an explicit opt-in so unit tests can construct an unrooted
     * transcriber, but real callers must provide a root.
     */
    public function __construct(
        private readonly string $storageRoot = '',
        private readonly bool $allowEmptyRootForTests = false,
    ) {
        if ($this->storageRoot === '' && !$this->allowEmptyRootForTests) {
            throw new RuntimeException(
                'HeuristicTranscriber: storageRoot must be non-empty in production. '
                . 'Construct with HeuristicTranscriber($root) or, in tests only, '
                . 'HeuristicTranscriber("", allowEmptyRootForTests: true).'
            );
        }
    }

    public function transcribe(string $audioPath, ?string $languageHint = null): TranscriptionResult
    {
        $absolute = $this->resolve($audioPath);
        $sidecar = $absolute . '.txt';

        if (!is_file($sidecar)) {
            // No sidecar means no transcription provider was wired up
            // for this install. Return a placeholder transcript with zero
            // confidence so the row reaches 'completed' but the UI can
            // surface a "transcript pending — no provider configured"
            // affordance rather than displaying empty text.
            return new TranscriptionResult(
                transcript: '[no transcription provider configured — drop a sidecar .txt next to the audio file or wire an AI transcriber]',
                language: $languageHint,
                confidence: 0.0,
                durationSeconds: null,
            );
        }

        $contents = @file_get_contents($sidecar);
        if ($contents === false) {
            throw new RuntimeException("HeuristicTranscriber: unable to read sidecar {$sidecar}");
        }

        $transcript = trim($contents);
        if ($transcript === '') {
            // An empty sidecar is treated as a failure rather than a
            // legitimate empty transcript — empty audio shouldn't have
            // produced a sidecar in the first place. Surfacing as a
            // failure lets the operator notice and re-record.
            throw new RuntimeException("HeuristicTranscriber: sidecar {$sidecar} is empty");
        }

        return new TranscriptionResult(
            transcript: $transcript,
            language: $languageHint ?? 'en-US',
            confidence: 1.0,
            durationSeconds: self::estimateDuration($transcript),
        );
    }

    public function label(): string
    {
        return 'heuristic_v1';
    }

    /**
     * Best-effort duration estimate for sidecar transcripts. Speaking rate
     * for typical English is ~150 wpm, so we estimate `words / 2.5`
     * seconds. The estimate is purely advisory — UI uses it for the
     * timeline width hint, not for billing or compliance.
     */
    private static function estimateDuration(string $transcript): float
    {
        $words = max(1, str_word_count($transcript));
        return round($words / 2.5, 2);
    }

    /**
     * Resolve a relative `audio_path` against the configured storage root,
     * then verify the resolved absolute path stays under the root.
     *
     * Defenses (AUD-063):
     *   1. Reject `..` segments and null bytes outright.
     *   2. Reject absolute paths — the service layer also rejects these,
     *      this is belt-and-braces against a future caller bypassing it.
     *   3. After concatenation, walk realpath and require the result to
     *      live under the canonicalised root (defeats symlink escapes
     *      that survive (1) and (2) — e.g., a symlink inside the root
     *      pointing at /etc).
     */
    private function resolve(string $audioPath): string
    {
        if (str_contains($audioPath, '..') || str_contains($audioPath, "\0")) {
            throw new RuntimeException(
                "HeuristicTranscriber: refusing to resolve unsafe path: {$audioPath}"
            );
        }
        if ($audioPath !== '' && ($audioPath[0] === '/' || $audioPath[0] === '\\'
            || preg_match('#^[A-Za-z]:[\\\\/]#', $audioPath))
        ) {
            throw new RuntimeException(
                "HeuristicTranscriber: audio_path must be relative: {$audioPath}"
            );
        }
        if ($this->storageRoot === '') {
            // Test-mode opt-in. Already validated in the constructor; the
            // checks above still applied so even the test path is safe.
            return $audioPath;
        }

        $root = rtrim($this->storageRoot, "/\\");
        $rel = ltrim($audioPath, "/\\");
        $candidate = $root . DIRECTORY_SEPARATOR . $rel;

        // For containment we canonicalise the root once (must exist) and
        // require the candidate's parent directory to canonicalise under
        // it. We use the parent dir because the audio file itself may be
        // missing in the no-sidecar case; the directory should still be
        // inside the root.
        $rootReal = realpath($root);
        if ($rootReal === false) {
            throw new RuntimeException(
                "HeuristicTranscriber: storage root does not exist: {$root}"
            );
        }
        $parentReal = realpath(dirname($candidate));
        if ($parentReal === false) {
            // Parent dir doesn't exist — the file definitely won't either,
            // so no read can happen, but we still want a clean error.
            throw new RuntimeException(
                "HeuristicTranscriber: audio directory does not exist under storage root: {$audioPath}"
            );
        }
        $rootRealNormalised = rtrim($rootReal, "/\\") . DIRECTORY_SEPARATOR;
        if (!str_starts_with($parentReal . DIRECTORY_SEPARATOR, $rootRealNormalised)) {
            throw new RuntimeException(
                "HeuristicTranscriber: resolved path escapes storage root: {$audioPath}"
            );
        }

        return $candidate;
    }
}
