<?php

namespace App\Services\VoiceNotes;

use App\Models\User;
use App\Models\VoiceNote;

/**
 * Phase 10.7 — thin HTTP facade for the voice-note workflow.
 *
 * Each handler returns the {"data": ...} envelope used by the rest of the
 * API. Controllers do no business logic — gating, validation, lifecycle
 * moves, and transcription orchestration all live in VoiceNoteService.
 */
class VoiceNoteController
{
    public function __construct(
        private readonly VoiceNoteService $service,
        private readonly VoiceNoteUploadService $uploads,
    ) {
    }

    // ─────────────────────────────────────────────── reads ────

    /**
     * @return array<string, mixed>
     */
    public function listForWorkorder(User $actor, int $workorderId): array
    {
        return [
            'data' => array_map(
                static fn(VoiceNote $n) => $n->toArray(),
                $this->service->listForWorkorder($actor, $workorderId)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForTicket(User $actor, int $ticketId): array
    {
        return [
            'data' => array_map(
                static fn(VoiceNote $n) => $n->toArray(),
                $this->service->listForTicket($actor, $ticketId)
            ),
        ];
    }

    /**
     * "My voice notes" — actor's own author_user_id.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listMine(User $actor, array $query): array
    {
        $limit = (int) ($query['limit'] ?? 100);
        $offset = (int) ($query['offset'] ?? 0);
        $notes = $this->service->listForAuthor($actor, $actor->id ?? 0, $limit, $offset);
        return [
            'data' => array_map(static fn(VoiceNote $n) => $n->toArray(), $notes),
        ];
    }

    /**
     * UIG-10 — cross-shop "All" feed for the React voice-notes page.
     * Gated upstream on `voice_notes.view_global`; technicians fall
     * through to the per-WO timeline / their own "Mine" tab.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listAll(User $actor, array $query): array
    {
        $limit = (int) ($query['limit'] ?? 100);
        $offset = (int) ($query['offset'] ?? 0);
        $notes = $this->service->listAll($actor, $limit, $offset);
        return [
            'data' => array_map(static fn(VoiceNote $n) => $n->toArray(), $notes),
        ];
    }

    /**
     * Cron-worker scan endpoint. Operators can also hit it manually to
     * inspect the backlog.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listPendingTranscriptions(User $actor, array $query): array
    {
        $limit = (int) ($query['limit'] ?? 25);
        $notes = $this->service->listPendingTranscriptions($actor, $limit);
        return [
            'data' => array_map(static fn(VoiceNote $n) => $n->toArray(), $notes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getNote(User $actor, int $id): array
    {
        $bundle = $this->service->getNoteWithTags($actor, $id);
        // R-01 — synthesise the auth-gated stream URL. The React detail
        // modal binds an <audio src> to this; nothing constructs paths
        // on the client side. Keeping URL construction in the controller
        // (not the model) keeps the model storage-layout agnostic.
        $payload = $bundle['note']->toArray() + [
            'tags' => $bundle['tags'],
            'audio_url' => "/api/voice-notes/{$bundle['note']->id}/audio",
        ];
        return ['data' => $payload];
    }

    /**
     * @return array<string, mixed>
     */
    public function listAllTags(User $actor): array
    {
        return ['data' => $this->service->listAllTags($actor)];
    }

    // ─────────────────────────────────────────────── mutations ────

    /**
     * R-01 — `audio` is a required $_FILES-style upload from the
     * multipart request. The remaining $payload carries the optional
     * metadata (workorder_id, ticket_id, visibility, etc.). The
     * upload pipeline runs first so that if the file is rejected we
     * never touch the database.
     *
     * @param array<string, mixed> $file    $_FILES['audio']
     * @param array<string, mixed> $payload Body (without storage fields)
     * @return array<string, mixed>
     */
    public function recordNote(User $actor, array $file, array $payload): array
    {
        $upload = $this->uploads->ingest($actor, $file);
        return ['data' => $this->service->record($actor, $upload, $payload)->toArray()];
    }

    /**
     * R-01 — stream the stored audio back to authorised callers.
     *
     * Returns a struct describing the stream (absolute_path, mime,
     * size); the route layer wraps that in the HTTP response. We
     * return the path rather than echoing here so the controller stays
     * pure (no header()/echo side effects), which keeps it unit-
     * testable.
     *
     * @return array{absolute_path: string, mime: string, size_bytes: int, filename: string}
     */
    public function streamAudio(User $actor, int $id): array
    {
        $note = $this->service->getNote($actor, $id);
        $absolute = $this->uploads->resolveStoredFile($note->audio_path);
        if ($absolute === null || !is_file($absolute) || !is_readable($absolute)) {
            throw new \InvalidArgumentException("Voice note {$id} audio file is missing");
        }
        $size = filesize($absolute);
        return [
            'absolute_path' => $absolute,
            'mime' => $note->audio_mime ?: 'application/octet-stream',
            'size_bytes' => $size === false ? 0 : $size,
            'filename' => "voice-note-{$id}.{$note->audio_format}",
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateNote(User $actor, int $id, array $payload): array
    {
        return ['data' => $this->service->updateNote($actor, $id, $payload)->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteNote(User $actor, int $id): array
    {
        $this->service->deleteNote($actor, $id);
        return ['data' => ['deleted' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    public function transcribeNote(User $actor, int $id): array
    {
        return ['data' => $this->service->transcribe($actor, $id)->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function pinNote(User $actor, int $id): array
    {
        return ['data' => $this->service->pin($actor, $id)->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function unpinNote(User $actor, int $id): array
    {
        return ['data' => $this->service->unpin($actor, $id)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function setTags(User $actor, int $id, array $payload): array
    {
        $tags = (array) ($payload['tags'] ?? []);
        $bundle = $this->service->setTags($actor, $id, $tags);
        return [
            'data' => [
                'note' => $bundle['note']->toArray(),
                'tags' => $bundle['tags'],
                'added' => $bundle['added'],
                'removed' => $bundle['removed'],
            ],
        ];
    }
}
