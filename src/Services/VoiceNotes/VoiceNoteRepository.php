<?php

namespace App\Services\VoiceNotes;

use App\Database\Connection;
use App\Models\VoiceNote;
use PDO;
use RuntimeException;

/**
 * Phase 10.7 — persistence for voice_notes.
 *
 * Mirrors the COLUMNS-const + private-hydrate pattern used by the other
 * Phase-10 repos. A few non-obvious operations:
 *
 *   listForWorkorder()
 *     "Pinned first, then newest first" — matches the WO timeline render
 *     order. Pinned notes are operational (e.g., "this customer is hostile,
 *     two adults present") so the dispatcher must see them on the very
 *     first scroll regardless of how stale they are.
 *
 *   listPendingTranscriptions()
 *     The cron worker scan path. Filters to status='pending' and limits
 *     to a small batch so a backlog of audio doesn't peg the worker on
 *     a single pass. Relies on idx_vn_status to stay cheap as the table
 *     grows.
 *
 *   markTranscribing() / markCompleted() / markFailed()
 *     Bespoke setters for the lifecycle moves. Each stamps the relevant
 *     timestamp column in addition to flipping transcription_status, so
 *     the row is self-describing without the service having to remember
 *     to populate `transcription_started_at` separately.
 */
class VoiceNoteRepository
{
    private const COLUMNS = 'id, workorder_id, ticket_id, vehicle_id, author_user_id,
        audio_path, audio_format, audio_mime, audio_size_bytes, audio_sha256_hash,
        duration_seconds,
        transcript, transcript_language, transcription_provider, transcription_status,
        transcription_started_at, transcription_completed_at, transcription_failure_reason,
        confidence, visibility, pinned, notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?VoiceNote
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM voice_notes WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new VoiceNote($row) : null;
    }

    /**
     * Pinned notes float to the top, then newest-first by id desc. Matches
     * the WO timeline render order.
     *
     * @return array<int, VoiceNote>
     */
    public function listForWorkorder(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM voice_notes
             WHERE workorder_id = :w
             ORDER BY pinned DESC, id DESC'
        );
        $stmt->execute(['w' => $workorderId]);
        return self::hydrateMany($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<int, VoiceNote>
     */
    public function listForTicket(int $ticketId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM voice_notes
             WHERE ticket_id = :t
             ORDER BY pinned DESC, id DESC'
        );
        $stmt->execute(['t' => $ticketId]);
        return self::hydrateMany($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<int, VoiceNote>
     */
    public function listForAuthor(int $authorUserId, int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . " FROM voice_notes
             WHERE author_user_id = :a
             ORDER BY id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute(['a' => $authorUserId]);
        return self::hydrateMany($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Global cross-shop feed for the React "All" tab on /cp/voice-notes.
     * Newest-first by id desc, capped to keep midnight backlogs from
     * paging the whole table into a single response.
     *
     * UIG-10 — gated upstream on `voice_notes.view_global` (dispatch /
     * manager / admin). Per-WO and per-author feeds keep their own
     * narrower views; this is the firehose.
     *
     * @return array<int, VoiceNote>
     */
    public function listAll(int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . " FROM voice_notes
             ORDER BY id DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute();
        return self::hydrateMany($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Cron worker scan path. Oldest-first so a backlog drains in arrival
     * order rather than starving early entries.
     *
     * @return array<int, VoiceNote>
     */
    public function listPendingTranscriptions(int $limit = 25): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . " FROM voice_notes
             WHERE transcription_status = 'pending'
             ORDER BY id ASC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return self::hydrateMany($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): VoiceNote
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO voice_notes
             (workorder_id, ticket_id, vehicle_id, author_user_id,
              audio_path, audio_format, audio_mime, audio_size_bytes, audio_sha256_hash,
              duration_seconds,
              transcription_provider, transcription_status, transcript_language,
              visibility, pinned, notes)
             VALUES
             (:workorder_id, :ticket_id, :vehicle_id, :author_user_id,
              :audio_path, :audio_format, :audio_mime, :audio_size_bytes, :audio_sha256_hash,
              :duration_seconds,
              :transcription_provider, :transcription_status, :transcript_language,
              :visibility, :pinned, :notes)'
        );
        $stmt->execute([
            'workorder_id' => self::nullableInt($data['workorder_id'] ?? null),
            'ticket_id' => self::nullableInt($data['ticket_id'] ?? null),
            'vehicle_id' => self::nullableInt($data['vehicle_id'] ?? null),
            'author_user_id' => self::nullableInt($data['author_user_id'] ?? null),
            'audio_path' => (string) ($data['audio_path'] ?? ''),
            'audio_format' => (string) ($data['audio_format'] ?? 'mp3'),
            'audio_mime' => self::nullableString($data['audio_mime'] ?? null),
            'audio_size_bytes' => self::nullableInt($data['audio_size_bytes'] ?? null),
            'audio_sha256_hash' => self::nullableString($data['audio_sha256_hash'] ?? null),
            'duration_seconds' => self::nullableFloat($data['duration_seconds'] ?? null),
            'transcription_provider' => (string) ($data['transcription_provider'] ?? 'heuristic_v1'),
            'transcription_status' => (string) ($data['transcription_status'] ?? VoiceNote::STATUS_PENDING),
            'transcript_language' => self::nullableString($data['transcript_language'] ?? null),
            'visibility' => (string) ($data['visibility'] ?? VoiceNote::VISIBILITY_INTERNAL),
            'pinned' => !empty($data['pinned']) ? 1 : 0,
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('voice_notes insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): VoiceNote
    {
        $writable = [
            'workorder_id', 'ticket_id', 'vehicle_id',
            'audio_format', 'audio_mime', 'audio_size_bytes', 'audio_sha256_hash',
            'duration_seconds',
            'transcript', 'transcript_language', 'transcription_provider',
            'transcription_status', 'transcription_started_at',
            'transcription_completed_at', 'transcription_failure_reason',
            'confidence', 'visibility', 'pinned', 'notes',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $fields[] = "{$col} = :{$col}";
            $params[$col] = self::castColumn($col, $data[$col]);
        }
        if ($fields !== []) {
            $sql = 'UPDATE voice_notes SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("voice_notes {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM voice_notes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────────────────── helpers ────

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, VoiceNote>
     */
    private static function hydrateMany(array $rows): array
    {
        return array_map(static fn(array $r) => new VoiceNote($r), $rows);
    }

    private static function castColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'workorder_id', 'ticket_id', 'vehicle_id', 'audio_size_bytes'
                => self::nullableInt($value),
            'duration_seconds', 'confidence' => self::nullableFloat($value),
            'pinned' => !empty($value) ? 1 : 0,
            'audio_mime', 'audio_sha256_hash',
            'transcript', 'transcript_language', 'transcription_started_at',
            'transcription_completed_at', 'transcription_failure_reason',
            'notes' => self::nullableString($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;
        return $s === '' ? null : $s;
    }
}
