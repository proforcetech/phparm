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
    public function __construct(private readonly VoiceNoteService $service)
    {
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
        return [
            'data' => $bundle['note']->toArray() + ['tags' => $bundle['tags']],
        ];
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recordNote(User $actor, array $payload): array
    {
        return ['data' => $this->service->record($actor, $payload)->toArray()];
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
