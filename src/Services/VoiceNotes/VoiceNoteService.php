<?php

namespace App\Services\VoiceNotes;

use App\Models\User;
use App\Models\VoiceNote;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Phase 10.7 — orchestrates the voice-note recording + transcription
 * workflow. The audio file itself lives on the filesystem outside this
 * service's purview (the upload pipeline persists it and hands us the
 * relative path); this service owns the metadata row, lifecycle moves,
 * and transcription orchestration.
 *
 * Permission gates:
 *   voice_notes.view        read voice-note metadata + transcripts
 *   voice_notes.create      record a new voice note (uploads audio +
 *                           inserts metadata row in 'pending' state)
 *   voice_notes.transcribe  manually trigger transcription on a pending
 *                           or failed note (the cron worker holds this
 *                           gate against a system-user actor; humans
 *                           hold it for ad-hoc retries)
 *   voice_notes.manage      edit metadata (visibility, pinned, notes,
 *                           tags), delete notes
 *
 * Lifecycle (VoiceNote::ALLOWED_TRANSITIONS):
 *   pending      → transcribing  (worker claims the row, stamps
 *                                 transcription_started_at)
 *   transcribing → completed     (transcript persisted with confidence,
 *                                 transcription_completed_at stamped)
 *   transcribing → failed        (transcriber threw; failure_reason
 *                                 captured)
 *   failed       → transcribing  (manual retry via .transcribe)
 *   pending      → failed        (terminal short-circuit if validation
 *                                 fails before the worker even claims
 *                                 the row, e.g., audio file missing)
 *
 * Re-transcription policy:
 *   transcribe() called on a 'completed' note re-runs the active
 *   transcriber and overwrites the transcript fields in place. The
 *   status doesn't move (it stays 'completed') because the row is
 *   conceptually still "fully realised" before and after — this is
 *   different from the lifecycle moves above and is handled as an
 *   explicit re-run rather than a state-machine transition.
 *
 * Validation rules enforced by record():
 *   - audio_path required, no `..` segments (defends against directory
 *     traversal in the storage layer)
 *   - audio_format must be in VoiceNote::SUPPORTED_FORMATS
 *   - visibility must be in VoiceNote::VISIBILITIES
 *   - author_user_id auto-populated from the actor (not from the payload)
 *     so a tech can't impersonate another tech via API
 *   - at least one of workorder_id / ticket_id / vehicle_id is recommended
 *     but not enforced — standalone "memo" notes are a legitimate use case
 */
class VoiceNoteService
{
    public function __construct(
        private readonly VoiceNoteRepository $repo,
        private readonly VoiceNoteTagRepository $tagRepo,
        private readonly TranscriberInterface $transcriber,
        private readonly AccessGate $gate,
    ) {
    }

    // ─────────────────────────────────────────────── reads ────

    /**
     * @return array<int, VoiceNote>
     */
    public function listForWorkorder(User $actor, int $workorderId): array
    {
        $this->gate->assert($actor, 'voice_notes.view');
        return $this->repo->listForWorkorder($workorderId);
    }

    /**
     * @return array<int, VoiceNote>
     */
    public function listForTicket(User $actor, int $ticketId): array
    {
        $this->gate->assert($actor, 'voice_notes.view');
        return $this->repo->listForTicket($ticketId);
    }

    /**
     * "My voice notes" — the per-user notebook view. The actor must have
     * .view; we don't gate on the actor BEING the author, because dispatch
     * may legitimately need to read another tech's notes.
     *
     * @return array<int, VoiceNote>
     */
    public function listForAuthor(User $actor, int $authorUserId, int $limit = 100, int $offset = 0): array
    {
        $this->gate->assert($actor, 'voice_notes.view');
        return $this->repo->listForAuthor($authorUserId, $limit, $offset);
    }

    /**
     * Cron worker scan path. Returns up to $limit pending notes oldest-first.
     *
     * @return array<int, VoiceNote>
     */
    public function listPendingTranscriptions(User $actor, int $limit = 25): array
    {
        $this->gate->assert($actor, 'voice_notes.view');
        return $this->repo->listPendingTranscriptions($limit);
    }

    public function getNote(User $actor, int $id): VoiceNote
    {
        $this->gate->assert($actor, 'voice_notes.view');
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("Voice note {$id} not found");
        }
        return $row;
    }

    /**
     * Single-shot fetch with tags array merged in. Avoids the N+1 round
     * trip the timeline render would otherwise incur.
     *
     * @return array{note: VoiceNote, tags: array<int, string>}
     */
    public function getNoteWithTags(User $actor, int $id): array
    {
        $note = $this->getNote($actor, $id);
        $tags = array_map(static fn($t) => $t->tag, $this->tagRepo->listForNote($id));
        return ['note' => $note, 'tags' => $tags];
    }

    // ─────────────────────────────────────────────── record ────

    /**
     * Persist the metadata row for an audio recording the upload service
     * has already landed on disk. The caller MUST pass the result of
     * VoiceNoteUploadService::ingest() as $upload — record() trusts the
     * upload service for path/MIME/size/sha256 and refuses to let the
     * client provide them directly.
     *
     * AUD-063 / R-01 — this method used to take the audio_path string
     * from the client's JSON body. That flow is gone. We assert below
     * that the client payload does NOT include any storage-managed
     * field; any such payload is treated as an attack and rejected with
     * a 400.
     *
     * @param array<string, mixed> $upload  Result of VoiceNoteUploadService::ingest()
     * @param array<string, mixed> $payload Remaining client-supplied metadata
     *                                      (workorder_id, ticket_id, etc.)
     */
    public function record(User $actor, array $upload, array $payload = []): VoiceNote
    {
        $this->gate->assert($actor, 'voice_notes.create');

        $this->assertNoClientStoragePayload($payload);
        $this->assertUploadShape($upload);

        $visibility = (string) ($payload['visibility'] ?? VoiceNote::VISIBILITY_INTERNAL);
        if (!in_array($visibility, VoiceNote::VISIBILITIES, true)) {
            throw new InvalidArgumentException(
                "Unknown visibility `{$visibility}` "
                . '(allowed: ' . implode(', ', VoiceNote::VISIBILITIES) . ')'
            );
        }

        return $this->repo->create([
            'workorder_id' => $payload['workorder_id'] ?? null,
            'ticket_id' => $payload['ticket_id'] ?? null,
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            // author is the actor — never trust the payload for this.
            'author_user_id' => $actor->id ?? null,
            // Storage-managed fields come from the upload service struct,
            // never from the client payload.
            'audio_path' => (string) $upload['audio_path'],
            'audio_format' => (string) $upload['audio_format'],
            'audio_mime' => (string) $upload['audio_mime'],
            'audio_size_bytes' => (int) $upload['audio_size_bytes'],
            'audio_sha256_hash' => (string) $upload['audio_sha256_hash'],
            'duration_seconds' => $payload['duration_seconds'] ?? null,
            'transcript_language' => $payload['transcript_language'] ?? null,
            'transcription_provider' => $this->transcriber->label(),
            'transcription_status' => VoiceNote::STATUS_PENDING,
            'visibility' => $visibility,
            'pinned' => !empty($payload['pinned']),
            'notes' => $payload['notes'] ?? null,
        ]);
    }

    // ─────────────────────────────────────────────── transcribe ────

    /**
     * Run the active transcriber against a note's audio file and persist
     * the result. Handles three entry-point cases:
     *
     *   pending → transcribing → completed   (normal flow)
     *   pending → transcribing → failed      (transcriber threw)
     *   failed  → transcribing → completed   (manual retry succeeded)
     *   failed  → transcribing → failed      (retry also failed)
     *
     * Re-transcribing a 'completed' note overwrites the transcript fields
     * in place without moving status — the row is conceptually still
     * realised before and after; this is an "upgrade the transcript with
     * a better model" path, not a lifecycle move.
     */
    public function transcribe(
        User $actor,
        int $id,
        ?DateTimeImmutable $now = null
    ): VoiceNote {
        $this->gate->assert($actor, 'voice_notes.transcribe');

        $note = $this->repo->findById($id);
        if ($note === null) {
            throw new InvalidArgumentException("Voice note {$id} not found");
        }

        $isReRun = $note->transcription_status === VoiceNote::STATUS_COMPLETED;
        if (!$isReRun) {
            $this->assertTransitionAllowed(
                $note->transcription_status,
                VoiceNote::STATUS_TRANSCRIBING
            );
        }

        $stamp = ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        // Mark the row as in-flight before invoking the transcriber so a
        // concurrent worker pass doesn't pick the same row up.
        if (!$isReRun) {
            $this->repo->update($id, [
                'transcription_status' => VoiceNote::STATUS_TRANSCRIBING,
                'transcription_started_at' => $stamp,
                'transcription_failure_reason' => null,
            ]);
        }

        try {
            $result = $this->transcriber->transcribe(
                $note->audio_path,
                $note->transcript_language
            );
        } catch (Throwable $e) {
            // Move to failed and capture the cause; the operator can see
            // why and either fix the audio or swap the transcriber binding.
            return $this->repo->update($id, [
                'transcription_status' => VoiceNote::STATUS_FAILED,
                'transcription_failure_reason' => $e->getMessage(),
                'transcription_completed_at' => $stamp,
            ]);
        }

        $update = [
            'transcript' => $result->transcript,
            'transcript_language' => $result->language ?? $note->transcript_language,
            'confidence' => $result->confidence,
            'transcription_provider' => $this->transcriber->label(),
            'transcription_status' => VoiceNote::STATUS_COMPLETED,
            'transcription_completed_at' => $stamp,
            'transcription_failure_reason' => null,
        ];
        if ($result->durationSeconds !== null && $note->duration_seconds === null) {
            // Only fill in duration_seconds when the recorder didn't
            // already supply it — the recorder's value is authoritative
            // (it knows the actual file duration; the transcriber's
            // estimate is just word-count-based).
            $update['duration_seconds'] = $result->durationSeconds;
        }
        return $this->repo->update($id, $update);
    }

    // ─────────────────────────────────────────────── update / delete ────

    /**
     * Edit metadata (visibility, pinned, notes, transcript) on an existing
     * note. The transcript is editable so a reviewer can clean up an
     * imperfect AI transcription before sharing with the customer.
     *
     * @param array<string, mixed> $payload
     */
    public function updateNote(User $actor, int $id, array $payload): VoiceNote
    {
        $this->gate->assert($actor, 'voice_notes.manage');

        $note = $this->repo->findById($id);
        if ($note === null) {
            throw new InvalidArgumentException("Voice note {$id} not found");
        }

        $update = [];
        if (array_key_exists('visibility', $payload)) {
            $vis = (string) $payload['visibility'];
            if (!in_array($vis, VoiceNote::VISIBILITIES, true)) {
                throw new InvalidArgumentException(
                    "Unknown visibility `{$vis}` "
                    . '(allowed: ' . implode(', ', VoiceNote::VISIBILITIES) . ')'
                );
            }
            $update['visibility'] = $vis;
        }
        if (array_key_exists('pinned', $payload)) {
            $update['pinned'] = !empty($payload['pinned']);
        }
        if (array_key_exists('notes', $payload)) {
            $update['notes'] = $payload['notes'];
        }
        if (array_key_exists('transcript', $payload)) {
            $update['transcript'] = $payload['transcript'];
        }
        if (array_key_exists('transcript_language', $payload)) {
            $update['transcript_language'] = $payload['transcript_language'];
        }

        if ($update === []) {
            return $note;
        }
        return $this->repo->update($id, $update);
    }

    public function pin(User $actor, int $id): VoiceNote
    {
        return $this->updateNote($actor, $id, ['pinned' => true]);
    }

    public function unpin(User $actor, int $id): VoiceNote
    {
        return $this->updateNote($actor, $id, ['pinned' => false]);
    }

    public function deleteNote(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'voice_notes.manage');
        $note = $this->repo->findById($id);
        if ($note === null) {
            throw new InvalidArgumentException("Voice note {$id} not found");
        }
        $this->repo->delete($id);
    }

    // ─────────────────────────────────────────────── tags ────

    /**
     * Replace the tag set on a note. Returns the {added, removed} diff so
     * the API caller can show "added: brake; removed: tire" toasts.
     *
     * @param array<int, string> $tags
     * @return array{note: VoiceNote, tags: array<int, string>, added: array<int, string>, removed: array<int, string>}
     */
    public function setTags(User $actor, int $id, array $tags): array
    {
        $this->gate->assert($actor, 'voice_notes.manage');
        $note = $this->repo->findById($id);
        if ($note === null) {
            throw new InvalidArgumentException("Voice note {$id} not found");
        }
        $diff = $this->tagRepo->replaceTags($id, $tags);
        $current = array_map(static fn($t) => $t->tag, $this->tagRepo->listForNote($id));
        return [
            'note' => $note,
            'tags' => $current,
            'added' => $diff['added'],
            'removed' => $diff['removed'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function listAllTags(User $actor, int $limit = 200): array
    {
        $this->gate->assert($actor, 'voice_notes.view');
        return $this->tagRepo->listAllTags($limit);
    }

    // ─────────────────────────────────────────────── helpers ────

    private function assertTransitionAllowed(string $current, string $target): void
    {
        $allowedFrom = VoiceNote::ALLOWED_TRANSITIONS[$target] ?? [];
        if (!in_array($current, $allowedFrom, true)) {
            throw new InvalidArgumentException(
                "Illegal transcription_status transition: {$current} → {$target} "
                . '(allowed from: ' . implode(', ', $allowedFrom) . ')'
            );
        }
    }

    /**
     * AUD-063 / R-01 — these field names are owned by the upload
     * service. If a client payload includes any of them we treat it as
     * an attempted bypass and reject the whole request, rather than
     * silently ignoring the suspect fields. Loud failure is the right
     * call here: any caller still passing audio_path in JSON is using
     * the deleted pre-R-01 contract and needs to be updated.
     *
     * @param array<string, mixed> $payload
     */
    private function assertNoClientStoragePayload(array $payload): void
    {
        $reserved = [
            'audio_path',
            'audio_mime',
            'audio_size_bytes',
            'audio_sha256_hash',
            'audio_format',
        ];
        foreach ($reserved as $field) {
            if (array_key_exists($field, $payload)) {
                throw new InvalidArgumentException(
                    "voice_notes: `{$field}` is server-managed and must not be "
                    . 'included in the client payload. Upload the audio via '
                    . 'multipart/form-data with field `audio` and let the '
                    . 'server populate storage metadata.'
                );
            }
        }
    }

    /**
     * Sanity-check the upload struct the caller hands us. The route
     * handler is the only producer (via VoiceNoteUploadService) so the
     * shape should always be right; this is a guard against a future
     * refactor breaking the contract.
     *
     * @param array<string, mixed> $upload
     */
    private function assertUploadShape(array $upload): void
    {
        $required = [
            'audio_path' => 'string',
            'audio_format' => 'string',
            'audio_mime' => 'string',
            'audio_size_bytes' => 'int',
            'audio_sha256_hash' => 'string',
        ];
        foreach ($required as $field => $type) {
            if (!array_key_exists($field, $upload)) {
                throw new InvalidArgumentException(
                    "voice_notes: upload struct missing required field `{$field}`"
                );
            }
            $value = $upload[$field];
            $ok = match ($type) {
                'string' => is_string($value) && $value !== '',
                'int' => is_int($value) && $value > 0,
                default => false,
            };
            if (!$ok) {
                throw new InvalidArgumentException(
                    "voice_notes: upload struct field `{$field}` has an invalid value"
                );
            }
        }
        // Sha256 hex is exactly 64 chars; reject anything else loudly so
        // a future bug in the upload service (e.g., hash_file failure
        // returning a short value) doesn't quietly persist a truncated
        // hash that would later confuse integrity checks.
        if (strlen((string) $upload['audio_sha256_hash']) !== 64) {
            throw new InvalidArgumentException(
                'voice_notes: audio_sha256_hash must be a 64-character hex digest'
            );
        }
    }
}
